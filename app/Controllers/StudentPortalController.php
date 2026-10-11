<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use Throwable;

/**
 * Student Portal Service Hub: ledger, grades, schedule, flowchart,
 * teacher evaluation, Certificate of Registration.
 *
 * All data comes from EXISTING tables (students, grades, student_charges,
 * payments, receipts, class_offerings, subjects...). Only teacher_evaluations
 * and student_class_registrations are new (see upgrade_student_portal.sql).
 * Student identity is ALWAYS derived from Auth (never from browser input).
 */
final class StudentPortalController extends Controller
{
    private function myStudent(): ?array
    {
        $s = Database::one('SELECT * FROM students WHERE user_id = ? LIMIT 1', [(int) Auth::id()]);
        if ($s) return $s;
        // Parent: resolve via guardian_students (optional ?child_id, ownership-checked; else first child).
        // An explicitly requested but unowned child is rejected (never silently
        // swapped to another child).
        if (Auth::is('parent')) {
            $g = Database::one('SELECT id FROM guardians WHERE user_id = ? LIMIT 1', [(int) Auth::id()]);
            if ($g) {
                $kid = (int) ($_GET['child_id'] ?? 0);
                if ($kid > 0) {
                    $owned = Database::one(
                        'SELECT s.* FROM guardian_students gs JOIN students s ON s.id = gs.student_id WHERE gs.guardian_id = ? AND s.id = ? LIMIT 1',
                        [(int) $g['id'], $kid]
                    );
                    if ($owned) return $owned;
                    set_flash('error', 'That child is not linked to your guardian account.');
                    redirect('/dashboard');
                }
                $first = Database::one(
                    'SELECT s.* FROM guardian_students gs JOIN students s ON s.id = gs.student_id WHERE gs.guardian_id = ? ORDER BY s.id LIMIT 1',
                    [(int) $g['id']]
                );
                if ($first) return $first;
            }
        }
        return null;
    }

    private function hasTable(string $t): bool
    {
        try {
            return (bool) Database::value("SHOW TABLES LIKE '{$t}'");
        } catch (Throwable $e) {
            return false;
        }
    }

    /* ------------------------------- LEDGER ------------------------------- */

    public function ledger(array $params = []): void
    {
        Auth::requireRole('student', 'parent');
        $s = $this->myStudent();
        if ($s === null) {
            set_flash('error', 'Student record not found.');
            redirect('/dashboard');
        }
        $sid = (int) $s['id'];
        $charges = Database::all(
            'SELECT sc.*, ft.name AS fee_name FROM student_charges sc
               LEFT JOIN fee_structures fs ON fs.id = sc.fee_structure_id
               LEFT JOIN fee_types ft ON ft.id = fs.fee_type_id
              WHERE sc.student_id = ? ORDER BY sc.charge_date DESC, sc.id DESC LIMIT 200',
            [$sid]
        );
        $payments = Database::all(
            "SELECT p.*, r.receipt_no FROM payments p LEFT JOIN receipts r ON r.payment_id = p.id
              WHERE p.student_id = ? ORDER BY p.created_at DESC LIMIT 200",
            [$sid]
        );
        $allocs = Database::all(
            'SELECT pa.*, sc.description FROM payment_allocations pa
               JOIN student_charges sc ON sc.id = pa.charge_id
              WHERE sc.student_id = ? ORDER BY pa.id DESC LIMIT 200',
            [$sid]
        );
        $balance = 0.0;
        try {
            $balance = (float) Database::value(
                "SELECT COALESCE((SELECT SUM(amount) FROM student_charges WHERE student_id = ?),0)
                        - COALESCE((SELECT SUM(pa.amount_applied) FROM payment_allocations pa
                                     JOIN payments p ON p.id = pa.payment_id WHERE p.status = 'paid'
                                      AND pa.charge_id IN (SELECT id FROM student_charges WHERE student_id = ?)),0)",
                [$sid, $sid]
            );
        } catch (Throwable $e) {
        }
        /* Payable filing for the Pay-now action (fail-open, read-only): the
           latest application owned by the current user, linked to this
           student, that can still receive money — awaiting_payment first,
           then approved-with-balance (installments / early settlement).
           The checkout's own ownership and status guards remain
           authoritative. */
        $payAppId = null;
        try {
            $payRow = Database::one(
                "SELECT id FROM enrollment_applications
                  WHERE user_id = ? AND status IN ('awaiting_payment','approved')
                    AND (student_id = ? OR applicant_child_id = ?)
                  ORDER BY (status = 'awaiting_payment') DESC, created_at DESC LIMIT 1",
                [(int) Auth::id(), $sid, $sid]
            );
            if (is_array($payRow) && isset($payRow['id'])) $payAppId = (int) $payRow['id'];
        } catch (Throwable $e) {
            try {
                $payRow = Database::one(
                    "SELECT id FROM enrollment_applications
                      WHERE user_id = ? AND status IN ('awaiting_payment','approved') AND student_id = ?
                      ORDER BY (status = 'awaiting_payment') DESC, created_at DESC LIMIT 1",
                    [(int) Auth::id(), $sid]
                );
                if (is_array($payRow) && isset($payRow['id'])) $payAppId = (int) $payRow['id'];
            } catch (Throwable $e2) {
                $payAppId = null;
            }
        }
        $this->view('student/ledger', [
            'title' => 'My Ledger', 'student' => $s, 'charges' => $charges,
            'payments' => $payments, 'allocations' => $allocs,
            'balance' => max(0, $balance), 'payAppId' => $payAppId,
        ]);
    }

    /* ------------------------------- GRADES ------------------------------- */

    public function grades(array $params = []): void
    {
        Auth::requireRole('student', 'parent');
        $s = $this->myStudent();
        if ($s === null) {
            set_flash('error', 'Student record not found.');
            redirect('/dashboard');
        }
        $ayId = (int) ($_GET['ay_id'] ?? 0);
        $sem = trim((string) ($_GET['semester'] ?? ''));
        $gradePeriodOpts = ['1st', '2nd', 'Summer'];
        try {
            if (class_exists(\App\Core\AcademicPeriod::class)) {
                foreach (\App\Core\AcademicPeriod::periodCodes(\App\Core\AcademicPeriod::BAND_BASIC) as $c) {
                    if (!in_array($c, $gradePeriodOpts, true)) $gradePeriodOpts[] = $c;
                }
            }
        } catch (Throwable $e) {
        }
        if (!in_array($sem, array_merge([''], $gradePeriodOpts), true)) $sem = '';
        // OFFICIAL released grades only: finalized grade sheets (never drafts,
        // never raw task scores). One row per subject × grading period.
        // INC rows (missing major work) are shown as INCOMPLETE, excluded
        // from the GWA until completed and re-finalized.
        $where = 'gs.status = \'finalized\' AND r.student_id = ? AND (r.grade IS NOT NULL OR r.remarks = \'INC\')';
        $bind = [(int) $s['id']];
        if ($ayId > 0) { $where .= ' AND gs.academic_year_id = ?'; $bind[] = $ayId; }
        if ($sem !== '') { $where .= ' AND gs.semester = ?'; $bind[] = $sem; }
        $rows = Database::all(
            "SELECT sc.subject_code, sc.subject_title, sc.units, sc.section_name,
                    ay.school_year, gs.semester, gp.code AS period_code,
                    r.grade AS final_grade, r.remarks
               FROM grade_sheet_rows r
               JOIN grade_sheets gs ON gs.id = r.sheet_id
               JOIN subject_classrooms sc ON sc.id = gs.classroom_id
               JOIN academic_years ay ON ay.id = gs.academic_year_id
               JOIN grade_periods gp ON gp.id = gs.period_id
              WHERE {$where}
              ORDER BY ay.school_year DESC, sc.subject_code ASC, gp.sort_order ASC",
            $bind
        );
        $scales = Database::all("SELECT * FROM grade_scales ORDER BY sort_order ASC");
        $totalUnits = 0.0;
        $wsum = 0.0;
        foreach ($rows as &$r) {
            $r['inc'] = $r['final_grade'] === null && trim((string) ($r['remarks'] ?? '')) === 'INC';
            if (!empty($r['inc'])) {
                $r['equivalent'] = 'Incomplete';
                $r['scale_remarks'] = 'INCOMPLETE';
                $r['passing'] = 0;
                continue;
            }
            $band = \App\Controllers\DocumentController::bandFor((float) $r['final_grade'], $scales);
            $r['equivalent'] = $band['equivalent'] ?? '';
            $r['scale_remarks'] = $band['remarks'] ?? '';
            $r['passing'] = (int) ($band['is_passing'] ?? ((float) $r['final_grade'] <= 3.00 ? 1 : 0));
            $totalUnits += (float) $r['units'];
            $wsum += (float) $r['final_grade'] * (float) $r['units'];
        }
        unset($r);
        $gwa = $totalUnits > 0 ? round($wsum / $totalUnits, 2) : null;
        $ays = Database::all('SELECT id, school_year FROM academic_years ORDER BY school_year DESC LIMIT 20');
        $this->view('student/grades', [
            'title' => 'My Grades', 'rows' => $rows, 'totalUnits' => $totalUnits,
            'gwa' => $gwa, 'academicYears' => $ays,
            'filters' => ['ay_id' => $ayId, 'semester' => $sem],
            'periodOptions' => $gradePeriodOpts,
        ]);
    }

    /* ------------------------------ SCHEDULE ------------------------------ */

    public function schedule(array $params = []): void
    {
        Auth::requireRole('student', 'parent');
        $s = $this->myStudent();
        if ($s === null) {
            set_flash('error', 'Student record not found.');
            redirect('/dashboard');
        }
        try {
            $rows = Database::all(
                "SELECT sc.subject_code, sc.subject_title, sc.section_name,
                        sc.room, sc.schedule AS schedule_note, sc.semester, ay.school_year,
                        TRIM(CONCAT(COALESCE(tu.first_name,''),' ',COALESCE(tu.last_name,''))) AS instructor
                   FROM classroom_members cm
                   JOIN subject_classrooms sc ON sc.id = cm.classroom_id
                   JOIN academic_years ay ON ay.id = sc.academic_year_id
                   JOIN teachers t ON t.id = sc.teacher_id
                   LEFT JOIN users tu ON tu.id = t.user_id
                  WHERE cm.student_id = ?
                  ORDER BY sc.subject_code ASC",
                [(int) $s['id']]
            );
        } catch (Throwable $e) {
            $rows = Database::all(
                "SELECT sc.subject_code, sc.subject_title, sc.section_name,
                        NULL AS room, NULL AS schedule_note, NULL AS semester, ay.school_year,
                        TRIM(CONCAT(COALESCE(tu.first_name,''),' ',COALESCE(tu.last_name,''))) AS instructor
                   FROM classroom_members cm
                   JOIN subject_classrooms sc ON sc.id = cm.classroom_id
                   JOIN academic_years ay ON ay.id = sc.academic_year_id
                   JOIN teachers t ON t.id = sc.teacher_id
                   LEFT JOIN users tu ON tu.id = t.user_id
                  WHERE cm.student_id = ?
                  ORDER BY sc.subject_code ASC",
                [(int) $s['id']]
            );
        }
        $this->view('student/schedule', ['title' => 'My Schedule', 'rows' => $rows, 'student' => $s]);
    }

    /* ------------------------------ FLOWCHART ------------------------------ */

    public function flowchart(array $params = []): void
    {
        Auth::requireRole('student', 'parent');
        $s = $this->myStudent();
        // Only subjects the student joined via invite code — never the whole school catalog.
        try {
            if ($s) {
                $flPerSel = 'NULL AS period_code,';
                try {
                    $hasFlPer = (bool) Database::value(
                        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subject_classrooms' AND COLUMN_NAME = 'period_code'"
                    );
                    if ($hasFlPer) $flPerSel = 'sc.period_code,';
                } catch (Throwable $e) {
                }
                $all = Database::all(
                    "SELECT sc.id, sc.subject_code AS code, sc.subject_title AS title, 3.0 AS units, sc.semester, {$flPerSel} sc.section_name
                       FROM classroom_members cm
                       JOIN subject_classrooms sc ON sc.id = cm.classroom_id
                      WHERE cm.student_id = ? AND sc.is_active = 1
                      ORDER BY sc.section_name, sc.semester, sc.subject_code LIMIT 200",
                    [(int) $s['id']]
                );
            } else {
                $all = [];
            }
        } catch (Throwable $e) { $all = []; }
        $joinedIds = [];
        if ($s) {
            try {
                $j = Database::all("SELECT classroom_id FROM classroom_members WHERE student_id = ?", [(int)$s['id']]);
                foreach ($j as $r) $joinedIds[(int)$r['classroom_id']] = true;
            } catch (Throwable $e) {}
        }
        $byLevel = [];
        if ($all !== []) {
            $groups = [];
            foreach ($all as $a) $groups[$a['section_name'] ?: 'Official Subjects'][] = $a;
            foreach ($groups as $sec => $subs) $byLevel[] = ['level' => ['code' => $sec, 'name' => $sec, 'category' => ''], 'subjects' => $subs];
        }
        $done = [];
        $enrolledCodes = [];
        foreach ($all as $a) if (isset($joinedIds[(int)$a['id']])) $enrolledCodes[(int)$a['id']] = true;
        $this->view('student/flowchart', [
            'title' => 'My Curriculum Flowchart', 'byLevel' => $byLevel,
            'done' => $done, 'enrolledCodes' => $enrolledCodes, 'student' => $s,
        ]);
    }

    /* ----------------------------- EVALUATION ----------------------------- */

    public function evaluations(array $params = []): void
    {
        Auth::requireRole('student');
        $s = $this->myStudent();
        if ($s === null) {
            set_flash('error', 'Student record not found.');
            redirect('/dashboard');
        }
        // Admin-controlled window: only allow evaluating while OPEN.
        $openPeriod = \App\Controllers\Admin\EvaluationController::currentOpenPeriod();
        $latestPeriod = $openPeriod ?? \App\Controllers\Admin\EvaluationController::latestPeriod();
        $eligible = [];
        if ($openPeriod !== null) {
            $eligible = Database::all(
                "SELECT sc.id AS offering_id, sc.subject_code, sc.subject_title,
                        TRIM(CONCAT(COALESCE(tu.first_name,''),' ',COALESCE(tu.last_name,''))) AS instructor,
                        (SELECT COUNT(*) FROM teacher_evaluations te WHERE te.student_id = ? AND te.class_offering_id = sc.id) AS evaluated
                   FROM classroom_members cm
                   JOIN subject_classrooms sc ON sc.id = cm.classroom_id
                   JOIN teachers t ON t.id = sc.teacher_id
                   LEFT JOIN users tu ON tu.id = t.user_id
                  WHERE cm.student_id = ? ORDER BY sc.subject_code",
                [(int) $s['id'], (int) $s['id']]
            );
        }
        $mine = [];
        if ($this->hasTable('teacher_evaluations')) {
            try {
            $mine = Database::all(
                'SELECT te.*, sc.subject_code FROM teacher_evaluations te
                   JOIN subject_classrooms sc ON sc.id = te.class_offering_id
                  WHERE te.student_id = ? ORDER BY te.created_at DESC',
                [(int) $s['id']]
            );
            } catch (Throwable $e) { $mine = []; }
        }
        $this->view('student/evaluations', [
            'title' => 'Online Teachers Evaluation', 'eligible' => $eligible, 'mine' => $mine,
            'openPeriod' => $openPeriod, 'closedPeriod' => $openPeriod === null ? $latestPeriod : null,
        ]);
    }

    public function submitEvaluation(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('student');
        $s = $this->myStudent();
        if ($s === null) {
            set_flash('error', 'Student record not found.');
            redirect('/dashboard');
        }
        // Block submissions unless admin opened a window.
        $openPeriod = \App\Controllers\Admin\EvaluationController::currentOpenPeriod();
        if ($openPeriod === null) {
            set_flash('error', 'Online Teachers Evaluation is currently closed.');
            redirect('/student/evaluations');
        }
        $offeringId = (int) ($_POST['class_offering_id'] ?? 0);
        $rating = (int) ($_POST['rating'] ?? 0);
        $comments = trim((string) ($_POST['comments'] ?? ''));
        if ($offeringId <= 0 || $rating < 1 || $rating > 5) {
            set_flash('error', 'Select a class and a rating from 1 to 5.');
            redirect('/student/evaluations');
        }
        // Must be joined to this official subject (server-side).
        $reg = Database::one(
            "SELECT sc.id, sc.teacher_id, sc.academic_year_id FROM classroom_members cm
               JOIN subject_classrooms sc ON sc.id = cm.classroom_id
              WHERE cm.student_id = ? AND cm.classroom_id = ? LIMIT 1",
            [(int) $s['id'], $offeringId]
        );
        if (!$reg) {
            set_flash('error', 'You can only evaluate classes you are registered in.');
            redirect('/student/evaluations');
        }
        try {
            // Self-heal: older installs may lack the table entirely.
            try {
                Database::pdo()->exec(
                    "CREATE TABLE IF NOT EXISTS `teacher_evaluations` (
                      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                      `student_id` BIGINT UNSIGNED NOT NULL,
                      `class_offering_id` INT UNSIGNED NOT NULL,
                      `teacher_id` BIGINT UNSIGNED DEFAULT NULL,
                      `academic_year_id` INT UNSIGNED DEFAULT NULL,
                      `evaluation_period_id` INT UNSIGNED DEFAULT NULL,
                      `rating` TINYINT UNSIGNED NOT NULL,
                      `comments` TEXT DEFAULT NULL,
                      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                      PRIMARY KEY (`id`),
                      UNIQUE KEY `uq_evaluation_once` (`student_id`,`class_offering_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                );
            } catch (Throwable $e) {
            }
            $row = [
                'student_id' => (int) $s['id'], 'class_offering_id' => $offeringId,
                'teacher_id' => $reg['teacher_id'] ? (int) $reg['teacher_id'] : null,
                'academic_year_id' => $reg['academic_year_id'] ? (int) $reg['academic_year_id'] : null,
                'rating' => $rating, 'comments' => $comments !== '' ? $comments : null,
                'evaluation_period_id' => (int) $openPeriod['id'],
            ];
            // Schema-drift guard: only insert columns that actually exist on
            // this install (older DBs lack evaluation_period_id, etc.).
            try {
                $cols = Database::all("SHOW COLUMNS FROM teacher_evaluations");
                $have = [];
                foreach ($cols as $c) $have[(string) $c['Field']] = true;
                foreach (array_keys($row) as $k) {
                    if (!isset($have[$k])) unset($row[$k]);
                }
            } catch (Throwable $e) {
            }
            Database::insert('teacher_evaluations', $row);
            Database::insert('audit_logs', [
                'actor_user_id' => (int) Auth::id(), 'action' => 'student.teacher_evaluated',
                'entity_type' => 'class_offerings', 'entity_id' => $offeringId,
                'ip_address' => client_ip(),
            ]);
            set_flash('success', 'Thank you! Your evaluation has been recorded.');
        } catch (Throwable $e) {
            if ($e instanceof \PDOException && ($e->errorInfo[1] ?? 0) === 1062) {
                set_flash('error', 'You have already evaluated this class.');
            } else {
                storage_log('evaluation failed: ' . $e->getMessage());
                set_flash('error', 'Could not save evaluation. Please try again.');
            }
        }
        redirect('/student/evaluations');
    }

}
