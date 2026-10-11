<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AcademicPeriod;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\EnrollmentCycle;
use Throwable;

/**
 * Registrar Office: academic records, grade verification, permits, documents.
 * Cannot touch financial transactions (payments enforced at route level).
 */
final class RegistrarController extends Controller
{
    private function guard(): void
    {
        Auth::requireRole('registrar', 'admin');
    }

    private static function hasTable(string $t): bool
    {
        try {
            return (bool) Database::value("SHOW TABLES LIKE '" . $t . "'");
        } catch (Throwable $e) {
            return false;
        }
    }

    /* ================= Student academic records ================= */

    public function students(array $params = []): void
    {
        $this->guard();
        $q = trim((string) ($_GET['q'] ?? ''));
        $level = (int) ($_GET['level'] ?? 0);
        $status = trim((string) ($_GET['status'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $per = 20;
        $off = ($page - 1) * $per;

        $where = [];
        $bind = [];
        if ($q !== '') {
            $where[] = "(u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR s.first_name LIKE ? OR s.middle_name LIKE ? OR s.last_name LIKE ? OR s.student_no LIKE ? OR CONCAT(u.first_name,' ',u.last_name) LIKE ? OR CONCAT(s.first_name,' ',s.last_name) LIKE ?)";
            $like = "%$q%";
            array_push($bind, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        }
        if ($level > 0) {
            $where[] = "s.grade_level_id = ?";
            $bind[] = $level;
        }
        if (in_array($status, ['enrolled', 'applicant', 'on_hold', 'graduated', 'transferred', 'dropped'], true)) {
            $where[] = "s.status = ?";
            $bind[] = $status;
        }
        $wsql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $rows = Database::all(
            "SELECT s.id, s.student_no, s.status,
                    COALESCE(NULLIF(u.first_name,''), s.first_name) AS first_name,
                    COALESCE(NULLIF(u.last_name,''), s.last_name) AS last_name,
                    u.email, (s.user_id IS NOT NULL) AS has_account,
                    gl.name AS level_name, sec.name AS section_name
               FROM students s
               LEFT JOIN users u ON u.id = s.user_id
               LEFT JOIN grade_levels gl ON gl.id = s.grade_level_id
               LEFT JOIN sections sec ON sec.id = s.section_id
               $wsql ORDER BY s.id DESC LIMIT $per OFFSET $off",
            $bind
        );
        $total = (int) Database::value(
            "SELECT COUNT(*) FROM students s LEFT JOIN users u ON u.id = s.user_id $wsql",
            $bind
        );
        $levels = Database::all("SELECT id, name FROM grade_levels ORDER BY sort_order LIMIT 30");
        $this->view('registrar/students_index', [
            'title' => 'Student Academic Records',
            'rows' => $rows, 'q' => $q, 'level' => $level, 'statusFilter' => $status,
            'levels' => $levels, 'page' => $page, 'per' => $per, 'total' => $total,
        ]);
    }

    public function studentShow(array $params = []): void
    {
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        $s = Database::one(
            "SELECT s.*, u.email, u.phone,
                    COALESCE(NULLIF(u.first_name,''), s.first_name) AS first_name,
                    COALESCE(NULLIF(u.last_name,''), s.last_name) AS last_name,
                    (s.user_id IS NOT NULL) AS has_account,
                    gl.name AS level_name, p.code AS program_code, p.name AS program_name, sec.name AS section_name
               FROM students s
               LEFT JOIN users u ON u.id = s.user_id
               LEFT JOIN grade_levels gl ON gl.id = s.grade_level_id
               LEFT JOIN programs p ON p.id = s.program_id
               LEFT JOIN sections sec ON sec.id = s.section_id
              WHERE s.id = ? LIMIT 1",
            [$id]
        );
        if ($s === null) {
            http_response_code(404);
            exit('Student not found');
        }
        $hasEaPeriodReg = false;
        try {
            $hasEaPeriodReg = (bool) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollment_applications' AND COLUMN_NAME = 'period_code'"
            );
        } catch (Throwable $e) {
        }
        $hasScPeriodReg = false;
        try {
            $hasScPeriodReg = (bool) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subject_classrooms' AND COLUMN_NAME = 'period_code'"
            );
        } catch (Throwable $e) {
        }
        $scPerSelShow = $hasScPeriodReg ? 'sc.period_code,' : 'NULL AS period_code,';
        $enrolled = Database::all(
            "SELECT sc.subject_code, sc.subject_title, sc.section_name, sc.semester, {$scPerSelShow} ay.school_year,
                    tu.first_name AS t_first, tu.last_name AS t_last
               FROM classroom_members cm
               JOIN subject_classrooms sc ON sc.id = cm.classroom_id
               JOIN academic_years ay ON ay.id = sc.academic_year_id
               JOIN teachers t ON t.id = sc.teacher_id
               LEFT JOIN users tu ON tu.id = t.user_id
              WHERE cm.student_id = ? ORDER BY ay.school_year DESC, sc.subject_code ASC LIMIT 100",
            [$id]
        );
        $grades = Database::all(
            "SELECT cts.id, cts.score, cts.status, cts.review_status, cts.feedback, cts.submitted_at, cts.graded_at,
                    ct.title, sc.subject_code, sc.subject_title, sc.semester, ay.school_year,
                    TRIM(CONCAT(COALESCE(tu.first_name,''),' ',COALESCE(tu.last_name,''))) AS teacher
               FROM classroom_task_submissions cts
               JOIN classroom_tasks ct ON ct.id = cts.task_id
               JOIN subject_classrooms sc ON sc.id = ct.classroom_id
               JOIN academic_years ay ON ay.id = sc.academic_year_id
               JOIN teachers t ON t.id = sc.teacher_id
               LEFT JOIN users tu ON tu.id = t.user_id
              WHERE cts.student_id = ? ORDER BY ay.school_year DESC, sc.subject_code ASC LIMIT 200",
            [$id]
        );
        $gwa = null;
        $scored = array_filter($grades, static fn($g) => $g['score'] !== null && is_numeric($g['score']));
        if ($scored !== []) {
            $gwa = round(array_sum(array_map(static fn($g) => (float) $g['score'], $scored)) / count($scored), 2);
        }
        $eaPerSel = $hasEaPeriodReg ? 'ea.period_code,' : 'NULL AS period_code,';
        $enrollHist = Database::all(
            "SELECT ea.id, ea.application_no, ea.status, ea.semester, {$eaPerSel} ea.created_at, ay.school_year, gl.name AS level_name
               FROM enrollment_applications ea
               JOIN academic_years ay ON ay.id = ea.academic_year_id
               JOIN grade_levels gl ON gl.id = ea.grade_level_applied_id
               WHERE ea.student_id = ? OR ea.user_id = ? ORDER BY ea.created_at DESC LIMIT 20",
            [$id, (int) ($s['user_id'] ?? 0)]
        );
        $docs = [];
        if (self::hasTable('academic_documents')) {
            $docs = Database::all("SELECT * FROM academic_documents WHERE student_id = ? ORDER BY created_at DESC LIMIT 20", [$id]);
        }
        $this->view('registrar/students_show', [
            'title' => 'Academic Record — ' . ($s['student_no'] ?? ('#' . $id)),
            'student' => $s, 'enrolled' => $enrolled, 'grades' => $grades,
            'gwa' => $gwa, 'enrollHist' => $enrollHist, 'docs' => $docs,
        ]);
    }

    /**
     * Context-aware academic-period filter values: legacy semesters plus the
     * Admin-configured basic-ed quarters/terms. Never trust raw input.
     * @return string[]
     */
    public static function periodFilterOptions(): array
    {
        $opts = ['1st', '2nd', 'Summer'];
        try {
            if (class_exists(\App\Core\AcademicPeriod::class)) {
                foreach (\App\Core\AcademicPeriod::periodCodes(\App\Core\AcademicPeriod::BAND_BASIC) as $c) {
                    if (!in_array($c, $opts, true)) $opts[] = $c;
                }
            }
        } catch (Throwable $e) {
        }
        return $opts;
    }

    /** Effective-period match expression for subject_classrooms (guarded). */
    private static function scPeriodExpr(): string
    {
        try {
            $has = (bool) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subject_classrooms' AND COLUMN_NAME = 'period_code'"
            );
            if ($has) return 'COALESCE(sc.period_code, sc.semester)';
        } catch (Throwable $e) {
        }
        return 'sc.semester';
    }

    /* ================= Grade verification ================= */

    public function grades(array $params = []): void
    {
        $this->guard();
        $review = trim((string) ($_GET['review'] ?? ''));
        $teacher = trim((string) ($_GET['teacher'] ?? ''));
        $subject = trim((string) ($_GET['subject'] ?? ''));
        $semester = trim((string) ($_GET['semester'] ?? ''));
        if (!in_array($review, ['submitted', 'reviewed', 'approved', 'returned', 'draft', ''], true)) $review = '';
        if (!in_array($semester, array_merge([''], self::periodFilterOptions()), true)) $semester = '';

        $where = ["cts.status IN ('submitted','late','graded','returned')"];
        $bind = [];
        if ($review !== '') {
            $where[] = "cts.review_status = ?";
            $bind[] = $review;
        } else {
            $where[] = "cts.review_status IN ('submitted','reviewed')";
        }
        if ($teacher !== '') {
            $where[] = "(tu.first_name LIKE ? OR tu.last_name LIKE ? OR CONCAT(tu.first_name,' ',tu.last_name) LIKE ?)";
            $like = "%$teacher%";
            array_push($bind, $like, $like, $like);
        }
        if ($subject !== '') {
            $where[] = "(sc.subject_code LIKE ? OR sc.subject_title LIKE ?)";
            $like = "%$subject%";
            array_push($bind, $like, $like);
        }
        if ($semester !== '') {
            $where[] = self::scPeriodExpr() . " = ?";
            $bind[] = $semester;
        }
        $wsql = 'WHERE ' . implode(' AND ', $where);
        $rows = Database::all(
            "SELECT cts.id, cts.score, cts.status, cts.review_status, cts.submitted_at,
                    ct.title, sc.subject_code, sc.subject_title, sc.section_name, sc.semester, ay.school_year,
                    s.student_no, CONCAT(u.first_name,' ',u.last_name) AS student_name,
                    TRIM(CONCAT(COALESCE(tu.first_name,''),' ',COALESCE(tu.last_name,''))) AS teacher_name
               FROM classroom_task_submissions cts
               JOIN classroom_tasks ct ON ct.id = cts.task_id
               JOIN subject_classrooms sc ON sc.id = ct.classroom_id
               JOIN academic_years ay ON ay.id = sc.academic_year_id
               JOIN students s ON s.id = cts.student_id
               JOIN users u ON u.id = s.user_id
               JOIN teachers t ON t.id = sc.teacher_id
               LEFT JOIN users tu ON tu.id = t.user_id
               $wsql ORDER BY cts.submitted_at DESC LIMIT 100",
            $bind
        );
        $counts = [];
        foreach (['submitted', 'reviewed', 'approved', 'returned'] as $st) {
            $counts[$st] = (int) Database::value("SELECT COUNT(*) FROM classroom_task_submissions WHERE review_status = ? AND status IN ('submitted','late','graded','returned')", [$st]);
        }
        $this->view('registrar/grades_index', [
            'title' => 'Grade Verification', 'rows' => $rows, 'counts' => $counts,
            'filters' => ['review' => $review === '' ? 'submitted' : $review, 'teacher' => $teacher, 'subject' => $subject, 'semester' => $semester],
            'periodOptions' => self::periodFilterOptions(),
        ]);
    }

    public function reviewGrade(array $params = []): void
    {
        Csrf::guard();
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        $action = trim((string) ($_POST['action'] ?? ''));
        $remarks = trim((string) ($_POST['remarks'] ?? ''));
        if (!in_array($action, ['reviewed', 'approved', 'returned'], true)) {
            set_flash('error', 'Invalid review action.');
            redirect('/registrar/grades');
        }
        if ($action === 'returned' && mb_strlen($remarks) < 5) {
            set_flash('error', 'Returning a grade requires a correction reason (min 5 characters).');
            redirect('/registrar/grades');
        }
        $sub = Database::one("SELECT * FROM classroom_task_submissions WHERE id = ? LIMIT 1", [$id]);
        if ($sub === null) {
            set_flash('error', 'Grade record not found.');
            redirect('/registrar/grades');
        }
        if (($sub['review_status'] ?? '') === 'approved' && $action !== 'returned') {
            set_flash('error', 'This grade is already Approved and locked. Return it first to request correction.');
            redirect('/registrar/grades');
        }
        if (!in_array($sub['status'] ?? '', ['submitted', 'late', 'graded', 'returned'], true)) {
            set_flash('error', 'Only teacher-submitted work can be reviewed.');
            redirect('/registrar/grades');
        }
        $from = (string) ($sub['review_status'] ?? 'submitted');
        try {
            Database::transaction(function () use ($id, $action, $remarks, $from) {
                Database::run(
                    "UPDATE classroom_task_submissions SET review_status = ?, reviewed_by = ?, reviewed_at = NOW(), review_remarks = ? WHERE id = ?",
                    [$action, (int) Auth::id(), $remarks !== '' ? $remarks : null, $id]
                );
                Database::insert('grade_reviews', [
                    'submission_id' => $id, 'from_status' => $from, 'to_status' => $action,
                    'action' => $action, 'remarks' => $remarks !== '' ? $remarks : null,
                    'actor_user_id' => (int) Auth::id(),
                ]);
                Database::insert('audit_logs', [
                    'actor_user_id' => (int) Auth::id(), 'action' => 'grade.' . $action,
                    'entity_type' => 'classroom_task_submissions', 'entity_id' => $id,
                    'ip_address' => client_ip(),
                ]);
            });
            try {
                $stu = Database::one(
                    "SELECT s.user_id FROM classroom_task_submissions cts JOIN students s ON s.id = cts.student_id WHERE cts.id = ? LIMIT 1",
                    [$id]
                );
                if ($stu && !empty($stu['user_id'])) {
                    \App\Controllers\NotificationController::push(
                        (int) $stu['user_id'],
                        'Grade ' . ucfirst($action) . ' by Registrar',
                        'Your submission #' . $id . ' was marked ' . $action . ($remarks !== '' ? ': ' . $remarks : '.'),
                        $action === 'approved' ? 'success' : 'warning',
                        '/student/grades'
                    );
                }
            } catch (Throwable $e) {
            }
            set_flash('success', 'Grade #' . $id . ' marked as ' . ucfirst($action) . '.');
        } catch (Throwable $e) {
            storage_log('registrar reviewGrade failed ' . $id . ': ' . $e->getMessage());
            set_flash('error', 'Review failed: ' . $e->getMessage());
        }
        redirect('/registrar/grades');
    }

    /* ================= Permits (registrar stage) ================= */

    public function permits(array $params = []): void
    {
        $this->guard();
        $status = trim((string) ($_GET['status'] ?? 'approved_accounting'));
        $allowed = ['pending_accounting', 'approved_accounting', 'approved_registrar', 'fully_approved', 'rejected', 'all'];
        if (!in_array($status, $allowed, true)) $status = 'approved_accounting';
        $where = $status === 'all' ? '' : 'WHERE ep.status = ?';
        $args = $status === 'all' ? [] : [$status];
        $rows = Database::all(
            "SELECT ep.*, s.student_no, u.first_name, u.last_name, u.email, ay.school_year
               FROM exam_permits ep
               JOIN students s ON s.id = ep.student_id
               JOIN users u ON u.id = s.user_id
               JOIN academic_years ay ON ay.id = ep.academic_year_id
               $where ORDER BY ep.requested_at DESC LIMIT 100",
            $args
        );
        $counts = [];
        foreach (['pending_accounting', 'approved_accounting', 'approved_registrar', 'fully_approved', 'rejected'] as $st) {
            $counts[$st] = (int) Database::value("SELECT COUNT(*) FROM exam_permits WHERE status = ?", [$st]);
        }
        $counts['all'] = (int) Database::value("SELECT COUNT(*) FROM exam_permits");
        $this->view('registrar/permits_index', [
            'title' => 'Academic Permits — Registrar', 'rows' => $rows, 'status' => $status, 'counts' => $counts,
        ]);
    }

    public function approvePermit(array $params = []): void
    {
        Csrf::guard();
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        $permit = Database::one("SELECT * FROM exam_permits WHERE id = ? LIMIT 1", [$id]);
        if ($permit === null || $permit['status'] !== 'approved_accounting') {
            set_flash('error', 'Permit must be approved by Accounting first (Stage 1).');
            redirect('/registrar/permits');
        }
        Database::run("UPDATE exam_permits SET status = 'approved_registrar' WHERE id = ?", [$id]);
        Database::insert('permit_approvals', [
            'permit_id' => $id, 'stage' => 'registrar', 'status' => 'approved',
            'approver_user_id' => Auth::id(), 'remarks' => trim((string) ($_POST['remarks'] ?? '')) ?: null,
        ]);
        Database::insert('audit_logs', [
            'actor_user_id' => Auth::id(), 'action' => 'permit.approved_registrar',
            'entity_type' => 'exam_permits', 'entity_id' => $id, 'ip_address' => client_ip(),
        ]);
        try {
            $stuUser = Database::one("SELECT s.user_id FROM students s WHERE s.id = ? LIMIT 1", [(int) $permit['student_id']]);
            if ($stuUser && !empty($stuUser['user_id'])) {
                \App\Controllers\NotificationController::push((int) $stuUser['user_id'], 'Registrar approved: ' . $permit['permit_no'], $permit['exam_period'] . ' permit passed Registrar — awaiting teacher verification.', 'success', '/student/permits');
            }
        } catch (Throwable $e) {
        }
        \App\Controllers\NotificationController::pushToTeachers('Permit ready to verify: ' . $permit['permit_no'], $permit['exam_period'] . ' permit approved by Registrar.', 'info', '/teacher/permits');
        set_flash('success', 'Permit ' . $permit['permit_no'] . ' approved (Registrar Stage 2/3).');
        redirect('/registrar/permits');
    }

    public function rejectPermit(array $params = []): void
    {
        Csrf::guard();
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? $_POST['remarks'] ?? ''));
        if (mb_strlen($reason) < 5) {
            set_flash('error', 'A rejection reason (min 5 characters) is required.');
            redirect('/registrar/permits');
        }
        $permit = Database::one("SELECT * FROM exam_permits WHERE id = ? LIMIT 1", [$id]);
        if ($permit === null || !in_array($permit['status'], ['approved_accounting', 'pending_accounting'], true)) {
            set_flash('error', 'Permit cannot be rejected in its current state.');
            redirect('/registrar/permits');
        }
        Database::run("UPDATE exam_permits SET status = 'rejected', rejected_reason = ? WHERE id = ?", [$reason, $id]);
        Database::insert('permit_approvals', [
            'permit_id' => $id, 'stage' => 'registrar', 'status' => 'rejected',
            'approver_user_id' => Auth::id(), 'remarks' => $reason,
        ]);
        Database::insert('audit_logs', [
            'actor_user_id' => Auth::id(), 'action' => 'permit.rejected_registrar',
            'entity_type' => 'exam_permits', 'entity_id' => $id, 'ip_address' => client_ip(),
        ]);
        try {
            $stuUser = Database::one("SELECT s.user_id FROM students s WHERE s.id = ? LIMIT 1", [(int) $permit['student_id']]);
            if ($stuUser && !empty($stuUser['user_id'])) {
                \App\Controllers\NotificationController::push((int) $stuUser['user_id'], 'Permit returned by Registrar: ' . $permit['permit_no'], $reason, 'danger', '/student/permits');
            }
        } catch (Throwable $e) {
        }
        set_flash('success', 'Permit ' . $permit['permit_no'] . ' rejected with reason logged.');
        redirect('/registrar/permits');
    }

    /* ================= Academic documents ================= */

    public function documents(array $params = []): void
    {
        $this->guard();
        $status = trim((string) ($_GET['status'] ?? 'pending'));
        if (!in_array($status, ['pending', 'approved', 'ready', 'released', 'rejected', 'cancelled', 'all'], true)) $status = 'pending';
        $type = trim((string) ($_GET['type'] ?? ''));
        $validTypes = ['transcript', 'enrollment_cert', 'grades_cert', 'history', 'grade_slip', 'registration_form', 'grade_equivalency', 'other'];
        if ($type !== '' && !in_array($type, $validTypes, true)) $type = '';
        $where = [];
        $args = [];
        if ($status !== 'all') {
            $where[] = 'ad.status = ?';
            $args[] = $status;
        }
        if ($type !== '') {
            $where[] = 'ad.doc_type = ?';
            $args[] = $type;
        }
        $wsql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $rows = Database::all(
            "SELECT ad.*, s.student_no, u.first_name, u.last_name, ay.school_year
               FROM academic_documents ad
               JOIN students s ON s.id = ad.student_id
               JOIN users u ON u.id = s.user_id
               LEFT JOIN academic_years ay ON ay.id = ad.academic_year_id
               $wsql ORDER BY ad.created_at DESC LIMIT 100",
            $args
        );
        $counts = [];
        foreach (['pending', 'approved', 'ready', 'released', 'rejected'] as $st) {
            $counts[$st] = (int) Database::value("SELECT COUNT(*) FROM academic_documents WHERE status = ?", [$st]);
        }
        $counts['all'] = (int) Database::value("SELECT COUNT(*) FROM academic_documents");
        $this->view('registrar/documents_index', [
            'title' => 'Academic Documents', 'rows' => $rows, 'status' => $status,
            'counts' => $counts, 'typeFilter' => $type,
        ]);
    }

    public function issueDocument(array $params = []): void
    {
        Csrf::guard();
        $this->guard();
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $docType = trim((string) ($_POST['doc_type'] ?? ''));
        $purpose = trim((string) ($_POST['purpose'] ?? ''));
        if ($studentId <= 0 || !in_array($docType, ['transcript', 'enrollment_cert', 'grades_cert', 'history', 'grade_slip', 'registration_form', 'grade_equivalency', 'other'], true)) {
            set_flash('error', 'Select a student and a valid document type.');
            redirect('/registrar/documents');
        }
        $student = Database::one("SELECT id FROM students WHERE id = ? LIMIT 1", [$studentId]);
        if ($student === null) {
            set_flash('error', 'Student not found.');
            redirect('/registrar/documents');
        }
        $docNo = 'DOC-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
        try {
            $docId = Database::insert('academic_documents', [
                'doc_no' => $docNo, 'student_id' => $studentId, 'doc_type' => $docType,
                'purpose' => $purpose !== '' ? $purpose : null, 'status' => 'approved',
                'processed_by' => (int) Auth::id(), 'remarks' => 'Issued by Registrar',
            ]);
            Database::insert('audit_logs', [
                'actor_user_id' => (int) Auth::id(), 'action' => 'document.issued',
                'entity_type' => 'academic_documents', 'entity_id' => $docId, 'ip_address' => client_ip(),
            ]);
            set_flash('success', 'Document ' . $docNo . ' issued (' . $docType . '). Mark as Released when handed to student.');
        } catch (Throwable $e) {
            storage_log('registrar issueDocument failed: ' . $e->getMessage());
            set_flash('error', 'Could not issue document: ' . $e->getMessage());
        }
        redirect('/registrar/documents');
    }

    public function releaseDocument(array $params = []): void
    {
        Csrf::guard();
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        $action = trim((string) ($_POST['action'] ?? 'released'));
        if (!in_array($action, ['approved', 'ready', 'released', 'rejected'], true)) $action = 'released';
        $doc = Database::one("SELECT * FROM academic_documents WHERE id = ? LIMIT 1", [$id]);
        if ($doc === null) {
            set_flash('error', 'Document not found.');
            redirect('/registrar/documents');
        }
        $from = (string) $doc['status'];
        $flow = [
            'approved' => ['pending'],
            'ready' => ['approved', 'pending'],
            'released' => ['ready', 'approved'],
            'rejected' => ['pending', 'approved', 'ready'],
        ];
        if (!in_array($from, $flow[$action] ?? [], true)) {
            set_flash('error', 'Cannot mark ' . $action . ' from ' . $from . '.');
            redirect('/registrar/documents/' . $id);
        }
        $remarks = trim((string) ($_POST['remarks'] ?? $_POST['reason'] ?? ''));
        if ($action === 'rejected' && mb_strlen($remarks) < 5) {
            set_flash('error', 'Rejecting a document requires a reason (min 5 characters).');
            redirect('/registrar/documents/' . $id);
        }
        Database::run(
            "UPDATE academic_documents SET status = ?, processed_by = ?, released_at = " . ($action === 'released' ? 'NOW()' : 'NULL') . ", remarks = ? WHERE id = ?",
            [$action, (int) Auth::id(), $remarks !== '' ? mb_substr($remarks, 0, 500) : null, $id]
        );
        Database::insert('audit_logs', [
            'actor_user_id' => (int) Auth::id(), 'action' => 'document.' . $action,
            'entity_type' => 'academic_documents', 'entity_id' => $id, 'ip_address' => client_ip(),
        ]);
        try {
            $stuUser = Database::one("SELECT user_id FROM students WHERE id = ? LIMIT 1", [(int) $doc['student_id']]);
            if ($stuUser && !empty($stuUser['user_id'])) {
                $note = match ($action) {
                    'rejected' => 'Rejected: ' . mb_substr($remarks, 0, 200) . ' — you may request again.',
                    'released' => 'Released — open it to view/print.',
                    'ready' => 'Ready for release.',
                    default => 'Approved — being prepared for release.',
                };
                \App\Controllers\NotificationController::push(
                    (int) $stuUser['user_id'], 'Document ' . $action . ': ' . $doc['doc_no'], $note,
                    $action === 'rejected' ? 'danger' : 'success', '/student/documents/' . $id
                );
            }
        } catch (Throwable $e) {
        }
        set_flash('success', 'Document ' . $doc['doc_no'] . ' marked as ' . ucfirst($action) . '.');
        redirect('/registrar/documents/' . $id);
    }

    public function documentsShow(array $params = []): void
    {
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        $doc = Database::one(
            "SELECT ad.*, s.student_no, u.first_name, u.last_name, u.email, ay.school_year,
                    gl.name AS level_name, p.code AS program_code
               FROM academic_documents ad
               JOIN students s ON s.id = ad.student_id
               JOIN users u ON u.id = s.user_id
               LEFT JOIN academic_years ay ON ay.id = ad.academic_year_id
               LEFT JOIN grade_levels gl ON gl.id = s.grade_level_id
               LEFT JOIN programs p ON p.id = s.program_id
              WHERE ad.id = ? LIMIT 1",
            [$id]
        );
        if ($doc === null) {
            http_response_code(404);
            exit('Document not found');
        }
        $history = Database::all(
            "SELECT * FROM academic_documents WHERE student_id = ? AND doc_type = ? AND id <> ?
              ORDER BY created_at DESC LIMIT 10",
            [(int) $doc['student_id'], $doc['doc_type'], $id]
        );
        $this->view('registrar/documents_show', [
            'title' => 'Request ' . $doc['doc_no'], 'doc' => $doc, 'history' => $history,
        ]);
    }

    /* ================= Read-only enrollment + clearance context ================= */

    public function approveEnrollment(array $params = []): void
    {
        Csrf::guard();
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        $app = Database::one("SELECT * FROM enrollment_applications WHERE id = ? LIMIT 1", [$id]);
        if ($app === null || !in_array($app['status'], ['accounting_approved', 'pending', 'action_required'], true)) {
            set_flash('error', 'Application must be financially verified (Accounting) before academic approval.');
            redirect('/registrar/enrollments');
        }
        if (($app['status'] ?? '') === 'pending') {
            set_flash('error', 'Accounting must verify financial requirements first (pending → accounting review).');
            redirect('/registrar/enrollments');
        }
        $planTotal = class_exists(\App\Core\Tuition::class) ? \App\Core\Tuition::total() : 10000.00;
        // Never charge the legacy 1500.00 column default (isset() is always
        // true for it) — effectiveDue() falls back to the plan total instead.
        $amount = class_exists(\App\Core\Tuition::class) ? \App\Core\Tuition::effectiveDue($app['amount_due'] ?? null) : $planTotal;
        if ($amount <= 0) $amount = $planTotal;
        try {
            Database::transaction(function () use ($app, $id, $amount) {
                $studentId = $app['student_id'];
                if ($studentId === null) {
                    // Tagged child filings belong to that child's row — never
                    // to a sibling sharing the filer's account.
                    $taggedChild = null;
                    if (!empty($app['applicant_child_id'])) {
                        $taggedChild = Database::one("SELECT id FROM students WHERE id = ? LIMIT 1", [(int) $app['applicant_child_id']]);
                    }
                    if ($taggedChild !== null) {
                        $studentId = (int) $taggedChild['id'];
                    } else {
                        if (!empty($app['applicant_child_id'])) {
                            throw new \RuntimeException('The linked child record for this application is missing. Contact the registrar — nothing was changed.');
                        }
                    $existing = $app['user_id'] !== null ? Database::one("SELECT id, student_no FROM students WHERE user_id = ? LIMIT 1", [(int) $app['user_id']]) : null;
                    if ($existing !== null) {
                        // PERMANENT NUMBER: reuse the row (and its number) — never duplicate.
                        $studentId = (int) $existing['id'];
                    } else {
                        $studentId = Database::insert('students', [
                            'user_id' => $app['user_id'], 'grade_level_id' => $app['grade_level_applied_id'],
                            'program_id' => $app['program_id'], 'status' => 'applicant', 'admission_date' => date('Y-m-d'),
                        ]);
                    }
                    }
                }
                // Assign a permanent number once (same scheme as Admin approval);
                // existing numbers are never overwritten.
                $snoRow = Database::one("SELECT student_no, grade_level_id FROM students WHERE id = ? LIMIT 1", [$studentId]);
                if ($snoRow && empty($snoRow['student_no'])) {
                    $lvl = (int) ($snoRow['grade_level_id'] ?? $app['grade_level_applied_id']);
                    Database::run("UPDATE students SET student_no = ? WHERE id = ?", [self::generateStudentNo($lvl), $studentId]);
                }
                $transactionRef = 'ENR-' . $app['application_no'] . '-' . strtoupper(bin2hex(random_bytes(2)));
                $payId = Database::insert('payments', [
                    'transaction_ref' => $transactionRef, 'student_id' => $studentId,
                    'enrollment_application_id' => (int) $app['id'], 'amount' => $amount,
                    'currency' => 'PHP', 'payment_method' => 'payrex', 'channel' => 'online',
                    'status' => 'pending', 'initiated_by' => (int) Auth::id(), 'ip_address' => client_ip(),
                ]);
                Database::run(
                    "UPDATE enrollment_applications SET status = 'awaiting_payment', student_id = ?, payment_id = ?, amount_due = ?, reviewed_by = ?, reviewed_at = NOW(), remarks = NULL WHERE id = ?",
                    [$studentId, $payId, $amount, (int) Auth::id(), $id]
                );
            });
            Database::insert('audit_logs', [
                'actor_user_id' => (int) Auth::id(), 'action' => 'enrollment.registrar_approved',
                'entity_type' => 'enrollment_applications', 'entity_id' => $id, 'ip_address' => client_ip(),
            ]);
            $kidName = class_exists(\App\Core\GuardianLinks::class) ? \App\Core\GuardianLinks::applicantChildName($app) : null;
            $kidPrefix = $kidName !== null ? $kidName . ': ' : '';
            \App\Controllers\NotificationController::push((int) $app['user_id'], 'Pay your enrollment: ' . $app['application_no'], $kidPrefix . 'Registrar approved academics — pay via PayRex to complete enrollment.', 'payment', '/my-enrollments');
            set_flash('success', 'Academically approved — payment request sent. Student must pay to be fully enrolled.');
        } catch (Throwable $e) {
            storage_log('registrar approveEnrollment failed ' . $id . ': ' . $e->getMessage());
            set_flash('error', 'Failed: ' . $e->getMessage());
        }
        redirect('/registrar/enrollments');
    }

    /** Permanent-number generator (same ranges as Admin approval; assigned once). */
    private static function generateStudentNo(int $gradeLevelId): string
    {
        $level = Database::one("SELECT category FROM grade_levels WHERE id = ? LIMIT 1", [$gradeLevelId]);
        $cat = $level['category'] ?? 'college';
        $base = match ($cat) {
            'kindergarten' => 1000000,
            'elementary'   => 1100000,
            'junior_high'  => 1200000,
            'senior_high'  => 1300000,
            'college'      => 2000000,
            default        => 3000000,
        };
        $max = Database::value("SELECT MAX(CAST(student_no AS UNSIGNED)) FROM students WHERE student_no REGEXP '^[0-9]+$' AND CAST(student_no AS UNSIGNED) BETWEEN ? AND ?", [$base, $base + 999999]);
        $next = $max ? ((int) $max + 1) : $base;
        if ($next > $base + 999999) $next = $base + (int) (microtime(true) * 1000) % 900000;
        $tries = 0;
        while (Database::one("SELECT id FROM students WHERE student_no = ? LIMIT 1", [(string) $next]) && $tries < 5) {
            $next++;
            $tries++;
        }
        return (string) $next;
    }

    public function enrollments(array $params = []): void
    {
        $this->guard();
        $status = trim((string) ($_GET['status'] ?? 'all'));
        $allowed = ['pending', 'accounting_approved', 'awaiting_payment', 'approved', 'rejected', 'action_required', 'all'];
        if (!in_array($status, $allowed, true)) $status = 'all';
        $where = $status === 'all' ? '' : 'WHERE ea.status = ?';
        $args = $status === 'all' ? [] : [$status];
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
            "SELECT ea.*, gl.name AS level_name, ay.school_year, CONCAT(u.first_name,' ',u.last_name) AS applicant,
                    {$childSel}
               FROM enrollment_applications ea
               JOIN grade_levels gl ON gl.id = ea.grade_level_applied_id
               JOIN academic_years ay ON ay.id = ea.academic_year_id
               LEFT JOIN users u ON u.id = ea.user_id
               {$childJoin}
               $where ORDER BY ea.created_at DESC LIMIT 100",
            $args
        );
        $this->view('registrar/enrollments_index', ['title' => 'Enrollment Records (Academic View)', 'rows' => $rows, 'status' => $status]);
    }

    public function clearances(array $params = []): void
    {
        $this->guard();
        $rows = Database::all(
            "SELECT c.*, s.student_no, u.first_name, u.last_name, ay.school_year
               FROM clearances c
               JOIN students s ON s.id = c.student_id
               JOIN users u ON u.id = s.user_id
               JOIN academic_years ay ON ay.id = c.academic_year_id
              ORDER BY c.created_at DESC LIMIT 100"
        );
        $this->view('registrar/clearances_index', ['title' => 'Clearance Review (Academic)', 'rows' => $rows]);
    }

    /* ============ Re-enrollment cycle report ============ */

    public function reenrollment(array $params = []): void
    {
        $this->guard();
        // Awaiting academic review: teacher-issued but registrar undecided
        $awaiting = Database::all(
            "SELECT c.*, s.student_no, s.grade_level_id, u.first_name, u.last_name, ay.school_year
                FROM clearances c
                JOIN students s ON s.id = c.student_id
                JOIN users u ON u.id = s.user_id
                JOIN academic_years ay ON ay.id = c.academic_year_id
               WHERE c.status = 'issued' AND COALESCE(c.registrar_status, 'pending') = 'pending'
               ORDER BY c.issued_at DESC LIMIT 100"
        );
        // Complete on all three legs, student not yet started next period
        $ready = Database::all(
            "SELECT c.*, s.id AS sid, s.student_no, s.grade_level_id, u.first_name, u.last_name, ay.school_year,
                    gl.name AS level_name
                FROM clearances c
                JOIN students s ON s.id = c.student_id
                JOIN users u ON u.id = s.user_id
                JOIN academic_years ay ON ay.id = c.academic_year_id
                LEFT JOIN grade_levels gl ON gl.id = s.grade_level_id
               WHERE c.status = 'issued' AND c.financial_status = 'cleared'
                 AND COALESCE(c.registrar_status, 'pending') = 'approved'
                 AND u.status = 'active' AND s.status = 'enrolled'
               ORDER BY c.issued_at DESC LIMIT 200"
        );
        // Exclude students that already hold an open/approved application
        $openApps = [];
        try {
            foreach (Database::all("SELECT student_id FROM enrollment_applications WHERE status IN ('pending','accounting_approved','awaiting_payment','action_required','approved') AND student_id IS NOT NULL") as $o) {
                $openApps[(int) $o['student_id']] = true;
            }
        } catch (Throwable $e) {
        }
        $eligible = array_values(array_filter($ready, static fn($r) => !isset($openApps[(int) $r['sid']])));
        // Band-aware labels, detected server-side per student:
        // college -> Semester, kinder-to-SHS -> Quarter/Term. Completed period
        // comes from the clearance; the NEXT period comes from the
        // eligibility engine (approved enrollment first, profile fallback).
        $labelize = static function (array $r): array {
            try {
                $band = AcademicPeriod::bandForLevel((int) ($r['grade_level_id'] ?? 0));
                $doneRaw = AcademicPeriod::effectivePeriod($r);
                $r['done_label'] = AcademicPeriod::periodName($band, $doneRaw);
                $r['done_noun'] = AcademicPeriod::noun($band, $doneRaw);
            } catch (Throwable $e) {
                $r['done_label'] = trim((string) ($r['semester'] ?? '—'));
                $r['done_noun'] = 'Period';
            }
            $r['next_noun'] = '';
            $r['next_label'] = '';
            $r['next_level'] = '';
            try {
                $cur = EnrollmentCycle::currentPeriod((int) ($r['student_id'] ?? $r['sid'] ?? 0));
                if ($cur !== null) {
                    $curPeriod = (string) ($cur['period'] ?? $cur['semester']);
                    if ($curPeriod === '') $curPeriod = (string) $cur['semester'];
                    $next = EnrollmentCycle::nextPeriod((int) $cur['level_id'], $curPeriod, (int) $cur['ay_id']);
                    if (empty($next['graduating'])) {
                        $nb = (string) ($next['band'] ?? AcademicPeriod::BAND_COLLEGE);
                        $np = (string) ($next['period'] ?? $next['semester']);
                        $r['next_noun'] = AcademicPeriod::noun($nb, $np);
                        $r['next_label'] = AcademicPeriod::periodName($nb, $np);
                        $r['next_level'] = (string) ($next['level_name'] ?? '');
                    } else {
                        $r['next_noun'] = 'Program';
                        $r['next_label'] = 'Complete';
                        $r['next_level'] = '';
                    }
                }
            } catch (Throwable $e) {
            }
            return $r;
        };
        $awaiting = array_map($labelize, $awaiting);
        $eligible = array_map($labelize, $eligible);
        $this->view('registrar/re_enrollment', [
            'title' => 'Re-enrollment Cycle', 'awaiting' => $awaiting, 'eligible' => $eligible,
        ]);
    }

    /* ================= Term grade sheets (official pipeline) ================= */

    public function sheets(array $params = []): void
    {
        $this->guard();
        $status = trim((string) ($_GET['status'] ?? 'submitted'));
        $teacher = trim((string) ($_GET['teacher'] ?? ''));
        $subject = trim((string) ($_GET['subject'] ?? ''));
        $period = trim((string) ($_GET['period'] ?? ''));
        $semester = trim((string) ($_GET['semester'] ?? ''));
        $allowed = ['draft', 'submitted', 'under_review', 'returned', 'approved', 'finalized', 'all'];
        if (!in_array($status, $allowed, true)) $status = 'submitted';
        if (!in_array($semester, array_merge([''], self::periodFilterOptions()), true)) $semester = '';

        $where = [];
        $bind = [];
        if ($status !== 'all') {
            $where[] = "gs.status = ?";
            $bind[] = $status;
        }
        if ($teacher !== '') {
            $where[] = "(tu.first_name LIKE ? OR tu.last_name LIKE ? OR CONCAT(tu.first_name,' ',tu.last_name) LIKE ?)";
            $like = "%$teacher%";
            array_push($bind, $like, $like, $like);
        }
        if ($subject !== '') {
            $where[] = "(sc.subject_code LIKE ? OR sc.subject_title LIKE ? OR sc.section_name LIKE ?)";
            $like = "%$subject%";
            array_push($bind, $like, $like, $like);
        }
        if ($period !== '') {
            $where[] = "gp.code = ?";
            $bind[] = $period;
        }
        if ($semester !== '') {
            $where[] = "gs.semester = ?";
            $bind[] = $semester;
        }
        $wsql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $rows = Database::all(
            "SELECT gs.*, gp.code AS period_code, sc.subject_code, sc.subject_title, sc.section_name,
                    sc.semester AS class_semester, ay.school_year,
                     TRIM(CONCAT(COALESCE(tu.first_name,''),' ',COALESCE(tu.last_name,''))) AS teacher_name,
                     (SELECT COUNT(*) FROM grade_sheet_rows r WHERE r.sheet_id = gs.id) AS total_rows,
                     (SELECT COUNT(*) FROM grade_sheet_rows r WHERE r.sheet_id = gs.id AND r.grade IS NULL AND COALESCE(r.remarks,'') <> 'INC') AS missing
               FROM grade_sheets gs
               JOIN subject_classrooms sc ON sc.id = gs.classroom_id
               JOIN grade_periods gp ON gp.id = gs.period_id
               JOIN academic_years ay ON ay.id = gs.academic_year_id
               JOIN teachers t ON t.id = sc.teacher_id
               LEFT JOIN users tu ON tu.id = t.user_id
               $wsql ORDER BY gs.updated_at DESC LIMIT 100",
            $bind
        );
        $counts = [];
        foreach (['submitted', 'under_review', 'returned', 'approved', 'finalized'] as $st) {
            $counts[$st] = (int) Database::value("SELECT COUNT(*) FROM grade_sheets WHERE status = ?", [$st]);
        }
        $counts['draft'] = (int) Database::value("SELECT COUNT(*) FROM grade_sheets WHERE status = 'draft'");
        $counts['all'] = (int) Database::value("SELECT COUNT(*) FROM grade_sheets");
        $periods = Database::all("SELECT code FROM grade_periods WHERE is_active = 1 ORDER BY sort_order ASC");
        $this->view('registrar/sheets_index', [
            'title' => 'Grade Sheets — Review & Finalize', 'rows' => $rows, 'counts' => $counts,
            'filters' => ['status' => $status, 'teacher' => $teacher, 'subject' => $subject, 'period' => $period, 'semester' => $semester],
            'periods' => $periods,
            'periodOptions' => self::periodFilterOptions(),
        ]);
    }

    public function sheetShow(array $params = []): void
    {
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        $sheet = Database::one(
            "SELECT gs.*, gp.code AS period_code, sc.subject_code, sc.subject_title, sc.section_name, sc.units,
                    ay.school_year, TRIM(CONCAT(COALESCE(tu.first_name,''),' ',COALESCE(tu.last_name,''))) AS teacher_name
               FROM grade_sheets gs
               JOIN subject_classrooms sc ON sc.id = gs.classroom_id
               JOIN grade_periods gp ON gp.id = gs.period_id
               JOIN academic_years ay ON ay.id = gs.academic_year_id
               JOIN teachers t ON t.id = sc.teacher_id
               LEFT JOIN users tu ON tu.id = t.user_id
              WHERE gs.id = ? LIMIT 1",
            [$id]
        );
        if ($sheet === null) {
            http_response_code(404);
            exit('Grade sheet not found');
        }
        $rows = Database::all(
            "SELECT r.*, s.student_no, CONCAT(u.first_name,' ',u.last_name) AS student_name
               FROM grade_sheet_rows r
               JOIN students s ON s.id = r.student_id
               JOIN users u ON u.id = s.user_id
              WHERE r.sheet_id = ? ORDER BY u.last_name ASC LIMIT 300",
            [$id]
        );
        $missing = 0;
        foreach ($rows as $r) {
            if ($r['grade'] === null && trim((string) ($r['remarks'] ?? '')) !== 'INC') $missing++;
        }
        $logs = Database::all(
            "SELECT l.*, CONCAT(u.first_name,' ',u.last_name) AS actor FROM grade_sheet_logs l
              LEFT JOIN users u ON u.id = l.actor_user_id WHERE l.sheet_id = ? ORDER BY l.created_at DESC LIMIT 40",
            [$id]
        );
        $calc = class_exists(\App\Core\GradeEngine::class)
            ? \App\Core\GradeEngine::breakdown((int) $sheet['classroom_id'], (int) $sheet['period_id'])
            : ['weights' => [], 'categories' => [], 'students' => [], 'untagged' => 0];
        $this->view('registrar/sheet_show', [
            'title' => 'Review — ' . $sheet['subject_code'] . ' ' . $sheet['period_code'],
            'sheet' => $sheet, 'rows' => $rows, 'logs' => $logs, 'missing' => $missing,
            'calc' => $calc,
        ]);
    }

    public function sheetAction(array $params = []): void
    {
        Csrf::guard();
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        $action = trim((string) ($_POST['action'] ?? ''));
        $remarks = trim((string) ($_POST['remarks'] ?? $_POST['reason'] ?? ''));
        if (!in_array($action, ['under_review', 'returned', 'approved', 'finalized', 'reopen'], true)) {
            set_flash('error', 'Invalid sheet action.');
            redirect('/registrar/sheets/' . $id);
        }
        $sheet = Database::one("SELECT * FROM grade_sheets WHERE id = ? LIMIT 1", [$id]);
        if ($sheet === null) {
            set_flash('error', 'Grade sheet not found.');
            redirect('/registrar/sheets');
        }
        $from = (string) $sheet['status'];
        $allowed = [
            'under_review' => ['submitted'],
            'returned' => ['submitted', 'under_review'],
            'approved' => ['submitted', 'under_review'],
            'finalized' => ['approved'],
            // Reopen is the summer-completion path: a finalized sheet that
            // still carries INC rows goes back to the teacher so the
            // completed major work can be graded, then resubmitted.
            'reopen' => ['finalized'],
        ];
        if (!in_array($from, $allowed[$action] ?? [], true)) {
            set_flash('error', 'Cannot mark ' . str_replace('_', ' ', $action) . ' from ' . str_replace('_', ' ', $from) . '.');
            redirect('/registrar/sheets/' . $id);
        }
        if (in_array($action, ['returned', 'reopen'], true) && mb_strlen($remarks) < 5) {
            set_flash('error', ($action === 'reopen' ? 'Reopening a finalized sheet' : 'Returning a sheet') . ' requires a recorded reason (min 5 characters).');
            redirect('/registrar/sheets/' . $id);
        }
        if ($action === 'reopen') {
            $incCount = (int) Database::value("SELECT COUNT(*) FROM grade_sheet_rows WHERE sheet_id = ? AND grade IS NULL AND remarks = 'INC'", [$id]);
            if ($incCount === 0) {
                set_flash('error', 'Reopen is only for finalized sheets with INC grades awaiting summer completion.');
                redirect('/registrar/sheets/' . $id);
            }
        }
        if ($action === 'finalized') {
            // Finalizable = every row graded, or INC-remarked AND still
            // engine-incomplete (missing major work). A stray INC on a
            // computable student blocks finalization — return it instead.
            $blockers = $this->finalizeBlockers((int) $sheet['classroom_id'], (int) $sheet['period_id'], $id);
            if ($blockers['missing'] > 0 || $blockers['bad_inc'] > 0) {
                $msg = 'Cannot finalize — ';
                if ($blockers['missing'] > 0) $msg .= $blockers['missing'] . ' grade(s) still missing. ';
                if ($blockers['bad_inc'] > 0) $msg .= $blockers['bad_inc'] . ' INC row(s) have all major work graded — return the sheet so the teacher enters the computed grade.';
                set_flash('error', trim($msg));
                redirect('/registrar/sheets/' . $id);
            }
        }
        try {
            Database::transaction(function () use ($id, $action, $remarks, $from, $sheet) {
                $patch = ['status' => $action];
                if ($action === 'under_review') {
                    Database::run("UPDATE grade_sheets SET status = 'under_review', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?", [(int) Auth::id(), $id]);
                } elseif ($action === 'returned') {
                    Database::run("UPDATE grade_sheets SET status = 'returned', reviewed_by = ?, reviewed_at = NOW(), review_remarks = ? WHERE id = ?", [(int) Auth::id(), mb_substr($remarks, 0, 500), $id]);
                } elseif ($action === 'approved') {
                    Database::run("UPDATE grade_sheets SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), review_remarks = ? WHERE id = ?", [(int) Auth::id(), $remarks !== '' ? mb_substr($remarks, 0, 500) : null, $id]);
                } elseif ($action === 'reopen') {
                    Database::run("UPDATE grade_sheets SET status = 'returned', reviewed_by = ?, reviewed_at = NOW(), review_remarks = ? WHERE id = ?", [(int) Auth::id(), mb_substr($remarks, 0, 500), $id]);
                } else {
                    Database::run("UPDATE grade_sheets SET status = 'finalized', finalized_by = ?, finalized_at = NOW() WHERE id = ?", [(int) Auth::id(), $id]);
                }
                unset($patch);
                Database::insert('grade_sheet_logs', [
                    'sheet_id' => $id, 'from_status' => $from, 'to_status' => $action,
                    'action' => $action, 'remarks' => $remarks !== '' ? mb_substr($remarks, 0, 500) : null,
                    'actor_user_id' => (int) Auth::id(),
                ]);
                Database::insert('audit_logs', [
                    'actor_user_id' => (int) Auth::id(), 'action' => 'gradesheet.' . $action,
                    'entity_type' => 'grade_sheets', 'entity_id' => $id, 'ip_address' => client_ip(),
                ]);
            });
            // Notify the teacher on every registrar decision
            try {
                $owner = Database::one(
                    "SELECT t.user_id, sc.subject_code FROM grade_sheets gs JOIN subject_classrooms sc ON sc.id = gs.classroom_id JOIN teachers t ON t.id = sc.teacher_id WHERE gs.id = ? LIMIT 1",
                    [$id]
                );
                if ($owner && !empty($owner['user_id'])) {
                    $msg = match ($action) {
                        'returned' => 'Returned for correction' . ($remarks !== '' ? ': ' . $remarks : '') . ' — correct and resubmit.',
                        'reopen' => 'Reopened for INC completion' . ($remarks !== '' ? ': ' . $remarks : '') . ' — grade the completed major work and resubmit.',
                        'approved' => 'Approved by Registrar. Awaiting finalization into official records.',
                        'finalized' => 'Finalized — now part of official academic records.',
                        default => 'Now under Registrar review.',
                    };
                    \App\Controllers\NotificationController::push(
                        (int) $owner['user_id'], 'Grade sheet ' . str_replace('_', ' ', $action) . ': ' . ($owner['subject_code'] ?? ''),
                        $msg, $action === 'returned' ? 'warning' : 'success', '/teacher/grades'
                    );
                }
                if ($action === 'finalized') {
                    $subs = Database::all(
                        "SELECT DISTINCT s.user_id FROM grade_sheet_rows r JOIN students s ON s.id = r.student_id WHERE r.sheet_id = ? AND s.user_id IS NOT NULL LIMIT 200",
                        [$id]
                    );
                    foreach ($subs as $su) {
                        \App\Controllers\NotificationController::push((int) $su['user_id'], 'Your grades have been released', 'Final grades are now official — view them in Grades. Need a stamped copy? Request a Grade Slip in Academic Documents.', 'success', '/student/grades');
                    }
                }
            } catch (Throwable $e) {
            }
            set_flash('success', 'Sheet marked as ' . str_replace('_', ' ', $action) . '.');
        } catch (Throwable $e) {
            storage_log('registrar sheetAction failed ' . $id . ': ' . $e->getMessage());
            set_flash('error', 'Action failed: ' . $e->getMessage());
        }
        redirect($action === 'finalized' ? '/registrar/sheets?status=finalized' : '/registrar/sheets/' . $id);
    }

    /**
     * Finalize gate: rows blocking official lock.
     * @return array{missing:int,bad_inc:int}
     * missing = grade NULL and not engine-incomplete;
     * bad_inc = stamped INC while the engine finds all major work graded.
     */
    private function finalizeBlockers(int $classroomId, int $periodId, int $sheetId): array
    {
        $out = ['missing' => 0, 'bad_inc' => 0];
        try {
            $calc = class_exists(\App\Core\GradeEngine::class)
                ? \App\Core\GradeEngine::breakdown($classroomId, $periodId)
                : ['weights' => [], 'students' => []];
            $engineDown = ($calc['weights'] ?? []) === [];
            foreach (Database::all("SELECT student_id, grade, remarks FROM grade_sheet_rows WHERE sheet_id = ?", [$sheetId]) as $sr) {
                if ($sr['grade'] !== null) continue;
                $info = $calc['students'][(int) $sr['student_id']] ?? null;
                $isInc = trim((string) ($sr['remarks'] ?? '')) === 'INC';
                if ($info !== null && !empty($info['inc'])) {
                    if (!$isInc) $out['missing']++;
                    continue;
                }
                if ($isInc && !$engineDown) { $out['bad_inc']++; continue; }
                $out['missing']++;
            }
        } catch (Throwable $e) {
            $out['missing'] = (int) Database::value(
                "SELECT COUNT(*) FROM grade_sheet_rows WHERE sheet_id = ? AND grade IS NULL AND COALESCE(remarks,'') <> 'INC'",
                [$sheetId]
            );
        }
        return $out;
    }

    /* ================= Grade scale (configurable) ================= */

    public function scales(array $params = []): void
    {
        $this->guard();
        foreach (['grade_scales', 'grade_weights', 'grade_conversions', 'assessment_categories'] as $t) {
            try {
                Database::value("SELECT 1 FROM `$t` LIMIT 1");
            } catch (Throwable $e) {
                set_flash('error', 'Grading configuration tables are missing — import database/patch_weighted_grades.sql first.');
                redirect('/registrar/sheets');
            }
        }
        $rows = Database::all("SELECT * FROM grade_scales ORDER BY sort_order ASC, min_grade ASC");
        $weights = Database::all(
            "SELECT gw.*, ac.code, ac.name FROM grade_weights gw
              JOIN assessment_categories ac ON ac.id = gw.category_id
             WHERE gw.classroom_id IS NULL ORDER BY ac.sort_order ASC"
        );
        $wsum = 0.0;
        foreach ($weights as $w) $wsum += (float) $w['weight_pct'];
        $convs = Database::all("SELECT * FROM grade_conversions ORDER BY sort_order ASC");
        $cats = Database::all("SELECT * FROM assessment_categories WHERE is_active = 1 ORDER BY sort_order ASC");
        $this->view('registrar/scales_index', [
            'title' => 'Grading Configuration', 'rows' => $rows,
            'weights' => $weights, 'weightSum' => $wsum,
            'conversions' => $convs, 'categories' => $cats,
        ]);
    }

    public function scaleStore(array $params = []): void
    {
        Csrf::guard();
        $this->guard();
        $min = trim((string) ($_POST['min_grade'] ?? ''));
        $max = trim((string) ($_POST['max_grade'] ?? ''));
        $eq = trim((string) ($_POST['equivalent'] ?? ''));
        $remarks = trim((string) ($_POST['remarks'] ?? 'PASSED'));
        $passing = isset($_POST['is_passing']) ? 1 : 0;
        if (!is_numeric($min) || !is_numeric($max) || (float) $min < 1.00 || (float) $max > 5.00 || (float) $min > (float) $max) {
            set_flash('error', 'Scale range must be numeric within 1.00–5.00 with min ≤ max.');
            redirect('/registrar/scales');
        }
        if ($eq === '') {
            set_flash('error', 'Equivalent label is required.');
            redirect('/registrar/scales');
        }
        $id = (int) ($_POST['id'] ?? 0);
        try {
            if ($id > 0) {
                Database::run(
                    "UPDATE grade_scales SET min_grade = ?, max_grade = ?, equivalent = ?, remarks = ?, is_passing = ? WHERE id = ?",
                    [(float) $min, (float) $max, mb_substr($eq, 0, 20), mb_substr($remarks, 0, 60), $passing, $id]
                );
            } else {
                $maxSort = (int) Database::value("SELECT COALESCE(MAX(sort_order),0) FROM grade_scales");
                Database::insert('grade_scales', [
                    'min_grade' => (float) $min, 'max_grade' => (float) $max,
                    'equivalent' => mb_substr($eq, 0, 20), 'remarks' => mb_substr($remarks, 0, 60),
                    'is_passing' => $passing, 'sort_order' => $maxSort + 1,
                ]);
            }
            Database::insert('audit_logs', [
                'actor_user_id' => (int) Auth::id(), 'action' => 'gradescale.saved',
                'entity_type' => 'grade_scales', 'entity_id' => $id, 'ip_address' => client_ip(),
            ]);
            set_flash('success', 'Grade scale saved.');
        } catch (Throwable $e) {
            storage_log('scaleStore failed: ' . $e->getMessage());
            set_flash('error', 'Save failed: ' . $e->getMessage());
        }
        redirect('/registrar/scales');
    }

    public function scaleDelete(array $params = []): void
    {
        Csrf::guard();
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        Database::run("DELETE FROM grade_scales WHERE id = ?", [$id]);
        Database::insert('audit_logs', [
            'actor_user_id' => (int) Auth::id(), 'action' => 'gradescale.deleted',
            'entity_type' => 'grade_scales', 'entity_id' => $id, 'ip_address' => client_ip(),
        ]);
        set_flash('success', 'Scale band deleted.');
        redirect('/registrar/scales');
    }

    /* ============ Category weights (global default) ============ */

    public function weightsStore(array $params = []): void
    {
        Csrf::guard();
        $this->guard();
        $posted = $_POST['weight'] ?? [];
        if (!is_array($posted)) $posted = [];
        $sum = 0.0;
        try {
            Database::transaction(function () use ($posted, &$sum) {
                foreach ($posted as $catId => $w) {
                    $w = trim((string) $w);
                    if ($w === '' || !is_numeric($w) || (float) $w < 0 || (float) $w > 100) {
                        throw new \RuntimeException('Weights must be numbers between 0 and 100.');
                    }
                    $sum += (float) $w;
                    Database::run(
                        "UPDATE grade_weights SET weight_pct = ? WHERE classroom_id IS NULL AND category_id = ?",
                        [round((float) $w, 2), (int) $catId]
                    );
                }
            });
            Database::insert('audit_logs', [
                'actor_user_id' => (int) Auth::id(), 'action' => 'gradeweights.saved',
                'entity_type' => 'grade_weights', 'entity_id' => 0, 'ip_address' => client_ip(),
            ]);
            set_flash(
                abs($sum - 100.0) > 0.01 ? 'warning' : 'success',
                'Weights saved (total ' . number_format($sum, 2) . '%).'
                . (abs($sum - 100.0) > 0.01 ? ' Should normally total 100% — engine renormalizes per student.' : '')
            );
        } catch (Throwable $e) {
            storage_log('weightsStore failed: ' . $e->getMessage());
            set_flash('error', 'Save failed: ' . $e->getMessage());
        }
        redirect('/registrar/scales');
    }

    /* ============ Percent → point conversion bands ============ */

    public function conversionsStore(array $params = []): void
    {
        Csrf::guard();
        $this->guard();
        $min = trim((string) ($_POST['min_pct'] ?? ''));
        $max = trim((string) ($_POST['max_pct'] ?? ''));
        $point = trim((string) ($_POST['point'] ?? ''));
        if (!is_numeric($min) || !is_numeric($max) || !is_numeric($point)
            || (float) $min < 0 || (float) $max > 100 || (float) $min > (float) $max
            || (float) $point < 1.00 || (float) $point > 5.00) {
            set_flash('error', 'Conversion needs percent range 0–100 (min ≤ max) and point 1.00–5.00.');
            redirect('/registrar/scales');
        }
        $id = (int) ($_POST['id'] ?? 0);
        try {
            if ($id > 0) {
                Database::run(
                    "UPDATE grade_conversions SET min_pct = ?, max_pct = ?, point = ? WHERE id = ?",
                    [(float) $min, (float) $max, round((float) $point, 2), $id]
                );
            } else {
                $maxSort = (int) Database::value("SELECT COALESCE(MAX(sort_order),0) FROM grade_conversions");
                Database::insert('grade_conversions', [
                    'min_pct' => (float) $min, 'max_pct' => (float) $max,
                    'point' => round((float) $point, 2), 'sort_order' => $maxSort + 1,
                ]);
            }
            Database::insert('audit_logs', [
                'actor_user_id' => (int) Auth::id(), 'action' => 'gradeconversion.saved',
                'entity_type' => 'grade_conversions', 'entity_id' => $id, 'ip_address' => client_ip(),
            ]);
            set_flash('success', 'Conversion band saved.');
        } catch (Throwable $e) {
            storage_log('conversionsStore failed: ' . $e->getMessage());
            set_flash('error', 'Save failed: ' . $e->getMessage());
        }
        redirect('/registrar/scales');
    }

    public function conversionDelete(array $params = []): void
    {
        Csrf::guard();
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        Database::run("DELETE FROM grade_conversions WHERE id = ?", [$id]);
        Database::insert('audit_logs', [
            'actor_user_id' => (int) Auth::id(), 'action' => 'gradeconversion.deleted',
            'entity_type' => 'grade_conversions', 'entity_id' => $id, 'ip_address' => client_ip(),
        ]);
        set_flash('success', 'Conversion band deleted.');
        redirect('/registrar/scales');
    }
}
