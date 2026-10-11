<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use Throwable;

/**
 * Exam Permit Validation Workflow (3-tier)
 * Stages: Accounting → Registrar → Teacher (Fully Approved)
 * Financial prerequisite: ₱0 fully paid OR installment paid (PayRex/receipt)
 */
final class ExamPermitController extends Controller
{
    private const PERIODS = ['Prelim','Midterm','Prefinal','Finals'];
    private const STATUS_PENDING_ACCOUNTING = 'pending_accounting';
    private const STATUS_APPROVED_ACCOUNTING = 'approved_accounting';
    private const STATUS_APPROVED_REGISTRAR = 'approved_registrar';
    private const STATUS_FULLY_APPROVED = 'fully_approved';

    /**
     * Minimum joined subjects (current term) before a Prelim permit can be
     * requested. Default 8; admin-overridable via system_settings
     * (permit_min_subjects). Midterm+ need no separate check — the sequential
     * chain already requires a fully-approved Prelim.
     */
    public static function minSubjects(): int
    {
        try {
            $v = Database::value("SELECT setting_value FROM system_settings WHERE setting_key = 'permit_min_subjects' LIMIT 1");
            $n = (int) trim((string) ($v ?? ''));
            if ($n >= 1 && $n <= 30) return $n;
        } catch (Throwable $e) {
        }
        return 8;
    }

    /** Subjects the student joined for the given academic year. */
    public static function termSubjectCount(int $studentId, int $ayId): int
    {
        if ($studentId <= 0 || $ayId <= 0) return 0;
        try {
            return (int) Database::value(
                "SELECT COUNT(*) FROM classroom_members cm
                  JOIN subject_classrooms sc ON sc.id = cm.classroom_id
                 WHERE cm.student_id = ? AND sc.academic_year_id = ?",
                [$studentId, $ayId]
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /* ====================== Financial prerequisite check ====================== */
    private function financialCheck(int $studentId, string $examPeriod): array
    {
        // Outstanding balance
        try {
            $balance = (float) Database::value(
                "SELECT COALESCE((SELECT SUM(amount) FROM student_charges WHERE student_id = ?),0)
                        - COALESCE((SELECT SUM(pa.amount_applied)
                                      FROM payment_allocations pa
                                      JOIN payments p ON p.id = pa.payment_id
                                     WHERE p.status = 'paid'
                                       AND pa.charge_id IN (SELECT id FROM student_charges WHERE student_id = ?)),0)",
                [$studentId, $studentId]
            );
        } catch (Throwable $e) {
            $balance = 0.0;
        }
        $balance = round(max(0, $balance), 2);

        // Find latest validated payment (PayRex intent or receipt)
        $paymentRef = null;
        $receiptNo = null;
        try {
            $pay = Database::one(
                "SELECT p.transaction_ref, p.payrex_payment_intent_id, p.payrex_resource_ref, p.paid_at, r.receipt_no
                   FROM payments p
                   LEFT JOIN receipts r ON r.payment_id = p.id
                  WHERE p.student_id = ? AND p.status = 'paid'
                  ORDER BY p.paid_at DESC LIMIT 1",
                [$studentId]
            );
            if ($pay) {
                $paymentRef = $pay['payrex_payment_intent_id'] ?? $pay['payrex_resource_ref'] ?? $pay['transaction_ref'] ?? null;
                if (empty($paymentRef)) $paymentRef = $pay['transaction_ref'] ?? null;
                $receiptNo = $pay['receipt_no'] ?? null;
                if (empty($paymentRef) && $receiptNo) $paymentRef = $receiptNo;
            }
        } catch (Throwable $e) { $paymentRef = null; }

        // Valid if fully paid (balance 0) OR installment paid (has at least one paid receipt/PayRex)
        $isPaid = $paymentRef !== null || $receiptNo !== null;
        if ($balance <= 0.01) {
            return ['valid'=>true, 'financial_status'=>'fully_paid', 'payment_ref'=>$paymentRef ?: ($receiptNo ?: 'FULLY-PAID-'.$studentId), 'receipt_no'=>$receiptNo, 'balance'=>$balance];
        }
        if ($isPaid) {
            // Installment paid: has recent paid payment (simplified per-spec monthly/installment)
            return ['valid'=>true, 'financial_status'=>'installment_paid', 'payment_ref'=>$paymentRef ?: $receiptNo, 'receipt_no'=>$receiptNo, 'balance'=>$balance];
        }
        // Unpaid: no valid payment found
        return ['valid'=>false, 'financial_status'=>'unpaid', 'payment_ref'=>null, 'receipt_no'=>null, 'balance'=>$balance];
    }

    /**
     * Acting student: self for students; owned linked child (?child_id=
     * ownership-checked, else first child) for parents.
     * @return array{row:array|null,via_parent:bool,child_id:int,child_name:string,tampered:bool}
     */
    private function actingStudent(): array
    {
        $out = ['row' => null, 'via_parent' => false, 'child_id' => 0, 'child_name' => '', 'tampered' => false];
        if (Auth::is('parent')) {
            if (!class_exists(\App\Core\GuardianLinks::class)) return $out;
            $kid = (int) ($_REQUEST['child_id'] ?? 0);
            if ($kid > 0 && !\App\Core\GuardianLinks::ownedChild((int) Auth::id(), $kid)) {
                $out['tampered'] = true;
                return $out;
            }
            $row = \App\Core\GuardianLinks::ownedChild((int) Auth::id(), $kid);
            if ($row) {
                $out['row'] = $row;
                $out['via_parent'] = true;
                $out['child_id'] = (int) $row['id'];
                $out['child_name'] = \App\Core\GuardianLinks::displayName($row);
            }
            return $out;
        }
        $row = Database::one("SELECT * FROM students WHERE user_id = ? LIMIT 1", [(int) Auth::id()]);
        if ($row) $out['row'] = $row;
        return $out;
    }

    /* ====================== Student: request permit ====================== */
    public function request(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('student', 'parent');

        $examPeriod = trim((string)($_POST['exam_period'] ?? ''));
        if (!in_array($examPeriod, self::PERIODS, true)) {
            set_flash('error', 'Invalid exam period.');
            redirect('/dashboard');
        }

        $userId = (int) Auth::id();
        $acting = $this->actingStudent();
        if ($acting['tampered']) {
            set_flash('error', 'That child is not linked to your guardian account.');
            redirect('/dashboard');
        }
        $student = $acting['row'];
        $viaParent = $acting['via_parent'];
        if ($student === null || ($student['status'] ?? '') !== 'enrolled') {
            set_flash('warning', $viaParent ? 'Your child must be enrolled for the active term to request an exam permit.' : 'You must be enrolled for the active term to request an exam permit.');
            redirect('/dashboard');
        }
        $studentId = (int)$student['id'];
        $currentAy = Database::one("SELECT id FROM academic_years WHERE is_current = 1 LIMIT 1");
        $ayId = $currentAy ? (int)$currentAy['id'] : 0;
        if (!$ayId) {
            set_flash('error', 'No active academic year. Contact registrar.');
            redirect('/dashboard');
        }

        // Subject gate (Prelim only — entry point of the chain): a freshly
        // enrolled student must join their term subjects first.
        if ($examPeriod === 'Prelim') {
            $need = self::minSubjects();
            $have = self::termSubjectCount($studentId, $ayId);
            if ($have < $need) {
                set_flash('warning', "Join your subjects first — you have joined {$have} of {$need} required subjects for this term. Browse Class Offerings or paste an invite code, then request your Prelim permit.");
                redirect('/dashboard');
            }
        }

        // Duplicate guard (rejected permits use Request Again to preserve history)
        $exists = Database::one("SELECT id, status FROM exam_permits WHERE student_id = ? AND academic_year_id = ? AND exam_period = ? LIMIT 1", [$studentId, $ayId, $examPeriod]);
        if ($exists) {
            if (($exists['status'] ?? '') === 'rejected') {
                set_flash('warning', "Your {$examPeriod} permit was rejected — open it and use Request Again to correct and resubmit (history is preserved).");
                redirect('/student/permits/' . (int) $exists['id']);
            }
            set_flash('warning', "You already have a {$examPeriod} permit ({$exists['status']}).");
            redirect('/dashboard');
        }

        // Sequential prerequisite: Prelim → Midterm → Prefinal → Finals
        $order = array_search($examPeriod, self::PERIODS, true);
        if ($order !== false && $order > 0) {
            $prevPeriod = self::PERIODS[$order - 1];
            $prevPermit = Database::one("SELECT status FROM exam_permits WHERE student_id = ? AND academic_year_id = ? AND exam_period = ? LIMIT 1", [$studentId, $ayId, $prevPeriod]);
            if (!$prevPermit || $prevPermit['status'] !== self::STATUS_FULLY_APPROVED) {
                set_flash('warning', "You must complete {$prevPeriod} (Fully Approved) before requesting {$examPeriod}. Sequential order: Prelim → Midterm → Prefinal → Finals.");
                redirect('/dashboard');
            }
        }

        // Financial prerequisite
        $fin = $this->financialCheck($studentId, $examPeriod);
        if (!$fin['valid']) {
            set_flash('error', "Payment not validated for {$examPeriod}. Please settle your tuition installment via PayRex or present official receipt. Outstanding balance: " . money($fin['balance']));
            redirect('/dashboard');
        }

        // Generate permit_no and qr_token
        $permitNo = 'EP-' . date('Y') . '-' . strtoupper(substr($examPeriod,0,4)) . '-' . strtoupper(bin2hex(random_bytes(3)));
        $qrToken = bin2hex(random_bytes(32));

        try {
            $permitId = Database::insert('exam_permits', [
                'permit_no'        => $permitNo,
                'student_id'       => $studentId,
                'academic_year_id' => $ayId,
                'exam_period'      => $examPeriod,
                'status'           => self::STATUS_PENDING_ACCOUNTING,
                'financial_status' => $fin['financial_status'],
                'payment_validated'=> 1,
                'payment_ref'      => $fin['payment_ref'] ?? null,
                'receipt_no'       => $fin['receipt_no'] ?? null,
                'balance_at_request'=> $fin['balance'],
                'qr_token'         => $qrToken,
            ]);

            // Audit
            Database::insert('audit_logs', [
                'actor_user_id' => $userId,
                'action'        => 'permit.requested',
                'entity_type'   => 'exam_permits',
                'entity_id'     => $permitId,
                'new_values_json' => $viaParent ? json_encode(['student_id' => $studentId, 'via_parent' => 1]) : null,
                'ip_address'    => client_ip(),
            ]);
            // Notify the Stage-1 owner (shows in their bell on every page)
            NotificationController::pushToAccounting(
                "New {$examPeriod} permit request: {$permitNo}",
                'Student #' . $studentId . ' requested a ' . $examPeriod . ' exam permit.' . ($viaParent ? ' (Filed by parent.)' : '') . ' Review in Permits queue.',
                'payment', '/accounting/permits'
            );

            set_flash('success', "{$examPeriod} exam permit {$permitNo} requested. Payment Validated ({$fin['payment_ref']}). Awaiting Accounting approval (Stage 1/3).");
        } catch (Throwable $e) {
            storage_log('permit request failed: ' . $e->getMessage());
            set_flash('error', 'Could not request permit: ' . $e->getMessage());
        }
        redirect('/dashboard');
    }

    /* ====================== Student: list & show ====================== */
    public function studentIndex(array $params = []): void
    {
        Auth::requireRole('student','parent');
        $acting = $this->actingStudent();
        if ($acting['tampered']) {
            set_flash('error', 'That child is not linked to your guardian account.');
            redirect('/dashboard');
        }
        $sid = $acting['row'] ? (int) $acting['row']['id'] : 0;
        $rows = $sid ? Database::all(
            "SELECT ep.*, ay.school_year FROM exam_permits ep JOIN academic_years ay ON ay.id=ep.academic_year_id WHERE ep.student_id=? ORDER BY ep.requested_at DESC",
            [$sid]
        ) : [];
        $linkedChildren = [];
        if (Auth::is('parent') && class_exists(\App\Core\GuardianLinks::class)) {
            $linkedChildren = \App\Core\GuardianLinks::linkedChildren((int) Auth::id());
        }
        $this->view('student/permits_index', [
            'title' => 'My Exam Permits', 'permits' => $rows,
            'isParent' => Auth::is('parent'), 'linkedChildren' => $linkedChildren,
            'actingChild' => $acting['via_parent'] ? ['id' => $acting['child_id'], 'name' => $acting['child_name']] : null,
        ]);
    }

    public function studentShow(array $params = []): void
    {
        Auth::requireRole('student','parent');
        $id = (int)($params['id'] ?? 0);
        $acting = $this->actingStudent();
        if ($acting['tampered']) {
            set_flash('error', 'That child is not linked to your guardian account.');
            redirect('/dashboard');
        }
        $sid = $acting['row'] ? (int) $acting['row']['id'] : 0;
        $permit = Database::one(
            "SELECT ep.*, ay.school_year, s.student_no, u.first_name, u.last_name, c.name AS campus_name
               FROM exam_permits ep
               JOIN academic_years ay ON ay.id=ep.academic_year_id
               JOIN students s ON s.id=ep.student_id
               JOIN users u ON u.id=s.user_id
               LEFT JOIN campuses c ON c.id=s.section_id
              WHERE ep.id=? AND ep.student_id=? LIMIT 1",
            [$id, $sid]
        );
        if ($permit === null) { http_response_code(404); exit('Permit not found'); }
        $approvals = Database::all("SELECT pa.*, u.first_name, u.last_name FROM permit_approvals pa LEFT JOIN users u ON u.id=pa.approver_user_id WHERE pa.permit_id=? ORDER BY pa.acted_at ASC", [$id]);
        $this->view('student/permits_show', ['title'=>'Permit ' . $permit['permit_no'], 'permit'=>$permit, 'approvals'=>$approvals, 'isParent'=>Auth::is('parent'), 'childId'=>$acting['via_parent'] ? (int)$acting['child_id'] : 0]);
    }

    public function printCard(array $params = []): void
    {
        Auth::requireRole('student','parent','teacher','admin');
        $id = (int)($params['id'] ?? 0);
        $permit = Database::one(
            "SELECT ep.*, ay.school_year, s.student_no, u.first_name, u.last_name, u.email, gl.name AS level_name, sec.name AS section_name
               FROM exam_permits ep
               JOIN academic_years ay ON ay.id=ep.academic_year_id
               JOIN students s ON s.id=ep.student_id
               JOIN users u ON u.id=s.user_id
               LEFT JOIN grade_levels gl ON gl.id=s.grade_level_id
               LEFT JOIN sections sec ON sec.id=s.section_id
              WHERE ep.id=? LIMIT 1",
            [$id]
        );
        if ($permit === null) { http_response_code(404); exit('Permit not found'); }
        // Authorization: student owns it, parent owns the linked child, or teacher/admin
        $role = Auth::role();
        if ($role === 'student') {
            $stu = Database::one("SELECT id FROM students WHERE user_id=? LIMIT 1", [(int)Auth::id()]);
            if (!$stu || (int)$stu['id'] !== (int)$permit['student_id']) { http_response_code(403); exit('Forbidden'); }
        } elseif ($role === 'parent') {
            $ok = false;
            if (class_exists(\App\Core\GuardianLinks::class)) {
                $g = Database::one("SELECT id FROM guardians WHERE user_id = ? LIMIT 1", [(int) Auth::id()]);
                if ($g) {
                    $ok = (bool) Database::one(
                        "SELECT 1 FROM guardian_students WHERE guardian_id = ? AND student_id = ? LIMIT 1",
                        [(int) $g['id'], (int) $permit['student_id']]
                    );
                }
            }
            if (!$ok) { http_response_code(403); exit('Forbidden'); }
        }
        // Only fully approved shows green stamp, but allow view at any stage with watermark
        $approvals = Database::all("SELECT pa.*, u.first_name, u.last_name FROM permit_approvals pa LEFT JOIN users u ON u.id=pa.approver_user_id WHERE pa.permit_id=? ORDER BY pa.acted_at ASC", [$id]);
        $this->view('permits/print', ['title'=>'Permit ' . $permit['permit_no'], 'permit'=>$permit, 'approvals'=>$approvals], null); // no layout for print
    }

    /* ====================== Admin: queue & approvals ====================== */
    public function adminQueue(array $params = []): void
    {
        Auth::requireRole('admin');
        $status = $_GET['status'] ?? 'pending_accounting';
        $allowed = ['pending_accounting','approved_accounting','approved_registrar','fully_approved','rejected','all'];
        if (!in_array($status, $allowed, true)) $status = 'pending_accounting';
        $where = $status==='all' ? '' : 'WHERE ep.status=?';
        $args = $status==='all' ? [] : [$status];
        $rows = Database::all(
            "SELECT ep.*, s.student_no, u.first_name, u.last_name, u.email, ay.school_year, gl.name AS level_name, sec.name AS section_name
               FROM exam_permits ep
               JOIN students s ON s.id=ep.student_id
               JOIN users u ON u.id=s.user_id
               JOIN academic_years ay ON ay.id=ep.academic_year_id
               LEFT JOIN grade_levels gl ON gl.id=s.grade_level_id
               LEFT JOIN sections sec ON sec.id=s.section_id
               $where
              ORDER BY ep.requested_at DESC LIMIT 100",
            $args
        );
        $counts = [];
        foreach (['pending_accounting','approved_accounting','approved_registrar','fully_approved','rejected'] as $st) {
            $counts[$st] = (int) Database::value("SELECT COUNT(*) FROM exam_permits WHERE status=?", [$st]);
        }
        $counts['all'] = (int) Database::value("SELECT COUNT(*) FROM exam_permits");
        $this->view('admin/permits_index', ['title'=>'Exam Permit Approvals','rows'=>$rows,'status'=>$status,'counts'=>$counts]);
    }

    public function approveAccounting(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin', 'accounting');
        $id = (int)($params['id'] ?? 0);
        $permit = Database::one("SELECT * FROM exam_permits WHERE id=? LIMIT 1", [$id]);
        if (!$permit || $permit['status'] !== self::STATUS_PENDING_ACCOUNTING) {
            set_flash('error','Permit not in pending_accounting state.');
            redirect('/admin/permits');
        }
        Database::run("UPDATE exam_permits SET status=? WHERE id=?", [self::STATUS_APPROVED_ACCOUNTING, $id]);
        Database::insert('permit_approvals', ['permit_id'=>$id,'stage'=>'accounting','status'=>'approved','approver_user_id'=>Auth::id(),'remarks'=>trim((string)($_POST['remarks']??'')) ?: null]);
        Database::insert('audit_logs', ['actor_user_id'=>Auth::id(),'action'=>'permit.approved_accounting','entity_type'=>'exam_permits','entity_id'=>$id,'ip_address'=>client_ip()]);
        try {
            $stuUser = Database::one("SELECT s.user_id FROM students s WHERE s.id=? LIMIT 1", [(int)$permit['student_id']]);
            if ($stuUser && !empty($stuUser['user_id'])) {
                NotificationController::push((int)$stuUser['user_id'], 'Accounting approved: ' . $permit['permit_no'], $permit['exam_period'] . ' permit passed Accounting (Stage 1/3) — forwarded to Registrar.', 'payment', '/student/permits');
            }
        } catch (Throwable $e) {}
        NotificationController::pushToRegistrars('Permit needs review: ' . $permit['permit_no'], $permit['exam_period'] . ' permit passed Accounting — review in Permits queue.', 'payment', '/registrar/permits');
        set_flash('success','Permit '.$permit['permit_no'].' approved by Accounting (Stage 1/3). Forwarded to Registrar.');
        redirect('/admin/permits');
    }

    public function approveRegistrar(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin', 'registrar');
        $id = (int)($params['id'] ?? 0);
        $permit = Database::one("SELECT * FROM exam_permits WHERE id=? LIMIT 1", [$id]);
        if (!$permit || $permit['status'] !== self::STATUS_APPROVED_ACCOUNTING) {
            set_flash('error','Permit must be approved by Accounting first.');
            redirect('/admin/permits');
        }
        Database::run("UPDATE exam_permits SET status=? WHERE id=?", [self::STATUS_APPROVED_REGISTRAR, $id]);
        Database::insert('permit_approvals', ['permit_id'=>$id,'stage'=>'registrar','status'=>'approved','approver_user_id'=>Auth::id(),'remarks'=>trim((string)($_POST['remarks']??'')) ?: null]);
        Database::insert('audit_logs', ['actor_user_id'=>Auth::id(),'action'=>'permit.approved_registrar','entity_type'=>'exam_permits','entity_id'=>$id,'ip_address'=>client_ip()]);
        try {
            $stuUser = Database::one("SELECT s.user_id FROM students s WHERE s.id=? LIMIT 1", [(int)$permit['student_id']]);
            if ($stuUser && !empty($stuUser['user_id'])) {
                NotificationController::push((int)$stuUser['user_id'], 'Registrar approved: ' . $permit['permit_no'], $permit['exam_period'] . ' permit passed Registrar (Stage 2/3) — awaiting teacher verification.', 'payment', '/student/permits');
            }
        } catch (Throwable $e) {}
        NotificationController::pushToTeachers('Permit ready to verify: ' . $permit['permit_no'], $permit['exam_period'] . ' permit approved by Registrar — verify in Permit Queue.', 'payment', '/teacher/permits');
        set_flash('success','Permit '.$permit['permit_no'].' approved by Registrar (Stage 2/3). Awaiting Teacher verification.');
        redirect('/admin/permits');
    }

    public function reject(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');
        $id = (int)($params['id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? $_POST['remarks'] ?? ''));
        if (mb_strlen($reason) < 5) {
            set_flash('error','A rejection reason (min 5 characters) is required.');
            redirect('/admin/permits');
        }
        $permit = Database::one("SELECT * FROM exam_permits WHERE id=? LIMIT 1", [$id]);
        if (!$permit || !in_array($permit['status'], [self::STATUS_PENDING_ACCOUNTING, self::STATUS_APPROVED_ACCOUNTING, self::STATUS_APPROVED_REGISTRAR], true)) {
            set_flash('error','Permit cannot be rejected in its current state.');
            redirect('/admin/permits');
        }
        $stage = $permit['status'] === self::STATUS_APPROVED_ACCOUNTING ? 'registrar' : ($permit['status'] === self::STATUS_APPROVED_REGISTRAR ? 'teacher' : 'accounting');
        Database::run("UPDATE exam_permits SET status='rejected', rejected_reason=? WHERE id=?", [mb_substr($reason, 0, 255), $id]);
        Database::insert('permit_approvals', ['permit_id'=>$id,'stage'=>$stage,'status'=>'rejected','approver_user_id'=>Auth::id(),'remarks'=>mb_substr($reason, 0, 2000)]);
        Database::insert('audit_logs', ['actor_user_id'=>Auth::id(),'action'=>'permit.rejected_'.$stage,'entity_type'=>'exam_permits','entity_id'=>$id,'ip_address'=>client_ip()]);
        try {
            $stuUser = Database::one("SELECT s.user_id FROM students s WHERE s.id=? LIMIT 1", [(int)$permit['student_id']]);
            if ($stuUser && !empty($stuUser['user_id'])) {
                NotificationController::push((int)$stuUser['user_id'], 'Permit rejected: ' . $permit['permit_no'], $permit['exam_period'] . ' — ' . mb_substr($reason, 0, 200), 'danger', '/student/permits/' . $id);
            }
        } catch (Throwable $e) {}
        set_flash('success','Permit '.$permit['permit_no'].' rejected with reason logged.');
        redirect('/admin/permits');
    }

    /* ====================== Student: request rejected permit again ====================== */
    public function requestAgain(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('student', 'parent');
        $id = (int)($params['id'] ?? 0);
        $userId = (int) Auth::id();
        $acting = $this->actingStudent();
        if ($acting['tampered']) {
            set_flash('error', 'That child is not linked to your guardian account.');
            redirect('/dashboard');
        }
        $student = $acting['row'];
        if ($student === null) {
            set_flash('error', 'Student record not found.');
            redirect('/student/permits');
        }
        $permit = Database::one("SELECT * FROM exam_permits WHERE id = ? AND student_id = ? LIMIT 1", [$id, (int) $student['id']]);
        if ($permit === null) {
            set_flash('error', 'Permit not found.');
            redirect('/student/permits');
        }
        if ($permit['status'] !== 'rejected') {
            set_flash('info', 'Only rejected permits can be requested again (current: ' . str_replace('_', ' ', $permit['status']) . ').');
            redirect('/student/permits/' . $id);
        }
        // Sequential prerequisite still applies (previous period must be fully approved)
        $order = array_search($permit['exam_period'], self::PERIODS, true);
        if ($order !== false && $order > 0) {
            $prevPeriod = self::PERIODS[$order - 1];
            $prevPermit = Database::one(
                "SELECT status FROM exam_permits WHERE student_id = ? AND academic_year_id = ? AND exam_period = ? LIMIT 1",
                [(int) $student['id'], (int) $permit['academic_year_id'], $prevPeriod]
            );
            if (!$prevPermit || $prevPermit['status'] !== self::STATUS_FULLY_APPROVED) {
                set_flash('warning', "Complete {$prevPeriod} (Fully Approved) before requesting {$permit['exam_period']} again.");
                redirect('/student/permits/' . $id);
            }
        }
        // Re-validate finances at resubmission time
        $fin = $this->financialCheck((int) $student['id'], $permit['exam_period']);
        if (!$fin['valid']) {
            set_flash('error', "Payment not validated for {$permit['exam_period']}. Settle your installment first. Outstanding: " . money($fin['balance']));
            redirect('/student/permits/' . $id);
        }
        try {
            Database::transaction(function () use ($permit, $id, $fin, $userId) {
                Database::run(
                    "UPDATE exam_permits SET status = ?, financial_status = ?, payment_validated = 1, payment_ref = ?, receipt_no = ?, balance_at_request = ?, rejected_reason = NULL, requested_at = NOW(), updated_at = NOW() WHERE id = ?",
                    [self::STATUS_PENDING_ACCOUNTING, $fin['financial_status'], $fin['payment_ref'] ?? null, $fin['receipt_no'] ?? null, $fin['balance'], $id]
                );
                Database::insert('permit_approvals', [
                    'permit_id' => $id, 'stage' => 'accounting', 'status' => 'approved',
                    'approver_user_id' => $userId, 'remarks' => 'Resubmitted by student after rejection (Request Again)',
                ]);
                Database::insert('audit_logs', [
                    'actor_user_id' => $userId, 'action' => 'permit.resubmitted',
                    'entity_type' => 'exam_permits', 'entity_id' => $id, 'ip_address' => client_ip(),
                ]);
            });
            NotificationController::pushToAccounting(
                "Permit resubmitted: {$permit['permit_no']}",
                "Student #{$student['id']} corrected and resubmitted {$permit['exam_period']} — review in Accounting queue.",
                'payment', '/accounting/permits'
            );
            set_flash('success', "{$permit['exam_period']} permit resubmitted — back to Accounting review (Stage 1/3). Previous rejection kept in history below.");
        } catch (Throwable $e) {
            storage_log('permit request-again failed ' . $id . ': ' . $e->getMessage());
            set_flash('error', 'Could not resubmit: ' . $e->getMessage());
        }
        redirect('/student/permits/' . $id);
    }

    /* ====================== Teacher: verify ====================== */
    public function teacherQueue(array $params = []): void
    {
        Auth::requireRole('teacher');
        // School-wide queue: students' section_id is often NULL after enrollment, so strict
        // section filtering hides permits. Show all registrar-approved permits to any teacher.
        $rows = Database::all(
            "SELECT ep.*, s.student_no, u.first_name, u.last_name, ay.school_year, gl.name AS level_name, sec.name AS section_name
               FROM exam_permits ep
               JOIN students s ON s.id=ep.student_id
               JOIN users u ON u.id=s.user_id
               JOIN academic_years ay ON ay.id=ep.academic_year_id
               LEFT JOIN grade_levels gl ON gl.id=s.grade_level_id
               LEFT JOIN sections sec ON sec.id=s.section_id
              WHERE ep.status='approved_registrar'
              ORDER BY ep.requested_at ASC LIMIT 50",
            []
        );
        $this->view('teacher/permits_queue', ['title'=>'Permit Verification Queue','permits'=>$rows]);
    }

    /* ====================== Teacher: permit detail ====================== */

    /**
     * Read-only detail teachers open from the queue's View button:
     * student identity, payment proof, stage timeline, approval history,
     * plus the Verify action when the permit is ready (Stage 2/3).
     */
    public function teacherShow(array $params = []): void
    {
        Auth::requireRole('teacher');
        $id = (int) ($params['id'] ?? 0);
        $permit = Database::one(
            "SELECT ep.*, s.student_no, s.user_id AS student_user_id,
                    u.first_name, u.last_name, u.email,
                    ay.school_year, gl.name AS level_name, gl.code AS level_code,
                    sec.name AS section_name, p.code AS program_code, p.name AS program_name
               FROM exam_permits ep
               JOIN students s ON s.id = ep.student_id
               JOIN users u ON u.id = s.user_id
               JOIN academic_years ay ON ay.id = ep.academic_year_id
               LEFT JOIN grade_levels gl ON gl.id = s.grade_level_id
               LEFT JOIN sections sec ON sec.id = s.section_id
               LEFT JOIN programs p ON p.id = s.program_id
              WHERE ep.id = ? LIMIT 1",
            [$id]
        );
        if ($permit === null) {
            set_flash('error', 'Permit not found.');
            redirect('/teacher/permits');
        }
        $history = [];
        try {
            $history = Database::all(
                "SELECT pa.stage, pa.status, pa.remarks, pa.created_at,
                        CONCAT(au.first_name, ' ', au.last_name) AS approver
                   FROM permit_approvals pa
                   LEFT JOIN users au ON au.id = pa.approver_user_id
                  WHERE pa.permit_id = ? ORDER BY pa.id ASC",
                [$id]
            );
        } catch (Throwable $e) {
        }
        $this->view('teacher/permit_show', ['title' => 'Permit ' . $permit['permit_no'], 'permit' => $permit, 'history' => $history]);
    }

    public function teacherVerify(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('teacher');
        $id = (int)($params['id'] ?? 0);
        $permit = Database::one("SELECT * FROM exam_permits WHERE id=? LIMIT 1", [$id]);
        if (!$permit || $permit['status'] !== self::STATUS_APPROVED_REGISTRAR) {
            set_flash('error','Permit not ready for teacher verification (must be approved by Registrar).');
            redirect('/teacher/permits');
        }
        // Ownership check against the CURRENT data model: the student must have
        // joined (classroom_members) a subject classroom taught by this
        // teacher (subject_classrooms.teacher_id). The old class_offerings
        // lookup is legacy and false-flagged everyone (students usually have
        // no section_id right after enrollment).
        $teacher = Database::one("SELECT id FROM teachers WHERE user_id=? LIMIT 1", [(int)Auth::id()]);
        $tid = $teacher ? (int)$teacher['id'] : 0;
        $isMyStudent = true;
        if ($tid > 0) {
            try {
                $isMyStudent = (int)Database::value(
                    "SELECT COUNT(*) FROM classroom_members cm
                      JOIN subject_classrooms sc ON sc.id = cm.classroom_id
                     WHERE cm.student_id = ? AND sc.teacher_id = ?",
                    [(int)$permit['student_id'], $tid]
                ) > 0;
            } catch (Throwable $e) {
                $isMyStudent = true; // fail-open: never block on a read error
            }
        }
        $overrideNote = '';
        if ($tid > 0 && !$isMyStudent) {
            // School-wide policy still allows it, but the override is written
            // into the approval record (previously only claimed in a flash).
            $overrideNote = ' [Section override: student is not in this teacher\'s subjects.]';
            set_flash('warning','This student is not in your subjects; verification recorded as override.');
        }
        Database::run("UPDATE exam_permits SET status=? WHERE id=?", [self::STATUS_FULLY_APPROVED, $id]);
        Database::insert('permit_approvals', ['permit_id'=>$id,'stage'=>'teacher','status'=>'approved','approver_user_id'=>Auth::id(),'remarks'=>trim(trim((string)($_POST['remarks']??'')) . $overrideNote) ?: null]);
        Database::insert('audit_logs', ['actor_user_id'=>Auth::id(),'action'=>'permit.fully_approved','entity_type'=>'exam_permits','entity_id'=>$id,'ip_address'=>client_ip()]);
        // Notify student
        try {
            $stuUser = Database::one("SELECT s.user_id FROM students s WHERE s.id=? LIMIT 1", [(int)$permit['student_id']]);
            if ($stuUser && $stuUser['user_id']) {
                Database::insert('notifications', [
                    'user_id'=> (int)$stuUser['user_id'],
                    'title'  => 'Exam Permit Fully Approved: ' . $permit['exam_period'],
                    'message'=> 'Permit '.$permit['permit_no'].' is now Fully Approved (Teacher verified). You may print your official permit card.',
                    'type'   => 'success',
                    'link_url'=> url('/student/permits/' . $id . '/print'),
                ]);
            }
        } catch (Throwable $e) {}
        set_flash('success','Permit '.$permit['permit_no'].' verified as Fully Approved (Stage 3/3).');
        redirect('/teacher/permits');
    }

    /* ====================== Public verification via QR ====================== */
    public function verifyQr(array $params = []): void
    {
        $token = trim((string)($params['token'] ?? ''));
        if ($token === '') { http_response_code(404); exit('Invalid QR'); }
        $permit = Database::one(
            "SELECT ep.*, s.student_no, u.first_name, u.last_name, ay.school_year FROM exam_permits ep JOIN students s ON s.id=ep.student_id JOIN users u ON u.id=s.user_id JOIN academic_years ay ON ay.id=ep.academic_year_id WHERE ep.qr_token=? LIMIT 1",
            [$token]
        );
        if (!$permit) { http_response_code(404); exit('Permit not found'); }
        header('Content-Type: text/html; charset=utf-8');
        $ok = $permit['status'] === 'fully_approved';
        $color = $ok ? '#166534' : '#92400e';
        $badge = $ok ? 'FULLY APPROVED' : strtoupper(str_replace('_',' ', $permit['status']));
        $badgeCls = $ok ? 'bg-success' : 'bg-warning text-dark';
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Verify Permit '.$permit['permit_no'].'</title>';
        echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><div class="container py-5"><div class="card mx-auto" style="max-width:560px"><div class="card-body text-center p-4">';
        echo '<div class="mb-2"><span class="badge '.$badgeCls.'">'.$badge.'</span></div><h4>'.$permit['permit_no'].'</h4>';
        echo '<p class="text-muted">'.$permit['first_name'].' '.$permit['last_name'].' ('.$permit['student_no'].')<br>'.$permit['exam_period'].' · '.$permit['school_year'].'</p>';
        echo '<p><span class="badge bg-'.($permit['payment_validated']?'success':'warning').'">'.($permit['payment_validated']?'Payment Validated: '.e($permit['payment_ref']):'Payment Pending').'</span></p>';
        echo '<p style="color:'.$color.';font-weight:600">'.($ok?'✓ This permit is authentic and fully approved by Accounting → Registrar → Teacher.':'⧗ This permit is not yet fully approved. Current: '.$badge).'</p>';
        echo '<a href="' . e(url('/')) . '" class="btn btn-primary">Back to Portal</a></div></div></div></body></html>';
    }
}
