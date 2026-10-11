<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\NotificationController;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use Throwable;

final class EnrollmentController extends Controller
{
    public function index(array $params = []): void
    {
        Auth::requireRole('admin', 'registrar', 'accounting');
        $status = $_GET['status'] ?? 'pending';
        $allowed = ['pending','accounting_approved','awaiting_payment','approved','rejected','action_required','all'];
        if (!in_array($status, $allowed, true)) $status = 'pending';

        $campusFilter = ($_GET['campus'] ?? '') !== '' ? (int) $_GET['campus'] : null;
        $campuses = Database::all("SELECT id, code, name, address FROM campuses WHERE is_active = 1 ORDER BY name");

        $conditions = [];
        $args = [];
        if ($status !== 'all') {
            $conditions[] = 'ea.status = ?';
            $args[] = $status;
        }
        if ($campusFilter !== null) {
            $conditions[] = 'ea.preferred_campus_id = ?';
            $args[] = $campusFilter;
        }
        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $rows = Database::all(
            "SELECT ea.*, gl.name AS level_name, gl.code AS level_code, p.name AS program_name,
                    ay.school_year, CONCAT(u.first_name,' ',u.last_name) AS applicant, u.email AS applicant_email,
                    c.name AS campus_name, c.address AS campus_address
               FROM enrollment_applications ea
               JOIN grade_levels gl ON gl.id = ea.grade_level_applied_id
               LEFT JOIN programs p ON p.id = ea.program_id
               JOIN academic_years ay ON ay.id = ea.academic_year_id
               LEFT JOIN campuses c ON c.id = ea.preferred_campus_id
               LEFT JOIN users u ON u.id = ea.user_id
               $where
              ORDER BY ea.created_at DESC LIMIT 100",
            $args
        );
        // Child identity per row for parent-filed applications (fail-open).
        try {
            $hasChildCol = (bool) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollment_applications' AND COLUMN_NAME = 'applicant_child_id'"
            );
            $hasNameCols = $hasChildCol && class_exists(\App\Core\GuardianLinks::class) && \App\Core\GuardianLinks::hasNameCols();
            foreach ($rows as &$rr) {
                $rr['child_first'] = null;
                $rr['child_last'] = null;
                $cid = $hasChildCol ? (int) ($rr['applicant_child_id'] ?? 0) : 0;
                if ($cid <= 0 && !empty($rr['student_id'])) $cid = (int) $rr['student_id'];
                if ($cid > 0) {
                    $cs = Database::one("SELECT * FROM students WHERE id = ? LIMIT 1", [$cid]);
                    if ($cs !== null) {
                        $cu = !empty($cs['user_id']) ? Database::one("SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1", [(int) $cs['user_id']]) : null;
                        if ($hasNameCols) {
                            $rr['child_first'] = ($cu['first_name'] ?? null) ?? ($cs['first_name'] ?? null);
                            $rr['child_last'] = ($cu['last_name'] ?? null) ?? ($cs['last_name'] ?? null);
                        } else {
                            $rr['child_first'] = $cu['first_name'] ?? null;
                            $rr['child_last'] = $cu['last_name'] ?? null;
                        }
                    }
                }
            }
            unset($rr);
        } catch (Throwable $e) {
        }

        $counts = [];
        foreach (['pending','accounting_approved','awaiting_payment','approved','rejected','action_required'] as $s) {
            $counts[$s] = (int) Database::value("SELECT COUNT(*) FROM enrollment_applications WHERE status = ?", [$s]);
        }
        $counts['all'] = array_sum($counts);

        $this->view('admin/enrollments/index', [
            'title' => 'Enrollment Queue',
            'rows' => $rows,
            'status' => $status,
            'campusFilter' => $campusFilter,
            'campuses' => $campuses,
            'counts' => $counts,
        ]);
    }

    public function show(array $params = []): void
    {
        Auth::requireRole('admin', 'registrar', 'accounting');
        $id = (int) ($params['id'] ?? 0);
        $app = Database::one(
            "SELECT ea.*, gl.name AS level_name, gl.code AS level_code, p.name AS program_name,
                    ay.school_year, u.first_name, u.last_name, u.email, u.phone,
                    c.name AS campus_name, c.address AS campus_address, c.city AS campus_city, c.province AS campus_province
               FROM enrollment_applications ea
               JOIN grade_levels gl ON gl.id = ea.grade_level_applied_id
               LEFT JOIN programs p ON p.id = ea.program_id
               JOIN academic_years ay ON ay.id = ea.academic_year_id
               LEFT JOIN campuses c ON c.id = ea.preferred_campus_id
               LEFT JOIN users u ON u.id = ea.user_id
              WHERE ea.id = ? LIMIT 1",
            [$id]
        );
        if ($app === null) {
            http_response_code(404);
            exit('Application not found');
        }
        // Child identity for parent-filed applications (tagged row first).
        $app['child_first'] = null;
        $app['child_last'] = null;
        $app['child_no'] = null;
        try {
            $cs = null;
            if (!empty($app['applicant_child_id'])) {
                $cs = Database::one("SELECT * FROM students WHERE id = ? LIMIT 1", [(int) $app['applicant_child_id']]);
            } elseif (!empty($app['student_id'])) {
                $cs = Database::one("SELECT * FROM students WHERE id = ? LIMIT 1", [(int) $app['student_id']]);
            }
            if ($cs !== null) {
                $cu = !empty($cs['user_id']) ? Database::one("SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1", [(int) $cs['user_id']]) : null;
                if (class_exists(\App\Core\GuardianLinks::class) && \App\Core\GuardianLinks::hasNameCols()) {
                    $app['child_first'] = ($cu['first_name'] ?? null) ?? ($cs['first_name'] ?? null);
                    $app['child_last'] = ($cu['last_name'] ?? null) ?? ($cs['last_name'] ?? null);
                } else {
                    $app['child_first'] = $cu['first_name'] ?? null;
                    $app['child_last'] = $cu['last_name'] ?? null;
                }
                $app['child_no'] = $cs['student_no'] ?? null;
            }
        } catch (Throwable $e) {
        }
        $docs = Database::all("SELECT * FROM enrollment_documents WHERE application_id = ? ORDER BY doc_type", [$id]);

        $this->view('admin/enrollments/show', [
            'title' => 'Application ' . $app['application_no'],
            'app' => $app,
            'docs' => $docs,
        ]);
    }

    public function approve(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');
        $id = (int) ($params['id'] ?? 0);
        $app = Database::one("SELECT * FROM enrollment_applications WHERE id = ? LIMIT 1", [$id]);
        if ($app === null) { set_flash('error','Application not found.'); redirect('/admin/enrollments'); }
        if (!in_array($app['status'], ['pending','accounting_approved','action_required'], true)) { set_flash('error','Only pending/action_required applications can be approved.'); redirect('/admin/enrollments/'.$id); }

        $planTotal = class_exists(\App\Core\Tuition::class) ? \App\Core\Tuition::total() : 10000.00;
        // Never charge the legacy 1500.00 column default (isset() is always
        // true for it) — effectiveDue() falls back to the plan total instead.
        $amount = class_exists(\App\Core\Tuition::class) ? \App\Core\Tuition::effectiveDue($app['amount_due'] ?? null) : $planTotal;
        if ((float)$amount <= 0) $amount = $planTotal;

        try {
            $result = Database::transaction(function () use ($app, $id, $amount) {
                $studentId = $app['student_id'];
                $providedSno = isset($app['returning_student_no']) ? trim((string)$app['returning_student_no']) : '';
                $isReturning = ($app['new_or_returning'] ?? '') === 'returning' && $providedSno !== '' && preg_match('/^\d{7,10}$/', $providedSno);

                if ($studentId === null) {
                    // A filing tagged to a specific child (parent flow) belongs
                    // to THAT child's row — never to a sibling sharing the
                    // filer's account. Legacy untagged rows keep old behavior.
                    $taggedChild = null;
                    if (!empty($app['applicant_child_id'])) {
                        $taggedChild = Database::one("SELECT id, student_no FROM students WHERE id = ? LIMIT 1", [(int) $app['applicant_child_id']]);
                    }
                    if ($taggedChild !== null) {
                        $studentId = (int) $taggedChild['id'];
                        Database::run("UPDATE students SET status='applicant', grade_level_id=? WHERE id=?", [(int)$app['grade_level_applied_id'], $studentId]);
                        if ($app['program_id'] !== null) Database::run("UPDATE students SET program_id=? WHERE id=?", [(int)$app['program_id'], $studentId]);
                    } else {
                        if (!empty($app['applicant_child_id'])) {
                            throw new \RuntimeException('The linked child record for this application is missing. Contact the registrar — nothing was changed.');
                        }
                    $existingByUser = $app['user_id'] !== null ? Database::one("SELECT id, student_no FROM students WHERE user_id=? LIMIT 1", [(int)$app['user_id']]) : null;
                    if ($existingByUser !== null) {
                        // PERMANENT NUMBER: reuse the existing student_no — never overwrite,
                        // never create a second number for the same portal account.
                        $studentId = (int)$existingByUser['id'];
                        Database::run("UPDATE students SET status='applicant', grade_level_id=? WHERE id=?", [(int)$app['grade_level_applied_id'], $studentId]);
                        if ($app['program_id'] !== null) Database::run("UPDATE students SET program_id=? WHERE id=?", [(int)$app['program_id'], $studentId]);
                    } elseif ($isReturning) {
                        $existing = Database::one("SELECT id, user_id FROM students WHERE student_no = ? LIMIT 1", [$providedSno]);
                        if ($existing !== null) {
                            $studentId = (int)$existing['id'];
                            if (empty($existing['user_id']) && $app['user_id'] !== null) {
                                Database::run("UPDATE students SET grade_level_id=?, user_id=?, status='applicant' WHERE id=?", [(int)$app['grade_level_applied_id'], (int)$app['user_id'], $studentId]);
                            } else {
                                Database::run("UPDATE students SET status='applicant', grade_level_id=? WHERE id=?", [(int)$app['grade_level_applied_id'], $studentId]);
                            }
                            if ($app['program_id'] !== null) Database::run("UPDATE students SET program_id=? WHERE id=?", [(int)$app['program_id'], $studentId]);
                        } else {
                            $studentId = Database::insert('students', ['user_id'=>$app['user_id'],'student_no'=>$providedSno,'grade_level_id'=>$app['grade_level_applied_id'],'program_id'=>$app['program_id'],'status'=>'applicant','admission_date'=>date('Y-m-d')]);
                        }
                    } else {
                        $studentId = Database::insert('students', ['user_id'=>$app['user_id'],'grade_level_id'=>$app['grade_level_applied_id'],'program_id'=>$app['program_id'],'status'=>'applicant','admission_date'=>date('Y-m-d')]);
                        $sno = $this->generateStudentNo((int)$app['grade_level_applied_id']);
                        Database::run("UPDATE students SET student_no = ? WHERE id = ?", [$sno, $studentId]);
                    }
                    }
                } else {
                    Database::run("UPDATE students SET status='applicant', grade_level_id=? WHERE id=?", [(int)$app['grade_level_applied_id'], $studentId]);
                    if ($app['program_id'] !== null) Database::run("UPDATE students SET program_id=? WHERE id=?", [(int)$app['program_id'], $studentId]);
                }

                $transactionRef = 'ENR-' . $app['application_no'] . '-' . strtoupper(bin2hex(random_bytes(2)));
                $payId = Database::insert('payments', [
                    'transaction_ref' => $transactionRef,
                    'student_id' => $studentId,
                    'enrollment_application_id' => (int)$app['id'],
                    'amount' => $amount,
                    'currency' => 'PHP',
                    'payment_method' => 'payrex',
                    'channel' => 'online',
                    'status' => 'pending',
                    'initiated_by' => (int)Auth::id(),
                    'ip_address' => client_ip(),
                ]);

                $intentId = null;
                if (defined('PAYREX_SECRET_KEY') && PAYREX_SECRET_KEY !== '') {
                    try {
                        $client = new \Lib\PayRexClient();
                        $pi = $client->createPaymentIntent((int)round((float)$amount * 100), ['gcash','maya','card','qrph'], 'Enrollment '.$app['application_no'], ['application_no'=>$app['application_no'],'payment_ref'=>$transactionRef]);
                        $intentId = $pi['id'] ?? null;
                        if ($intentId) Database::run("UPDATE payments SET payrex_payment_intent_id=? WHERE id=?", [$intentId, $payId]);
                    } catch (Throwable $e) { storage_log('payrex intent failed for enroll '.$id.': '.$e->getMessage()); }
                }

                Database::run("UPDATE enrollment_applications SET status='awaiting_payment', student_id=?, payment_id=?, amount_due=?, reviewed_by=?, reviewed_at=NOW(), remarks=NULL WHERE id=?", [$studentId, $payId, $amount, (int)Auth::id(), $id]);
                return ['student_id'=>$studentId,'pay_id'=>$payId,'intent'=>$intentId,'amount'=>$amount];
            });

            try {
                if (class_exists(\App\Core\StudentDocs::class) && !empty($result['student_id'])) {
                    \App\Core\StudentDocs::backfillFromApplication($id, (int) $result['student_id']);
                }
            } catch (Throwable $e) {}
            $applicant = Database::one("SELECT * FROM users WHERE id = ?", [(int)$app['user_id']]);
            if ($applicant) {
                try { send_enrollment_payment_email($applicant, $app['application_no'], (float)$result['amount'], $app['id']); } catch (Throwable $e) {}
                try { send_enrollment_status_email($applicant, $app['application_no'], 'Awaiting Payment', 'Accounting & Registrar approved — please pay via PayRex to complete enrollment.'); } catch (Throwable $e) {}
            }
            Database::insert('audit_logs', ['actor_user_id'=>(int)Auth::id(),'action'=>'enrollment.sent_to_payment','entity_type'=>'enrollment_applications','entity_id'=>$id,'ip_address'=>client_ip()]);
            $kidName = class_exists(\App\Core\GuardianLinks::class) ? \App\Core\GuardianLinks::applicantChildName($app) : null;
            $kidPrefix = $kidName !== null ? $kidName . ': ' : '';
            NotificationController::push((int)$app['user_id'], 'Pay your enrollment: ' . $app['application_no'], $kidPrefix . 'Accounting & Registrar approved — pay ' . money_plain($result['amount']) . ' via PayRex to complete enrollment.', 'payment', '/my-enrollments');
            set_flash('success', 'Accounting & Registrar approved! Payment request sent ('.money_plain($result['amount']).') — student must pay via PayRex to be fully enrolled. Ref: ENR-'.$app['application_no']);
        } catch (Throwable $e) {
            storage_log('approve->payment failed: ' . $e->getMessage());
            set_flash('error', 'Failed to send payment: ' . $e->getMessage());
        }
        redirect('/admin/enrollments/' . $id);
    }

    public function markPaid(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');
        $id = (int)($params['id'] ?? 0);
        $app = Database::one("SELECT * FROM enrollment_applications WHERE id=? LIMIT 1", [$id]);
        if (!$app || $app['status'] !== 'awaiting_payment') { set_flash('error','Not awaiting payment.'); redirect('/admin/enrollments/'.$id); }
        try {
            Database::transaction(function() use ($app, $id) {
                $payId = $app['payment_id'];
                if ($payId) Database::run("UPDATE payments SET status='paid', paid_at=NOW() WHERE id=? AND status!='paid'", [$payId]);
                if ($app['student_id']) Database::run("UPDATE students SET status='enrolled' WHERE id=?", [(int)$app['student_id']]);
                Database::run("UPDATE enrollment_applications SET status='approved', reviewed_at=NOW(), reviewed_by=? WHERE id=?", [(int)Auth::id(), $id]);
            });
            $applicant = Database::one("SELECT * FROM users WHERE id=?", [(int)$app['user_id']]);
            if ($applicant) try { send_enrollment_status_email($applicant, $app['application_no'], 'Approved - Enrolled', 'Payment confirmed. You are now fully enrolled.'); } catch (Throwable $e) {}
            // Parent-filed enrollment: link the filing parent to the child so
            // the Parent portal shows them (idempotent, fail-open).
            try {
                if (class_exists(\App\Core\GuardianLinks::class)) {
                    \App\Core\GuardianLinks::ensureForApplication($id, (int) Auth::id());
                }
            } catch (Throwable $e) {
            }
            NotificationController::push((int)$app['user_id'], 'Enrolled: ' . $app['application_no'], 'Payment confirmed — you are now fully enrolled.', 'success', '/dashboard');
            set_flash('success','Payment confirmed — student now fully enrolled.');
        } catch (Throwable $e) { set_flash('error','Failed: '.$e->getMessage()); }
        redirect('/admin/enrollments/'.$id);
    }

    public function reject(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');
        $id = (int) ($params['id'] ?? 0);
        $remarks = trim((string)($_POST['remarks'] ?? ''));
        if ($remarks === '') { set_flash('error','Please provide a reason for rejection.'); redirect('/admin/enrollments/' . $id); }
        $app = Database::one("SELECT * FROM enrollment_applications WHERE id = ? LIMIT 1", [$id]);
        if ($app === null) { set_flash('error','Application not found.'); redirect('/admin/enrollments'); }
        Database::run("UPDATE enrollment_applications SET status='rejected', remarks=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?", [$remarks, (int)Auth::id(), $id]);
        // Cycle integrity: a consumed clearance becomes reusable when its
        // enrollment is rejected (audit-logged, history preserved).
        try {
            if (!empty($app['clearance_id'])) {
                Database::run("UPDATE clearances SET status='issued' WHERE id=? AND status='used'", [(int)$app['clearance_id']]);
                Database::insert('audit_logs', ['actor_user_id'=>(int)Auth::id(),'action'=>'clearance.restored','entity_type'=>'clearances','entity_id'=>(int)$app['clearance_id'],'ip_address'=>client_ip()]);
            }
        } catch (Throwable $e) {
        }
        $applicant = Database::one("SELECT * FROM users WHERE id = ?", [(int)$app['user_id']]);
        if ($applicant) { try { send_enrollment_status_email($applicant, $app['application_no'], 'Rejected', $remarks); } catch (Throwable $e) {} }
        NotificationController::push((int)$app['user_id'], 'Enrollment rejected: ' . $app['application_no'], $remarks, 'danger', '/my-enrollments');
        set_flash('success','Application rejected.');
        redirect('/admin/enrollments/' . $id);
    }

    public function actionRequired(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');
        $id = (int) ($params['id'] ?? 0);
        $remarks = trim((string)($_POST['remarks'] ?? ''));
        if ($remarks === '') { set_flash('error','Please describe what action is required.'); redirect('/admin/enrollments/' . $id); }
        $app = Database::one("SELECT * FROM enrollment_applications WHERE id = ? LIMIT 1", [$id]);
        if ($app === null) { set_flash('error','Application not found.'); redirect('/admin/enrollments'); }
        Database::run("UPDATE enrollment_applications SET status='action_required', remarks=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?", [$remarks, (int)Auth::id(), $id]);
        $applicant = Database::one("SELECT * FROM users WHERE id = ?", [(int)$app['user_id']]);
        if ($applicant) { try { send_enrollment_status_email($applicant, $app['application_no'], 'Action Required', $remarks); } catch (Throwable $e) {} }
        $kidName = class_exists(\App\Core\GuardianLinks::class) ? \App\Core\GuardianLinks::applicantChildName($app) : null;
        $kidPrefix = $kidName !== null ? $kidName . ': ' : '';
        NotificationController::push((int)$app['user_id'], 'Action required: ' . $app['application_no'], $kidPrefix . $remarks, 'warning', '/my-enrollments');
        set_flash('success','Marked as Action Required.');
        redirect('/admin/enrollments/' . $id);
    }

    private function generateStudentNo(int $gradeLevelId): string
    {
        $level = Database::one("SELECT category, code FROM grade_levels WHERE id=? LIMIT 1", [$gradeLevelId]);
        $cat = $level['category'] ?? 'college';
        $base = match($cat) {
            'kindergarten' => 1000000,
            'elementary'   => 1100000,
            'junior_high'  => 1200000,
            'senior_high'  => 1300000,
            'college'      => 2000000,
            default        => 3000000,
        };
        $max = Database::value("SELECT MAX(CAST(student_no AS UNSIGNED)) FROM students WHERE student_no REGEXP '^[0-9]+$' AND CAST(student_no AS UNSIGNED) BETWEEN ? AND ?", [$base, $base + 999999]);
        $next = $max ? ((int)$max + 1) : $base;
        if ($next > $base + 999999) $next = $base + (int)(microtime(true)*1000) % 900000;
        $tries = 0;
        while (Database::one("SELECT id FROM students WHERE student_no=? LIMIT 1", [(string)$next]) && $tries < 5) {
            $next++;
            $tries++;
        }
        return (string)$next;
    }
}