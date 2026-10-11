<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use Throwable;

/**
 * Accounting Office: enrollment finance, payments, balances, financial clearance.
 * Cannot modify academic grades or official academic records (enforced by routes).
 */
final class AccountingController extends Controller
{
    private function guard(): void
    {
        Auth::requireRole('accounting', 'admin');
    }

    private static function balanceFor(int $studentId): float
    {
        try {
            $v = Database::value(
                "SELECT COALESCE((SELECT SUM(amount) FROM student_charges WHERE student_id = ?),0)
                        - COALESCE((SELECT SUM(pa.amount_applied) FROM payment_allocations pa
                                     JOIN payments p ON p.id = pa.payment_id WHERE p.status = 'paid'
                                      AND pa.charge_id IN (SELECT id FROM student_charges WHERE student_id = ?)),0)",
                [$studentId, $studentId]
            );
            return (float) max(0, (float) $v);
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    /* ================= Enrollment finance ================= */

    public function enrollments(array $params = []): void
    {
        $this->guard();
        $status = trim((string) ($_GET['status'] ?? 'pending'));
        $allowed = ['pending', 'accounting_approved', 'awaiting_payment', 'approved', 'rejected', 'action_required', 'all'];
        if (!in_array($status, $allowed, true)) $status = 'pending';
        $where = $status === 'all' ? '' : 'WHERE ea.status = ?';
        $args = $status === 'all' ? [] : [$status];
        // Child identity for parent-filed applications (tagged row or linked
        // student), so reviewers never confuse the filer with the student.
        $childJoin = '';
        $childSel = 'NULL AS child_first, NULL AS child_last, NULL AS child_no';
        try {
            $hasChildCol = (bool) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollment_applications' AND COLUMN_NAME = 'applicant_child_id'"
            );
            $hasNameCols = class_exists(\App\Core\GuardianLinks::class) && \App\Core\GuardianLinks::hasNameCols();
            if ($hasChildCol) {
                $childJoin = " LEFT JOIN students cs ON (cs.id = ea.student_id OR cs.id = ea.applicant_child_id)"
                    . " LEFT JOIN users cu ON cu.id = cs.user_id";
                $childSel = $hasNameCols
                    ? "COALESCE(cu.first_name, cs.first_name) AS child_first, COALESCE(cu.last_name, cs.last_name) AS child_last, cs.student_no AS child_no"
                    : "cu.first_name AS child_first, cu.last_name AS child_last, cs.student_no AS child_no";
            } else {
                $childJoin = " LEFT JOIN students cs ON cs.id = ea.student_id LEFT JOIN users cu ON cu.id = cs.user_id";
                $childSel = "cu.first_name AS child_first, cu.last_name AS child_last, cs.student_no AS child_no";
            }
        } catch (Throwable $e) {
        }
        $rows = Database::all(
            "SELECT ea.*, gl.name AS level_name, ay.school_year, CONCAT(u.first_name,' ',u.last_name) AS applicant, u.email AS applicant_email,
                    {$childSel}
               FROM enrollment_applications ea
               JOIN grade_levels gl ON gl.id = ea.grade_level_applied_id
               JOIN academic_years ay ON ay.id = ea.academic_year_id
               LEFT JOIN users u ON u.id = ea.user_id
               {$childJoin}
               $where ORDER BY ea.created_at DESC LIMIT 100",
            $args
        );
        // Payment-plan snapshot per application (fail-open when tables missing).
        try {
            if (class_exists(\App\Core\Tuition::class) && \App\Core\Tuition::hasTables()) {
                $ids = array_map(static fn($r) => (int) $r['id'], $rows);
                if ($ids !== []) {
                    $plans = Database::all(
                        "SELECT epp.*, (SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.enrollment_application_id = epp.application_id AND p.status = 'paid') AS paid_total,
                                (SELECT MIN(pi2.due_date) FROM plan_installments pi2 WHERE pi2.epp_id = epp.id AND pi2.status IN ('due','upcoming')) AS next_due
                           FROM enrollment_payment_plans epp WHERE epp.application_id IN (" . implode(',', $ids) . ")"
                    );
                    $byApp = [];
                    foreach ($plans as $pl) $byApp[(int) $pl['application_id']] = $pl;
                    foreach ($rows as &$r) {
                        $r['plan'] = $byApp[(int) $r['id']] ?? null;
                    }
                    unset($r);
                }
            }
        } catch (Throwable $e) {
        }
        // Attach outstanding balance per linked student for quick review
        foreach ($rows as &$r) {
            $r['outstanding'] = !empty($r['student_id']) ? self::balanceFor((int) $r['student_id']) : null;
        }
        unset($r);
        $counts = [];
        foreach (['pending', 'accounting_approved', 'awaiting_payment', 'approved'] as $st) {
            $counts[$st] = (int) Database::value("SELECT COUNT(*) FROM enrollment_applications WHERE status = ?", [$st]);
        }
        $counts['all'] = (int) Database::value("SELECT COUNT(*) FROM enrollment_applications");
        $this->view('accounting/enrollments_index', [
            'title' => 'Enrollment Transactions — Accounting', 'rows' => $rows, 'status' => $status, 'counts' => $counts,
        ]);
    }

    public function verifyEnrollment(array $params = []): void
    {
        Csrf::guard();
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        $app = Database::one("SELECT * FROM enrollment_applications WHERE id = ? LIMIT 1", [$id]);
        if ($app === null || $app['status'] !== 'pending') {
            set_flash('error', 'Only pending applications can be financially verified.');
            redirect('/accounting/enrollments');
        }
        $balance = !empty($app['student_id']) ? self::balanceFor((int) $app['student_id']) : 0.0;
        Database::run(
            "UPDATE enrollment_applications SET status = 'accounting_approved', reviewed_by = ?, reviewed_at = NOW(), remarks = ? WHERE id = ?",
            [(int) Auth::id(), trim((string) ($_POST['remarks'] ?? '')) ?: ('Financial requirements verified. Outstanding: ' . money_plain($balance)), $id]
        );
        Database::insert('audit_logs', [
            'actor_user_id' => (int) Auth::id(), 'action' => 'enrollment.accounting_verified',
            'entity_type' => 'enrollment_applications', 'entity_id' => $id, 'ip_address' => client_ip(),
        ]);
        try {
            \App\Controllers\NotificationController::push((int) $app['user_id'], 'Accounting verified: ' . $app['application_no'], 'Financial requirements satisfied — forwarded to Registrar for academic processing.', 'success', '/my-enrollments');
            \App\Controllers\NotificationController::pushToRegistrars('Enrollment ready for academic review: ' . $app['application_no'], 'Accounting verified — review in Registrar enrollments.', 'info', '/registrar/enrollments');
        } catch (Throwable $e) {
        }
        set_flash('success', 'Application ' . $app['application_no'] . ' financially verified → forwarded to Registrar.');
        redirect('/accounting/enrollments');
    }

    public function markPaid(array $params = []): void
    {
        Csrf::guard();
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        $app = Database::one("SELECT * FROM enrollment_applications WHERE id = ? LIMIT 1", [$id]);
        if ($app === null || $app['status'] !== 'awaiting_payment') {
            set_flash('error', 'Only applications awaiting payment can be confirmed.');
            redirect('/accounting/enrollments');
        }
        try {
            Database::transaction(function () use ($app, $id) {
                $payId = $app['payment_id'];
                if ($payId) {
                    Database::run("UPDATE payments SET status = 'paid', paid_at = NOW() WHERE id = ? AND status != 'paid'", [$payId]);
                    try {
                        Database::insert('receipts', ['payment_id' => $payId, 'receipt_no' => 'RC-' . date('Y') . '-' . str_pad((string) $payId, 6, '0', STR_PAD_LEFT)]);
                    } catch (Throwable $e) {
                    }
                }
                if ($app['student_id']) Database::run("UPDATE students SET status = 'enrolled' WHERE id = ?", [(int) $app['student_id']]);
                Database::run("UPDATE enrollment_applications SET status = 'approved', reviewed_at = NOW(), reviewed_by = ? WHERE id = ?", [(int) Auth::id(), $id]);
            });
            Database::insert('audit_logs', [
                'actor_user_id' => (int) Auth::id(), 'action' => 'enrollment.payment_confirmed',
                'entity_type' => 'enrollment_applications', 'entity_id' => $id, 'ip_address' => client_ip(),
            ]);
            try {
                if (!empty($app['payment_id']) && class_exists(\App\Core\Tuition::class)) {
                    \App\Core\Tuition::applyPaidPayment((int) $app['payment_id'], (int) Auth::id());
                }
            } catch (Throwable $e) {
            }
            // Parent-filed enrollment: link the filing parent to the child so
            // the Parent portal shows them (idempotent, fail-open).
            try {
                if (class_exists(\App\Core\GuardianLinks::class)) {
                    \App\Core\GuardianLinks::ensureForApplication($id, (int) Auth::id());
                }
            } catch (Throwable $e) {
            }
            \App\Controllers\NotificationController::push((int) $app['user_id'], 'Enrolled: ' . $app['application_no'], 'Payment confirmed by Accounting — you are now fully enrolled.', 'success', '/dashboard');
            set_flash('success', 'Payment confirmed — student now fully enrolled.');
        } catch (Throwable $e) {
            storage_log('accounting markPaid failed ' . $id . ': ' . $e->getMessage());
            set_flash('error', 'Failed: ' . $e->getMessage());
        }
        redirect('/accounting/enrollments');
    }

    /* ================= Student financial accounts ================= */

    public function students(array $params = []): void
    {
        $this->guard();
        $q = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $per = 20;
        $off = ($page - 1) * $per;
        $where = '';
        $bind = [];
        if ($q !== '') {
            $where = "WHERE (u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR s.first_name LIKE ? OR s.middle_name LIKE ? OR s.last_name LIKE ? OR s.student_no LIKE ? OR CONCAT(u.first_name,' ',u.last_name) LIKE ? OR CONCAT(s.first_name,' ',s.last_name) LIKE ?)";
            $like = "%$q%";
            $bind = [$like, $like, $like, $like, $like, $like, $like, $like, $like];
        }
        $rows = Database::all(
            "SELECT s.id, s.student_no, s.status,
                    COALESCE(NULLIF(u.first_name,''), s.first_name) AS first_name,
                    COALESCE(NULLIF(u.last_name,''), s.last_name) AS last_name,
                    u.email
               FROM students s LEFT JOIN users u ON u.id = s.user_id
               $where ORDER BY s.id DESC LIMIT $per OFFSET $off",
            $bind
        );
        foreach ($rows as &$r) {
            $r['balance'] = self::balanceFor((int) $r['id']);
        }
        unset($r);
        $total = (int) Database::value("SELECT COUNT(*) FROM students s LEFT JOIN users u ON u.id = s.user_id $where", $bind);
        $this->view('accounting/students_index', [
            'title' => 'Student Financial Accounts', 'rows' => $rows, 'q' => $q,
            'page' => $page, 'per' => $per, 'total' => $total,
        ]);
    }

    public function studentAccount(array $params = []): void
    {
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        $s = Database::one(
            "SELECT s.*, u.email, u.phone,
                    COALESCE(NULLIF(u.first_name,''), s.first_name) AS first_name,
                    COALESCE(NULLIF(u.last_name,''), s.last_name) AS last_name,
                    gl.name AS level_name, sec.name AS section_name
               FROM students s LEFT JOIN users u ON u.id = s.user_id
               LEFT JOIN grade_levels gl ON gl.id = s.grade_level_id
               LEFT JOIN sections sec ON sec.id = s.section_id
              WHERE s.id = ? LIMIT 1",
            [$id]
        );
        if ($s === null) {
            http_response_code(404);
            exit('Student not found');
        }
        $charges = Database::all(
            "SELECT sc.*, ft.name AS fee_name FROM student_charges sc
               LEFT JOIN fee_structures fs ON fs.id = sc.fee_structure_id
               LEFT JOIN fee_types ft ON ft.id = fs.fee_type_id
              WHERE sc.student_id = ? ORDER BY sc.charge_date DESC LIMIT 200",
            [$id]
        );
        $payments = Database::all(
            "SELECT p.*, r.receipt_no FROM payments p LEFT JOIN receipts r ON r.payment_id = p.id
              WHERE p.student_id = ? ORDER BY p.created_at DESC LIMIT 200",
            [$id]
        );
        $allocs = Database::all(
            "SELECT pa.*, sc.description FROM payment_allocations pa
               JOIN student_charges sc ON sc.id = pa.charge_id
              WHERE sc.student_id = ? ORDER BY pa.id DESC LIMIT 200",
            [$id]
        );
        $this->view('accounting/students_show', [
            'title' => 'Account — ' . ($s['student_no'] ?? ('#' . $id)),
            'student' => $s, 'charges' => $charges, 'payments' => $payments,
            'allocations' => $allocs, 'balance' => self::balanceFor($id),
        ]);
    }

    /* ================= Payments ================= */

    public function payments(array $params = []): void
    {
        $this->guard();
        $status = trim((string) ($_GET['status'] ?? 'processing'));
        $allowed = ['pending', 'processing', 'paid', 'failed', 'cancelled', 'expired', 'all'];
        if (!in_array($status, $allowed, true)) $status = 'processing';
        if ($status === 'failed') {
            $where = "WHERE p.status IN ('failed','cancelled','expired')";
            $args = [];
        } else {
            $where = $status === 'all' ? '' : 'WHERE p.status = ?';
            $args = $status === 'all' ? [] : [$status];
        }
        $rows = Database::all(
            "SELECT p.*, u.email, u.first_name, u.last_name, s.student_no
               FROM payments p
               LEFT JOIN students s ON s.id = p.student_id
               LEFT JOIN users u ON u.id = s.user_id
               $where ORDER BY p.created_at DESC LIMIT 100",
            $args
        );
        $counts = [];
        foreach (['pending', 'processing', 'paid', 'failed', 'cancelled', 'expired'] as $st) {
            $counts[$st] = (int) Database::value("SELECT COUNT(*) FROM payments WHERE status = ?", [$st]);
        }
        $counts['failed'] = $counts['failed'] + $counts['cancelled'] + $counts['expired'];
        $counts['all'] = (int) Database::value("SELECT COUNT(*) FROM payments");
        $this->view('accounting/payments_index', [
            'title' => 'Payment Records — Accounting', 'rows' => $rows, 'status' => $status, 'counts' => $counts,
        ]);
    }

    public function approvePayment(array $params = []): void
    {
        Csrf::guard();
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        $pay = Database::one("SELECT * FROM payments WHERE id = ? LIMIT 1", [$id]);
        if ($pay === null || !in_array($pay['status'], ['pending', 'processing'], true)) {
            set_flash('error', 'Payment not awaiting approval.');
            redirect('/accounting/payments');
        }
        try {
            $actorId = (int) Auth::id();
            Database::transaction(function () use ($pay, $id, $actorId) {
                Database::run("UPDATE payments SET status = 'paid', paid_at = NOW() WHERE id = ?", [$id]);
                $receiptNo = 'RC-' . date('Y') . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
                try {
                    Database::insert('receipts', ['payment_id' => $id, 'receipt_no' => $receiptNo]);
                } catch (Throwable $e) {
                }
                // Keep payment and enrollment in sync: a paid enrollment
                // payment completes the application (same as admin approval).
                if (!empty($pay['enrollment_application_id'])) {
                    Database::run(
                        "UPDATE enrollment_applications SET status = 'approved', reviewed_at = NOW(), reviewed_by = ? WHERE id = ? AND status = 'awaiting_payment'",
                        [$actorId, (int) $pay['enrollment_application_id']]
                    );
                    $app = Database::one("SELECT student_id, user_id, application_no FROM enrollment_applications WHERE id = ? LIMIT 1", [(int) $pay['enrollment_application_id']]);
                    if ($app && !empty($app['student_id'])) {
                        Database::run("UPDATE students SET status = 'enrolled' WHERE id = ?", [(int) $app['student_id']]);
                    }
                }
            });
            Database::insert('audit_logs', [
                'actor_user_id' => (int) Auth::id(), 'action' => 'payment.accounting_approved',
                'entity_type' => 'payments', 'entity_id' => $id, 'ip_address' => client_ip(),
            ]);
            try {
                if (class_exists(\App\Core\Tuition::class)) {
                    \App\Core\Tuition::applyPaidPayment($id, (int) Auth::id());
                }
            } catch (Throwable $e) {
            }
            try {
                if (!empty($pay['enrollment_application_id'])) {
                    $appUser = Database::one("SELECT * FROM enrollment_applications WHERE id = ? LIMIT 1", [(int) $pay['enrollment_application_id']]);
                    if ($appUser && !empty($appUser['user_id'])) {
                        $kidName = class_exists(\App\Core\GuardianLinks::class) ? \App\Core\GuardianLinks::applicantChildName($appUser) : null;
                        $kidPrefix = $kidName !== null ? $kidName . ': ' : '';
                        \App\Controllers\NotificationController::push(
                            (int) $appUser['user_id'],
                            'Enrolled: ' . ($appUser['application_no'] ?? ''),
                            $kidPrefix . 'Payment ' . $pay['transaction_ref'] . ' confirmed by Accounting — you are now fully enrolled.',
                            'success', '/dashboard'
                        );
                    }
                }
            } catch (Throwable $e) {
            }
            // Parent-filed enrollment paid here: link filer to child (idempotent).
            try {
                if (!empty($pay['enrollment_application_id']) && class_exists(\App\Core\GuardianLinks::class)) {
                    \App\Core\GuardianLinks::ensureForApplication((int) $pay['enrollment_application_id'], (int) Auth::id());
                }
            } catch (Throwable $e) {
            }
            set_flash('success', 'Payment ' . $pay['transaction_ref'] . ' approved (Paid).');
        } catch (Throwable $e) {
            storage_log('accounting approvePayment failed ' . $id . ': ' . $e->getMessage());
            set_flash('error', 'Failed: ' . $e->getMessage());
        }
        redirect('/accounting/payments');
    }

    public function rejectPayment(array $params = []): void
    {
        Csrf::guard();
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? $_POST['remarks'] ?? ''));
        if (mb_strlen($reason) < 5) {
            set_flash('error', 'A rejection reason (min 5 characters) is required.');
            redirect('/accounting/payments');
        }
        $pay = Database::one("SELECT * FROM payments WHERE id = ? LIMIT 1", [$id]);
        if ($pay === null || !in_array($pay['status'], ['pending', 'processing'], true)) {
            set_flash('error', 'Payment not awaiting approval.');
            redirect('/accounting/payments');
        }
        Database::run("UPDATE payments SET status = 'failed', failure_reason = ? WHERE id = ?", [mb_substr($reason, 0, 255), $id]);
        Database::insert('audit_logs', [
            'actor_user_id' => (int) Auth::id(), 'action' => 'payment.accounting_rejected',
            'entity_type' => 'payments', 'entity_id' => $id, 'ip_address' => client_ip(),
        ]);
        set_flash('success', 'Payment ' . $pay['transaction_ref'] . ' rejected.');
        redirect('/accounting/payments');
    }

    /* ================= Financial clearance ================= */

    public function clearances(array $params = []): void
    {
        $this->guard();
        $status = trim((string) ($_GET['status'] ?? 'pending'));
        $allowed = ['pending', 'for_review', 'cleared', 'not_cleared', 'on_hold', 'all'];
        if (!in_array($status, $allowed, true)) $status = 'pending';
        $where = $status === 'all' ? '' : 'WHERE c.financial_status = ?';
        $args = $status === 'all' ? [] : [$status];
        $rows = Database::all(
            "SELECT c.*, s.student_no, u.first_name, u.last_name, ay.school_year
               FROM clearances c
               JOIN students s ON s.id = c.student_id
               JOIN users u ON u.id = s.user_id
               JOIN academic_years ay ON ay.id = c.academic_year_id
               $where ORDER BY c.created_at DESC LIMIT 100",
            $args
        );
        foreach ($rows as &$r) {
            $r['balance'] = self::balanceFor((int) $r['student_id']);
        }
        unset($r);
        $counts = [];
        foreach (['pending', 'for_review', 'cleared', 'not_cleared', 'on_hold'] as $st) {
            $counts[$st] = (int) Database::value("SELECT COUNT(*) FROM clearances WHERE financial_status = ?", [$st]);
        }
        $counts['all'] = array_sum($counts);
        $history = [];
        try {
            $history = Database::all(
                "SELECT l.*, CONCAT(u.first_name,' ',u.last_name) AS actor FROM clearance_status_logs l
                  LEFT JOIN users u ON u.id = l.actor_user_id ORDER BY l.created_at DESC LIMIT 20"
            );
        } catch (Throwable $e) {
        }
        $this->view('accounting/clearances_index', [
            'title' => 'Financial Clearance — Accounting', 'rows' => $rows,
            'status' => $status, 'counts' => $counts, 'history' => $history,
        ]);
    }

    public function updateClearance(array $params = []): void
    {
        Csrf::guard();
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        $to = trim((string) ($_POST['financial_status'] ?? ''));
        $remarks = trim((string) ($_POST['remarks'] ?? ''));
        if (!in_array($to, ['pending', 'for_review', 'cleared', 'not_cleared', 'on_hold'], true)) {
            set_flash('error', 'Invalid clearance status.');
            redirect('/accounting/clearances');
        }
        if (in_array($to, ['not_cleared', 'on_hold'], true) && mb_strlen($remarks) < 5) {
            set_flash('error', 'Marking ' . str_replace('_', ' ', $to) . ' requires a reason (min 5 characters).');
            redirect('/accounting/clearances');
        }
        $clr = Database::one("SELECT * FROM clearances WHERE id = ? LIMIT 1", [$id]);
        if ($clr === null) {
            set_flash('error', 'Clearance not found.');
            redirect('/accounting/clearances');
        }
        if ($to === 'cleared') {
            $bal = self::balanceFor((int) $clr['student_id']);
            if ($bal > 0.01) {
                set_flash('error', 'Cannot mark Cleared — outstanding balance ' . money_plain($bal) . ' remains.');
                redirect('/accounting/clearances');
            }
        }
        $from = (string) ($clr['financial_status'] ?? 'pending');
        Database::run(
            "UPDATE clearances SET financial_status = ?, financial_reviewed_by = ?, financial_reviewed_at = NOW(), financial_remarks = ? WHERE id = ?",
            [$to, (int) Auth::id(), $remarks !== '' ? $remarks : null, $id]
        );
        try {
            Database::insert('clearance_status_logs', [
                'clearance_id' => $id, 'from_status' => $from, 'to_status' => $to,
                'remarks' => $remarks !== '' ? $remarks : null, 'actor_user_id' => (int) Auth::id(),
            ]);
        } catch (Throwable $e) {
        }
        Database::insert('audit_logs', [
            'actor_user_id' => (int) Auth::id(), 'action' => 'clearance.financial_' . $to,
            'entity_type' => 'clearances', 'entity_id' => $id, 'ip_address' => client_ip(),
        ]);
        \App\Core\EnrollmentCycle::maybeComplete($id);
        try {
            $stuUser = Database::one("SELECT user_id FROM students WHERE id = ? LIMIT 1", [(int) $clr['student_id']]);
            if ($stuUser && !empty($stuUser['user_id'])) {
                \App\Controllers\NotificationController::push(
                    (int) $stuUser['user_id'],
                    'Financial clearance: ' . ucfirst(str_replace('_', ' ', $to)),
                    'Clearance #' . $id . ' financial status is now ' . $to . ($remarks !== '' ? ' — ' . $remarks : '.'),
                    $to === 'cleared' ? 'success' : 'warning',
                    '/student/clearances'
                );
            }
        } catch (Throwable $e) {
        }
        if ($to === 'cleared') {
            \App\Controllers\NotificationController::pushToRegistrars(
                'Clearance cleared — needs academic review #' . $id,
                'Clearance #' . $id . ' passed financial review. Review in Clearances queue.',
                'info', '/registrar/clearances'
            );
        }
        set_flash('success', 'Clearance #' . $id . ' financial status → ' . $to . '.');
        redirect('/accounting/clearances');
    }

    /* ================= Permits (accounting stage) ================= */

    public function permits(array $params = []): void
    {
        $this->guard();
        $status = trim((string) ($_GET['status'] ?? 'pending_accounting'));
        $allowed = ['pending_accounting', 'approved_accounting', 'approved_registrar', 'fully_approved', 'rejected', 'all'];
        if (!in_array($status, $allowed, true)) $status = 'pending_accounting';
        $where = $status === 'all' ? '' : 'WHERE ep.status = ?';
        $args = $status === 'all' ? [] : [$status];
        $rows = Database::all(
            "SELECT ep.*, s.student_no, u.first_name, u.last_name, ay.school_year
               FROM exam_permits ep
               JOIN students s ON s.id = ep.student_id
               JOIN users u ON u.id = s.user_id
               JOIN academic_years ay ON ay.id = ep.academic_year_id
               $where ORDER BY ep.requested_at DESC LIMIT 100",
            $args
        );
        foreach ($rows as &$r) {
            $r['balance'] = self::balanceFor((int) $r['student_id']);
        }
        unset($r);
        $counts = [];
        foreach (['pending_accounting', 'approved_accounting', 'approved_registrar', 'fully_approved', 'rejected'] as $st) {
            $counts[$st] = (int) Database::value("SELECT COUNT(*) FROM exam_permits WHERE status = ?", [$st]);
        }
        $counts['all'] = (int) Database::value("SELECT COUNT(*) FROM exam_permits");
        $this->view('accounting/permits_index', [
            'title' => 'Exam Permits — Accounting', 'rows' => $rows, 'status' => $status, 'counts' => $counts,
        ]);
    }

    public function approvePermit(array $params = []): void
    {
        Csrf::guard();
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        $permit = Database::one("SELECT * FROM exam_permits WHERE id = ? LIMIT 1", [$id]);
        if ($permit === null || $permit['status'] !== 'pending_accounting') {
            set_flash('error', 'Permit not awaiting Accounting approval.');
            redirect('/accounting/permits');
        }
        Database::run("UPDATE exam_permits SET status = 'approved_accounting' WHERE id = ?", [$id]);
        Database::insert('permit_approvals', [
            'permit_id' => $id, 'stage' => 'accounting', 'status' => 'approved',
            'approver_user_id' => Auth::id(), 'remarks' => trim((string) ($_POST['remarks'] ?? '')) ?: null,
        ]);
        Database::insert('audit_logs', [
            'actor_user_id' => Auth::id(), 'action' => 'permit.approved_accounting',
            'entity_type' => 'exam_permits', 'entity_id' => $id, 'ip_address' => client_ip(),
        ]);
        \App\Controllers\NotificationController::pushToRegistrars('Permit ready for Registrar: ' . $permit['permit_no'], $permit['exam_period'] . ' passed Accounting — review in Registrar queue.', 'info', '/registrar/permits');
        set_flash('success', 'Permit ' . $permit['permit_no'] . ' approved (Accounting Stage 1/3).');
        redirect('/accounting/permits');
    }

    public function rejectPermit(array $params = []): void
    {
        Csrf::guard();
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? $_POST['remarks'] ?? ''));
        if (mb_strlen($reason) < 5) {
            set_flash('error', 'A rejection reason (min 5 characters) is required.');
            redirect('/accounting/permits');
        }
        $permit = Database::one("SELECT * FROM exam_permits WHERE id = ? LIMIT 1", [$id]);
        if ($permit === null || $permit['status'] !== 'pending_accounting') {
            set_flash('error', 'Permit cannot be rejected in its current state.');
            redirect('/accounting/permits');
        }
        Database::run("UPDATE exam_permits SET status = 'rejected', rejected_reason = ? WHERE id = ?", [$reason, $id]);
        Database::insert('permit_approvals', [
            'permit_id' => $id, 'stage' => 'accounting', 'status' => 'rejected',
            'approver_user_id' => Auth::id(), 'remarks' => $reason,
        ]);
        Database::insert('audit_logs', [
            'actor_user_id' => Auth::id(), 'action' => 'permit.rejected_accounting',
            'entity_type' => 'exam_permits', 'entity_id' => $id, 'ip_address' => client_ip(),
        ]);
        set_flash('success', 'Permit ' . $permit['permit_no'] . ' rejected with reason logged.');
        redirect('/accounting/permits');
    }

    /* ============ Permanent enrollment documents (reuse + resubmission) ==== */

    public function documents(array $params = []): void
    {
        $this->guard();
        $tab = trim((string) ($_GET['tab'] ?? 'uploads'));
        if (!in_array($tab, ['uploads', 'permanent'], true)) $tab = 'uploads';
        $status = trim((string) ($_GET['status'] ?? 'submitted'));
        $allowed = ['submitted', 'verified', 'needs_resubmission', 'rejected', 'all'];
        if (!in_array($status, $allowed, true)) $status = 'submitted';
        $q = trim((string) ($_GET['q'] ?? ''));
        $types = class_exists(\App\Core\StudentDocs::class) ? \App\Core\StudentDocs::TYPES : [];

        // Tab 1 (default): files students actually uploaded per application —
        // always readable, no migration dependency.
        $uploads = [];
        $uploadCounts = ['submitted' => 0, 'verified' => 0, 'needs_resubmission' => 0, 'rejected' => 0, 'all' => 0];
        try {
            $uw = [];
            $ub = [];
            if ($status !== 'all') { $uw[] = 'ed.status = ?'; $ub[] = $status; }
            if ($q !== '') {
                $uw[] = "(u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR s.student_no LIKE ? OR ea.application_no LIKE ? OR ea.returning_student_no LIKE ?)";
                $like = "%$q%";
                array_push($ub, $like, $like, $like, $like, $like, $like);
            }
            $uwsql = $uw ? 'WHERE ' . implode(' AND ', $uw) : '';
            $hasEaPeriodAcc = false;
            try {
                $hasEaPeriodAcc = (bool) Database::value(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollment_applications' AND COLUMN_NAME = 'period_code'"
                );
            } catch (Throwable $e) {
            }
            $eaPerSelAcc = $hasEaPeriodAcc ? 'ea.period_code AS app_period,' : 'NULL AS app_period,';
            $uploads = Database::all(
                "SELECT ed.*, ea.application_no, ea.status AS app_status, ea.student_id AS app_student_id,
                        ea.returning_student_no, ea.semester AS app_semester, {$eaPerSelAcc} ay.school_year,
                        s.student_no, TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS applicant, u.email
                   FROM enrollment_documents ed
                   JOIN enrollment_applications ea ON ea.id = ed.application_id
                   JOIN academic_years ay ON ay.id = ea.academic_year_id
                   LEFT JOIN users u ON u.id = ea.user_id
                   LEFT JOIN students s ON s.id = ea.student_id
                   $uwsql ORDER BY ed.uploaded_at DESC LIMIT 100",
                $ub
            );
            foreach (['submitted', 'verified', 'needs_resubmission', 'rejected'] as $st) {
                $uploadCounts[$st] = (int) Database::value("SELECT COUNT(*) FROM enrollment_documents WHERE status = ?", [$st]);
            }
            // Legacy rows predate the status column backfill — count them as submitted.
            $uploadCounts['submitted'] += (int) Database::value("SELECT COUNT(*) FROM enrollment_documents WHERE status IS NULL OR status = ''");
            $uploadCounts['all'] = array_sum([$uploadCounts['submitted'], $uploadCounts['verified'], $uploadCounts['needs_resubmission'], $uploadCounts['rejected']]);
        } catch (Throwable $e) {
            $uploads = [];
        }

        // Tab 2: permanent per-student file (needs the migration; fail open).
        $rows = [];
        $counts = ['submitted' => 0, 'verified' => 0, 'needs_resubmission' => 0, 'rejected' => 0, 'all' => 0];
        if (class_exists(\App\Core\StudentDocs::class) && \App\Core\StudentDocs::hasTable()) {
            try {
                $where = [];
                $bind = [];
                if ($status !== 'all') { $where[] = 'sd.status = ?'; $bind[] = $status; }
                if ($q !== '') {
                    $where[] = "(u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR s.student_no LIKE ?)";
                    $like = "%$q%";
                    array_push($bind, $like, $like, $like, $like);
                }
                $wsql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
                $rows = Database::all(
                    "SELECT sd.*, s.student_no, u.first_name, u.last_name, u.email
                       FROM student_documents sd
                       JOIN students s ON s.id = sd.student_id
                       LEFT JOIN users u ON u.id = s.user_id
                       $wsql ORDER BY sd.updated_at DESC LIMIT 100",
                    $bind
                );
                foreach (['submitted', 'verified', 'needs_resubmission', 'rejected'] as $st) {
                    $counts[$st] = (int) Database::value("SELECT COUNT(*) FROM student_documents WHERE status = ?", [$st]);
                }
                $counts['all'] = array_sum($counts);
            } catch (Throwable $e) {
                $rows = [];
            }
        }
        $storeReady = false;
        try {
            $storeReady = class_exists(\App\Core\StudentDocs::class)
                && \App\Core\StudentDocs::hasTable()
                && Database::value("SHOW COLUMNS FROM enrollment_documents LIKE 'status'") !== null;
        } catch (Throwable $e) {
            $storeReady = false;
        }
        $this->view('accounting/documents_index', [
            'title' => 'Enrollment Documents — Accounting',
            'tab' => $tab, 'uploads' => $uploads, 'uploadCounts' => $uploadCounts,
            'rows' => $rows, 'status' => $status, 'counts' => $counts, 'q' => $q,
            'types' => $types, 'storeReady' => $storeReady,
        ]);
    }

    /** Verify an uploaded file (per-application row); imports it to the permanent file. */
    public function verifyUpload(array $params = []): void
    {
        Csrf::guard();
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        try {
            $doc = Database::one("SELECT * FROM enrollment_documents WHERE id = ? LIMIT 1", [$id]);
        } catch (Throwable $e) {
            $doc = null;
        }
        if ($doc === null) { set_flash('error', 'Upload not found.'); redirect('/accounting/documents?tab=uploads'); }
        try {
            Database::run(
                "UPDATE enrollment_documents SET status = 'verified', is_verified = 1, verified_by = ?, verified_at = NOW(), review_reason = NULL WHERE id = ?",
                [(int) Auth::id(), $id]
            );
        } catch (Throwable $e) {
            set_flash('error', 'Document store not up to date — import database/patch_enrollment_form_docs.sql first.');
            redirect('/accounting/documents?tab=uploads');
        }
        $imported = 0;
        try {
            $app = Database::one("SELECT student_id FROM enrollment_applications WHERE id = ? LIMIT 1", [(int) $doc['application_id']]);
            if ($app && !empty($app['student_id']) && class_exists(\App\Core\StudentDocs::class)) {
                $imported = \App\Core\StudentDocs::backfillFromApplication((int) $doc['application_id'], (int) $app['student_id']);
            }
        } catch (Throwable $e) {}
        Database::insert('audit_logs', [
            'actor_user_id' => (int) Auth::id(), 'action' => 'enrollment_upload.verified',
            'entity_type' => 'enrollment_documents', 'entity_id' => $id, 'ip_address' => client_ip(),
        ]);
        try {
            $appUser = Database::one(
                "SELECT ea.user_id, ea.application_no FROM enrollment_applications ea WHERE ea.id = ? LIMIT 1",
                [(int) $doc['application_id']]
            );
            if ($appUser && !empty($appUser['user_id'])) {
                \App\Controllers\NotificationController::push(
                    (int) $appUser['user_id'],
                    'Document verified (' . ($appUser['application_no'] ?? '') . ')',
                    'Your uploaded ' . str_replace('_', ' ', (string) $doc['doc_type']) . ' was verified by Accounting.' . ($imported > 0 ? ' It was added to your permanent file and will be reused next term.' : ''),
                    'success', '/my-enrollments'
                );
            }
        } catch (Throwable $e) {}
        set_flash('success', 'Upload verified.' . ($imported > 0 ? ' Imported to the permanent file for reuse.' : ''));
        redirect('/accounting/documents?tab=uploads');
    }

    /** Request a fresh upload for a per-application row (reason required). */
    public function requestUploadResubmission(array $params = []): void
    {
        Csrf::guard();
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? $_POST['review_reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            set_flash('error', 'A reason (min 5 characters) is required — the student will see it.');
            redirect('/accounting/documents?tab=uploads');
        }
        try {
            $doc = Database::one("SELECT * FROM enrollment_documents WHERE id = ? LIMIT 1", [$id]);
        } catch (Throwable $e) {
            $doc = null;
        }
        if ($doc === null) { set_flash('error', 'Upload not found.'); redirect('/accounting/documents?tab=uploads'); }
        try {
            Database::run(
                "UPDATE enrollment_documents SET status = 'needs_resubmission', review_reason = ? WHERE id = ?",
                [mb_substr($reason, 0, 500), $id]
            );
        } catch (Throwable $e) {
            set_flash('error', 'Document store not up to date — import database/patch_enrollment_form_docs.sql first.');
            redirect('/accounting/documents?tab=uploads');
        }
        try {
            if (!empty($doc['student_document_id'])) {
                Database::run(
                    "UPDATE student_documents SET status = 'needs_resubmission', review_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?",
                    [mb_substr($reason, 0, 500), (int) Auth::id(), (int) $doc['student_document_id']]
                );
            }
        } catch (Throwable $e) {}
        Database::insert('audit_logs', [
            'actor_user_id' => (int) Auth::id(), 'action' => 'enrollment_upload.resubmission_requested',
            'entity_type' => 'enrollment_documents', 'entity_id' => $id,
            'new_values_json' => json_encode(['reason' => mb_substr($reason, 0, 200)]),
            'ip_address' => client_ip(),
        ]);
        try {
            $appUser = Database::one(
                "SELECT ea.user_id FROM enrollment_applications ea WHERE ea.id = ? LIMIT 1",
                [(int) $doc['application_id']]
            );
            if ($appUser && !empty($appUser['user_id'])) {
                \App\Controllers\NotificationController::push(
                    (int) $appUser['user_id'],
                    'New upload needed: ' . str_replace('_', ' ', (string) $doc['doc_type']),
                    'Accounting requested a new ' . str_replace('_', ' ', (string) $doc['doc_type']) . ': ' . mb_substr($reason, 0, 200),
                    'warning', '/my-enrollments'
                );
            }
        } catch (Throwable $e) {}
        set_flash('success', 'Resubmission requested — student notified with your reason.');
        redirect('/accounting/documents?tab=uploads');
    }

    public function verifyDocument(array $params = []): void
    {
        Csrf::guard();
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        $doc = Database::one("SELECT * FROM student_documents WHERE id = ? LIMIT 1", [$id]);
        if ($doc === null) { set_flash('error', 'Document not found.'); redirect('/accounting/documents'); }
        Database::run(
            "UPDATE student_documents SET status = 'verified', review_reason = NULL, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?",
            [(int) Auth::id(), $id]
        );
        try {
            Database::run(
                "UPDATE enrollment_documents SET status = 'verified', is_verified = 1, verified_by = ?, verified_at = NOW() WHERE student_document_id = ?",
                [(int) Auth::id(), $id]
            );
        } catch (Throwable $e) {}
        Database::insert('audit_logs', [
            'actor_user_id' => (int) Auth::id(), 'action' => 'enrollment_doc.verified',
            'entity_type' => 'student_documents', 'entity_id' => $id, 'ip_address' => client_ip(),
        ]);
        try {
            $stuUser = Database::one("SELECT user_id FROM students WHERE id = ? LIMIT 1", [(int) $doc['student_id']]);
            if ($stuUser && !empty($stuUser['user_id'])) {
                \App\Controllers\NotificationController::push(
                    (int) $stuUser['user_id'],
                    'Document verified: ' . \App\Core\StudentDocs::label((string) $doc['doc_type']),
                    'Your ' . \App\Core\StudentDocs::label((string) $doc['doc_type']) . ' (v' . (int) $doc['version'] . ') was verified — it will be reused automatically in future enrollments.',
                    'success', '/student/enrollment-docs'
                );
            }
        } catch (Throwable $e) {}
        set_flash('success', 'Document verified — it will now be reused automatically.');
        redirect('/accounting/documents');
    }

    /**
     * Request a new version: reason required, student notified, history kept
     * (old version stays verified until the new one is verified).
     */
    public function requestResubmission(array $params = []): void
    {
        Csrf::guard();
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? $_POST['review_reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            set_flash('error', 'A reason (min 5 characters) is required — the student will see it.');
            redirect('/accounting/documents');
        }
        $doc = Database::one("SELECT * FROM student_documents WHERE id = ? LIMIT 1", [$id]);
        if ($doc === null) { set_flash('error', 'Document not found.'); redirect('/accounting/documents'); }
        Database::run(
            "UPDATE student_documents SET status = 'needs_resubmission', review_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?",
            [mb_substr($reason, 0, 500), (int) Auth::id(), $id]
        );
        try {
            Database::run(
                "UPDATE enrollment_documents SET status = 'needs_resubmission', review_reason = ? WHERE student_document_id = ?",
                [mb_substr($reason, 0, 500), $id]
            );
        } catch (Throwable $e) {}
        Database::insert('audit_logs', [
            'actor_user_id' => (int) Auth::id(), 'action' => 'enrollment_doc.resubmission_requested',
            'entity_type' => 'student_documents', 'entity_id' => $id,
            'new_values_json' => json_encode(['reason' => mb_substr($reason, 0, 200)]),
            'ip_address' => client_ip(),
        ]);
        try {
            $stuUser = Database::one("SELECT user_id FROM students WHERE id = ? LIMIT 1", [(int) $doc['student_id']]);
            if ($stuUser && !empty($stuUser['user_id'])) {
                \App\Controllers\NotificationController::push(
                    (int) $stuUser['user_id'],
                    'New document needed: ' . \App\Core\StudentDocs::label((string) $doc['doc_type']),
                    'Accounting requested an updated ' . \App\Core\StudentDocs::label((string) $doc['doc_type']) . ': ' . mb_substr($reason, 0, 200),
                    'warning', '/student/enrollment-docs'
                );
            }
        } catch (Throwable $e) {}
        set_flash('success', 'Resubmission requested — student notified with your reason. History preserved.');
        redirect('/accounting/documents');
    }
}
