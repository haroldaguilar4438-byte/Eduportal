<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Models\Announcement;
use Throwable;

/**
 * Role-aware dashboard (entry point after login).
 * Queries are defensive: missing/empty data degrades to zeros and empty lists,
 * keeping the portal usable while later modules are still being populated.
 */
final class DashboardController extends Controller
{
    public function index(array $params = []): void
    {
        Auth::requireRole('admin', 'teacher', 'student', 'parent', 'registrar', 'accounting');

        if (Auth::is('admin')) {
            $this->adminDashboard();
        } elseif (Auth::is('registrar')) {
            $this->registrarDashboard();
        } elseif (Auth::is('accounting')) {
            $this->accountingDashboard();
        } elseif (Auth::is('teacher')) {
            $this->teacherDashboard();
        } elseif (Auth::is('student')) {
            $this->studentDashboard();
        } elseif (Auth::is('parent')) {
            $this->parentDashboard();
        } else {
            redirect('/login');
        }
    }

    /* ================================ ADMIN ================================ */

    private function adminDashboard(): void
    {
        $stats = [
            'pending_enrollments' => self::scalar(
                "SELECT COUNT(*) FROM enrollment_applications WHERE status = 'pending'"
            ),
            'students_enrolled'   => self::scalar(
                "SELECT COUNT(*) FROM students WHERE status = 'enrolled'"
            ),
            'teachers'            => self::scalar('SELECT COUNT(*) FROM teachers'),
            'open_risk_flags'     => self::scalar(
                "SELECT COUNT(*) FROM at_risk_flags WHERE status = 'open' AND risk_level IN ('moderate','high')"
            ),
        ];
        $monthPaid       = self::scalar("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='paid' AND paid_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
        $pendingPayments = self::scalar("SELECT COUNT(*) FROM payments WHERE status IN ('pending','processing')");

        $recentEnrollments = self::rows(
            "SELECT ea.application_no, ea.status, ea.created_at,
                    CONCAT(u.first_name,' ',u.last_name) AS applicant,
                    gl.name AS level_name
               FROM enrollment_applications ea
               LEFT JOIN users u ON u.id = ea.user_id
               JOIN grade_levels gl ON gl.id = ea.grade_level_applied_id
              ORDER BY ea.created_at DESC LIMIT 6"
        );
        $recentPayments = self::rows(
            "SELECT transaction_ref, amount, status, payment_method, channel, created_at
               FROM payments ORDER BY created_at DESC LIMIT 6"
        );
        // Active announcements only: published + started + not expired + audience-eligible.
        // Backend-filtered (never JS/CSS hiding); expired rows stay in Admin history.
        $announcements = Announcement::activeFor('admin', (int) Auth::id(), 5);

        $this->view('admin/dashboard', [
            'title'             => 'Admin dashboard',
            'stats'             => $stats,
            'monthPaid'         => (float) $monthPaid,
            'pendingPayments'   => (int) $pendingPayments,
            'recentEnrollments' => $recentEnrollments,
            'recentPayments'    => $recentPayments,
            'announcements'     => $announcements,
            'analytics'         => self::analytics(),
        ]);
    }

    /** School analytics payload (same builder as the JSON refresh endpoint). */
    private static function analytics(): array
    {
        try {
            if (class_exists(\App\Controllers\Admin\AnalyticsController::class)) {
                return \App\Controllers\Admin\AnalyticsController::build($_GET);
            }
        } catch (Throwable $e) {
            storage_log('admin analytics build failed: ' . $e->getMessage());
        }
        return ['filters' => ['ay_id' => 0, 'ay_label' => '', 'period' => '', 'band' => '', 'program_id' => 0], 'options' => ['years' => [], 'programs' => [], 'periods' => []], 'data' => []];
    }

    /* ============================== REGISTRAR ============================== */

    private function registrarDashboard(): void
    {
        $stats = [
            'total_students' => self::scalar("SELECT COUNT(*) FROM students WHERE status = 'enrolled'"),
            'pending_permits' => self::scalar("SELECT COUNT(*) FROM exam_permits WHERE status = 'approved_accounting'"),
            'grades_awaiting' => self::scalar("SELECT COUNT(*) FROM classroom_task_submissions WHERE status = 'graded' AND review_status = 'submitted'"),
            'pending_docs' => self::scalar("SELECT COUNT(*) FROM academic_documents WHERE status = 'pending'"),
            'pending_sheets' => self::scalar("SELECT COUNT(*) FROM grade_sheets WHERE status IN ('submitted','under_review')"),
            'returned_sheets' => self::scalar("SELECT COUNT(*) FROM grade_sheets WHERE status = 'returned'"),
            'pending_clearance_review' => self::scalar("SELECT COUNT(*) FROM clearances WHERE status = 'issued' AND COALESCE(registrar_status,'pending') = 'pending'"),
            'ready_next' => self::scalar(
                "SELECT COUNT(*) FROM clearances c WHERE c.status = 'issued' AND c.financial_status = 'cleared'
                   AND COALESCE(c.registrar_status,'pending') = 'approved'
                   AND NOT EXISTS (SELECT 1 FROM enrollment_applications ea WHERE ea.student_id = c.student_id
                     AND ea.status IN ('pending','accounting_approved','awaiting_payment','action_required','approved'))"
            ),
        ];
        $pendingGrades = self::scalar(
            "SELECT COUNT(*) FROM classroom_task_submissions cts WHERE cts.status IN ('submitted','late')"
        );
        $recentApproved = self::rows(
            "SELECT cts.id, cts.score, cts.reviewed_at, ct.title, sc.subject_code,
                    CONCAT(u.first_name,' ',u.last_name) AS student_name
               FROM classroom_task_submissions cts
               JOIN classroom_tasks ct ON ct.id = cts.task_id
               JOIN subject_classrooms sc ON sc.id = ct.classroom_id
               JOIN students st ON st.id = cts.student_id
               JOIN users u ON u.id = st.user_id
              WHERE cts.review_status = 'approved'
              ORDER BY cts.reviewed_at DESC LIMIT 6"
        );
        $permitQueue = self::rows(
            "SELECT ep.id, ep.permit_no, ep.exam_period, ep.status, ep.requested_at,
                    s.student_no, u.first_name, u.last_name
               FROM exam_permits ep
               JOIN students s ON s.id = ep.student_id
               JOIN users u ON u.id = s.user_id
              WHERE ep.status = 'approved_accounting'
              ORDER BY ep.requested_at ASC LIMIT 6"
        );
        $recentDocs = self::rows(
            "SELECT ad.id, ad.doc_no, ad.doc_type, ad.status, ad.created_at,
                    CONCAT(u.first_name,' ',u.last_name) AS student_name
               FROM academic_documents ad
               JOIN students s ON s.id = ad.student_id
               JOIN users u ON u.id = s.user_id
              ORDER BY ad.created_at DESC LIMIT 6"
        );
        $this->view('registrar/dashboard', [
            'title' => 'Registrar dashboard',
            'stats' => $stats,
            'pendingGrades' => (int) $pendingGrades,
            'recentApproved' => $recentApproved,
            'permitQueue' => $permitQueue,
            'recentDocs' => $recentDocs,
            'announcements' => Announcement::activeFor('registrar', (int) Auth::id(), 5),
        ]);
    }

    /* ============================== ACCOUNTING ============================= */

    private function accountingDashboard(): void
    {
        $stats = [
            'pending_clearance' => self::scalar("SELECT COUNT(*) FROM clearances WHERE financial_status IN ('pending','for_review')"),
            'cleared' => self::scalar("SELECT COUNT(*) FROM clearances WHERE financial_status = 'cleared'"),
            'pending_payments' => self::scalar("SELECT COUNT(*) FROM payments WHERE status IN ('pending','processing')"),
            'pending_enroll' => self::scalar("SELECT COUNT(*) FROM enrollment_applications WHERE status = 'pending'"),
        ];
        $outstanding = self::scalar(
            "SELECT COALESCE((SELECT SUM(amount) FROM student_charges),0)
                    - COALESCE((SELECT SUM(pa.amount_applied) FROM payment_allocations pa
                                 JOIN payments p ON p.id = pa.payment_id WHERE p.status = 'paid'),0)"
        );
        $monthPaid = self::scalar("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='paid' AND paid_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
        $recentPayments = self::rows(
            "SELECT p.transaction_ref, p.amount, p.status, p.payment_method, p.channel, p.created_at,
                    s.student_no
               FROM payments p LEFT JOIN students s ON s.id = p.student_id
              ORDER BY p.created_at DESC LIMIT 6"
        );
        $enrollQueue = self::rows(
            "SELECT ea.application_no, ea.status, ea.created_at,
                    CONCAT(u.first_name,' ',u.last_name) AS applicant
               FROM enrollment_applications ea
               LEFT JOIN users u ON u.id = ea.user_id
              WHERE ea.status IN ('pending','accounting_approved','awaiting_payment')
              ORDER BY ea.created_at DESC LIMIT 6"
        );
        $this->view('accounting/dashboard', [
            'title' => 'Accounting dashboard',
            'stats' => $stats,
            'outstanding' => (float) max(0, (float) $outstanding),
            'monthPaid' => (float) $monthPaid,
            'recentPayments' => $recentPayments,
            'enrollQueue' => $enrollQueue,
            'announcements' => Announcement::activeFor('accounting', (int) Auth::id(), 5),
        ]);
    }

    /* =============================== TEACHER =============================== */

    private function teacherDashboard(): void
    {
        $teacher   = Database::one('SELECT id FROM teachers WHERE user_id = ? LIMIT 1', [(int) Auth::id()]);
        $teacherId = $teacher === null ? 0 : (int) $teacher['id'];

        try {
        $classes = self::rows(
            "SELECT sc.id, sc.section_name, sc.max_students, sc.subject_code AS code, sc.subject_title AS title,
                    sc.section_name AS section_name, '' AS level_name, sc.schedule AS schedule_note, sc.room,
                    (SELECT COUNT(*) FROM classroom_members cm WHERE cm.classroom_id = sc.id) AS enrolled_count
               FROM subject_classrooms sc
              WHERE sc.teacher_id = ? AND sc.is_active = 1
              ORDER BY sc.subject_code LIMIT 12",
            [$teacherId]
        );
        } catch (Throwable $e) {
        $classes = self::rows(
            "SELECT sc.id, sc.section_name, sc.max_students, sc.subject_code AS code, sc.subject_title AS title,
                    sc.section_name AS section_name, '' AS level_name, NULL AS schedule_note, NULL AS room,
                    (SELECT COUNT(*) FROM classroom_members cm WHERE cm.classroom_id = sc.id) AS enrolled_count
               FROM subject_classrooms sc
              WHERE sc.teacher_id = ?
              ORDER BY sc.subject_code LIMIT 12",
            [$teacherId]
        );
        }

        // ── Teacher stat cards (official subjects) ──
        $totalClasses = count($classes);
        $enrolledStudents = self::scalar(
            "SELECT COUNT(DISTINCT cm.student_id) FROM classroom_members cm
              JOIN subject_classrooms sc ON sc.id = cm.classroom_id WHERE sc.teacher_id = ?",
            [$teacherId]
        );
        $pendingGrades = self::scalar(
            "SELECT COUNT(*) FROM classroom_task_submissions cts
              JOIN classroom_tasks ct ON ct.id = cts.task_id
              JOIN subject_classrooms sc ON sc.id = ct.classroom_id
             WHERE sc.teacher_id = ? AND cts.status IN ('submitted','late')",
            [$teacherId]
        );
        // Pending exam permit checks (registrar-approved awaiting teacher verification)
        // FIX: Students often have section_id = NULL after enrollment (not yet assigned), so strict
        // section filter hides permits from teachers. School-wide visibility ensures queue is not empty.
        $pendingPermits = 0;
        $permitQueue = [];
        try {
            $hasExamPermits = Database::value("SHOW TABLES LIKE 'exam_permits'");
            if ($hasExamPermits) {
                $pendingPermits = self::scalar("SELECT COUNT(*) FROM exam_permits WHERE status = 'approved_registrar'");
                $permitQueue = self::rows(
                    "SELECT ep.id, ep.permit_no, ep.exam_period, ep.status, ep.financial_status, ep.payment_validated, ep.payment_ref, ep.receipt_no, ep.requested_at,
                            s.student_no, sec.name AS section_name, u.first_name, u.last_name,
                            ay.school_year
                       FROM exam_permits ep
                       JOIN students s ON s.id = ep.student_id
                       JOIN users u ON u.id = s.user_id
                       LEFT JOIN sections sec ON sec.id = s.section_id
                       JOIN academic_years ay ON ay.id = ep.academic_year_id
                      WHERE ep.status = 'approved_registrar'
                      ORDER BY ep.requested_at ASC LIMIT 10",
                    []
                );
            }
        } catch (Throwable $e) {
            storage_log('teacher permit queue failed: ' . $e->getMessage());
            $pendingPermits = 0;
            $permitQueue = [];
        }

        $teacherStats = [
            'totalClasses'     => (int) $totalClasses,
            'enrolledStudents' => (int) $enrolledStudents,
            'pendingGrades'    => (int) $pendingGrades,
            'pendingPermits'   => (int) $pendingPermits,
            'pendingClearances' => (int) self::scalar(
                "SELECT COUNT(DISTINCT ci.clearance_id) FROM clearance_items ci
                  JOIN clearances c ON c.id = ci.clearance_id
                 WHERE ci.teacher_id = ? AND ci.is_signed = 0 AND c.status = 'pending'",
                [$teacherId]
            ),
        ];

        $toGrade = self::rows(
            "SELECT cts.id, ct.title, cts.status, cts.submitted_at,
                    CONCAT(u.first_name,' ',u.last_name) AS student_name
               FROM classroom_task_submissions cts
               JOIN classroom_tasks ct ON ct.id = cts.task_id
               JOIN subject_classrooms sc ON sc.id = ct.classroom_id
               JOIN students st ON st.id = cts.student_id
               JOIN users u ON u.id = st.user_id
              WHERE sc.teacher_id = ? AND cts.status IN ('submitted','late')
              ORDER BY cts.submitted_at ASC LIMIT 8",
            [$teacherId]
        );
        // Active teacher announcements only (published + started + not expired).
        $announcements = Announcement::activeFor('teacher', (int) Auth::id(), 4);

        // ── Google Classroom-style Subject Classrooms (teacher owns) ──
        $subjectClassrooms = [];
        try {
            $hasSC = Database::value("SHOW TABLES LIKE 'subject_classrooms'");
            if ($hasSC && $teacherId) {
                $hasJR = Database::value("SHOW TABLES LIKE 'class_join_requests'");
                $jrSel = $hasJR ? ', (SELECT COUNT(*) FROM class_join_requests jr WHERE jr.classroom_id = sc.id AND jr.status = \'pending\') AS pending_requests' : ', 0 AS pending_requests';
                $subjectClassrooms = self::rows(
                    "SELECT sc.*, ay.school_year, (SELECT COUNT(*) FROM classroom_members cm WHERE cm.classroom_id = sc.id) AS member_count{$jrSel}
                       FROM subject_classrooms sc
                       JOIN academic_years ay ON ay.id = sc.academic_year_id
                      WHERE sc.teacher_id = ?
                      ORDER BY sc.created_at DESC LIMIT 20",
                    [$teacherId]
                );
            }
        } catch (Throwable $e) { storage_log('teacher classrooms failed: '.$e->getMessage()); $subjectClassrooms = []; }

        // ── Teacher Activity History (when teacher created activities) — now with uploaded files count ──
        $activityHistory = [];
        try {
            $hasTasks = Database::value("SHOW TABLES LIKE 'classroom_tasks'");
            $hasSC2 = Database::value("SHOW TABLES LIKE 'subject_classrooms'");
            $hasSubs = Database::value("SHOW TABLES LIKE 'classroom_task_submissions'");
            if ($hasTasks && $hasSC2 && $teacherId) {
                $activityHistory = self::rows(
                    "SELECT ct.*, sc.subject_code, sc.subject_title, sc.invite_code, sc.section_name,
                            (SELECT COUNT(*) FROM classroom_members cm WHERE cm.classroom_id = sc.id) AS member_count,
                            (SELECT COUNT(*) FROM classroom_task_submissions cts WHERE cts.task_id = ct.id) AS submission_count,
                            (SELECT COUNT(*) FROM classroom_task_submissions cts2 WHERE cts2.task_id = ct.id AND cts2.file_path IS NOT NULL) AS file_count
                       FROM classroom_tasks ct
                       JOIN subject_classrooms sc ON sc.id = ct.classroom_id
                      WHERE sc.teacher_id = ?
                      ORDER BY ct.created_at DESC
                      LIMIT 20",
                    [$teacherId]
                );
            }
        } catch (Throwable $e) { storage_log('teacher activity history failed: '.$e->getMessage()); $activityHistory = []; }

        $this->view('teacher/dashboard', [
            'title'         => 'Teacher dashboard',
            'classes'       => $classes,
            'toGrade'       => $toGrade,
            'announcements' => $announcements,
            // Enhanced vars (backward compatible)
            'teacherStats' => $teacherStats,
            'permitQueue'  => $permitQueue,
            // Aliases for view flexibility
            'assignedClasses' => $classes,
            'enrolledStudents' => (int)$enrolledStudents,
            'pendingGrades' => (int)$pendingGrades,
            'pendingPermits' => (int)$pendingPermits,
            // Google Classroom-style subject classrooms + activity history
            'subjectClassrooms' => $subjectClassrooms,
            'activityHistory'   => $activityHistory,
            // Grading engine metadata for the activity form (fail-open)
            'assessCategories' => self::rows("SELECT * FROM assessment_categories WHERE is_active = 1 ORDER BY sort_order ASC"),
            'gradePeriods' => self::rows("SELECT * FROM grade_periods WHERE is_active = 1 ORDER BY sort_order ASC"),
        ]);
    }

    /* =============================== STUDENT =============================== */

    private function studentDashboard(): void
    {
        $student   = Database::one('SELECT * FROM students WHERE user_id = ? LIMIT 1', [(int) Auth::id()]);
        $studentId = $student === null ? 0 : (int) $student['id'];

        $avgGrade = self::scalar(
            'SELECT AVG(g.final_grade) FROM grades g
              JOIN students s ON s.id = g.student_id
             WHERE s.user_id = ? AND g.is_released = 1 AND g.final_grade IS NOT NULL',
            [(int) Auth::id()]
        );

        // Outstanding balance = assessed charges minus paid allocations.
        $balance = self::scalar(
            "SELECT COALESCE((SELECT SUM(amount) FROM student_charges WHERE student_id = ?),0)
                   - COALESCE((SELECT SUM(pa.amount_applied)
                                 FROM payment_allocations pa
                                 JOIN payments p ON p.id = pa.payment_id
                                WHERE p.status = 'paid'
                                  AND pa.charge_id IN (SELECT id FROM student_charges WHERE student_id = ?)),0)",
            [$studentId, $studentId]
        );

        $dueAssignments = $studentId <= 0 ? [] : self::rows(
            "SELECT ct.id, ct.title, ct.due_at, sc.subject_code
               FROM classroom_tasks ct
               JOIN subject_classrooms sc ON sc.id = ct.classroom_id
               JOIN classroom_members cm ON cm.classroom_id = sc.id
              WHERE cm.student_id = ?
                AND ct.due_at IS NOT NULL AND ct.due_at > NOW()
              ORDER BY ct.due_at ASC LIMIT 6",
            [$studentId]
        );

        // Active student announcements only (latest 5, pinned first — never backfill with expired).
        $announcements = Announcement::activeFor('student', (int) Auth::id(), 5);

        // ── Exam Permit Widget data (non-destructive additive) ──
        $currentAy = Database::one("SELECT * FROM academic_years WHERE is_current = 1 LIMIT 1");
        if ($currentAy === null) $currentAy = Database::one("SELECT * FROM academic_years ORDER BY id DESC LIMIT 1");
        $currentAyId = $currentAy ? (int) $currentAy['id'] : 0;

        // ── Next-enrollment cycle status (fail-open) ──
        $cycle = null;
        try {
            if ($studentId > 0 && class_exists(\App\Core\EnrollmentCycle::class)) {
                $cycle = \App\Core\EnrollmentCycle::checklist($studentId);
            }
        } catch (Throwable $e) {
            $cycle = null;
        }

        $examPermits = [];
        try {
            $hasExamPermits = Database::value("SHOW TABLES LIKE 'exam_permits'");
            if ($hasExamPermits && $studentId) {
                $examPermits = self::rows(
                    "SELECT ep.*, ay.school_year
                       FROM exam_permits ep
                       JOIN academic_years ay ON ay.id = ep.academic_year_id
                      WHERE ep.student_id = ? AND ep.academic_year_id = ?
                      ORDER BY FIELD(ep.exam_period,'Prelim','Midterm','Prefinal','Finals')",
                    [$studentId, $currentAyId]
                );
            }
        } catch (Throwable $e) {
            storage_log('student exam permits failed: ' . $e->getMessage());
            $examPermits = [];
        }

        // ── Google Classroom-style Joined Subjects ──
        $joinedClassrooms = [];
        $classroomTasks = [];
        try {
            $hasSC = Database::value("SHOW TABLES LIKE 'subject_classrooms'");
            if ($hasSC && $studentId) {
                $joinedClassrooms = self::rows(
                    "SELECT sc.*, ay.school_year, tu.first_name AS teacher_first, tu.last_name AS teacher_last, t.employee_no,
                            (SELECT COUNT(*) FROM classroom_members cm2 WHERE cm2.classroom_id = sc.id) AS member_count,
                            cm.joined_at
                       FROM subject_classrooms sc
                       JOIN classroom_members cm ON cm.classroom_id = sc.id
                       LEFT JOIN teachers t ON t.id = sc.teacher_id
                       LEFT JOIN users tu ON tu.id = t.user_id
                       JOIN academic_years ay ON ay.id = sc.academic_year_id
                      WHERE cm.student_id = ?
                      ORDER BY cm.joined_at DESC",
                    [$studentId]
                );
                // Classroom tasks for joined subjects — Dashboard Feed: only active with VALID deadlines (future, reasonable year)
                // Also join student's own submission so uploaded files are shown directly in feed (clickable row still opens upload page)
                $hasTasks = Database::value("SHOW TABLES LIKE 'classroom_tasks'");
                $hasSubs = Database::value("SHOW TABLES LIKE 'classroom_task_submissions'");
                if ($hasTasks) {
                    $curY = (int)date('Y');
                    if ($hasSubs) {
                        $classroomTasks = self::rows(
                            "SELECT ct.*, sc.subject_code, sc.subject_title, sc.invite_code, sc.academic_year_id, gp.code AS period_code,
                                    cts.id AS submission_id, cts.file_path, cts.original_filename, cts.status AS submission_status, cts.submitted_at AS submission_at, cts.score
                               FROM classroom_tasks ct
                               JOIN subject_classrooms sc ON sc.id = ct.classroom_id
                               LEFT JOIN grade_periods gp ON gp.id = ct.period_id
                               JOIN classroom_members cm ON cm.classroom_id = sc.id
                               LEFT JOIN classroom_task_submissions cts ON cts.task_id = ct.id AND cts.student_id = ?
                              WHERE cm.student_id = ?
                                AND (ct.due_at IS NULL OR (ct.due_at > NOW() AND YEAR(ct.due_at) BETWEEN ? AND ?))
                              ORDER BY ct.due_at IS NULL, ct.due_at ASC
                              LIMIT 8",
                            [$studentId, $studentId, $curY, $curY+5]
                        );
                    } else {
                        $classroomTasks = self::rows(
                            "SELECT ct.*, sc.subject_code, sc.subject_title, sc.invite_code, sc.academic_year_id, gp.code AS period_code
                               FROM classroom_tasks ct
                               JOIN subject_classrooms sc ON sc.id = ct.classroom_id
                               LEFT JOIN grade_periods gp ON gp.id = ct.period_id
                               JOIN classroom_members cm ON cm.classroom_id = sc.id
                              WHERE cm.student_id = ?
                                AND (ct.due_at IS NULL OR (ct.due_at > NOW() AND YEAR(ct.due_at) BETWEEN ? AND ?))
                              ORDER BY ct.due_at IS NULL, ct.due_at ASC
                              LIMIT 8",
                            [$studentId, $curY, $curY+5]
                        );
                    }
                }
            }
        } catch (Throwable $e) { storage_log('student classrooms failed: '.$e->getMessage()); $joinedClassrooms = []; $classroomTasks = []; }

        // ── Subject gate for Prelim permits (fail-open) ──
        $permitMinSubjects = 8;
        $joinedTermCount = 0;
        try {
            $permitMinSubjects = ExamPermitController::minSubjects();
            $joinedTermCount = ExamPermitController::termSubjectCount($studentId, $currentAyId);
        } catch (Throwable $e) {
        }

        // ── Current academic context (enrollment-aware subject eligibility) ──
        $acadCtx = null;
        $eligibleCount = 0;
        try {
            if ($studentId > 0 && class_exists(\App\Core\CollegeEligibility::class)) {
                $acadCtx = \App\Core\CollegeEligibility::currentContext($studentId);
                if ($acadCtx !== null && ($acadCtx['has_active_enrollment'] ?? false)) {
                    $hasLvl = Database::value("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subject_classrooms' AND COLUMN_NAME = 'grade_level_id'");
                    $hasProg = Database::value("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subject_classrooms' AND COLUMN_NAME = 'program_id'");
                    $hasPer = \App\Core\CollegeEligibility::hasScPeriodCol();
                    $dashBand = (string) ($acadCtx['band'] ?? \App\Core\AcademicPeriod::BAND_COLLEGE);
                    $ecWhere = 'sc.is_active = 1 AND sc.status = \'open\' AND sc.teacher_id IS NOT NULL AND sc.academic_year_id = ?';
                    $ecBind = [(int) $acadCtx['ay_id']];
                    if ($dashBand === \App\Core\AcademicPeriod::BAND_BASIC) {
                        if ($hasPer) {
                            $ecWhere .= ' AND sc.period_code = ?';
                            $ecBind[] = (string) ($acadCtx['period'] ?? '');
                        } else {
                            $ecWhere .= ' AND 1 = 0';
                        }
                    } else {
                        $ecWhere .= ' AND sc.semester = ?';
                        $ecBind[] = (string) $acadCtx['semester'];
                    }
                    if ($hasLvl) {
                        $ecWhere .= ' AND sc.grade_level_id = ?';
                        $ecBind[] = (int) $acadCtx['level_id'];
                    }
                    if ($hasProg && !empty($acadCtx['program_id'])) {
                        $ecWhere .= ' AND (sc.program_id IS NULL OR sc.program_id = ?)';
                        $ecBind[] = (int) $acadCtx['program_id'];
                    }
                    $ecBind[] = $studentId;
                    $eligibleCount = (int) Database::value(
                        "SELECT COUNT(*) FROM subject_classrooms sc
                          WHERE {$ecWhere}
                            AND NOT EXISTS (SELECT 1 FROM classroom_members cm WHERE cm.classroom_id = sc.id AND cm.student_id = ?)
                            AND (sc.max_students > (SELECT COUNT(*) FROM classroom_members cm2 WHERE cm2.classroom_id = sc.id))",
                        $ecBind
                    );
                }
            }
        } catch (Throwable $e) {
            $acadCtx = $acadCtx ?? null;
            $eligibleCount = 0;
        }

        /* Payable filing for the Pay-now action (fail-open, read-only — same
           rule as the ledger: awaiting_payment first, then
           approved-with-balance). */
        $payAppId = null;
        try {
            if ($studentId > 0) {
                $payRow = Database::one(
                    "SELECT id FROM enrollment_applications
                      WHERE user_id = ? AND status IN ('awaiting_payment','approved')
                        AND (student_id = ? OR applicant_child_id = ?)
                      ORDER BY (status = 'awaiting_payment') DESC, created_at DESC LIMIT 1",
                    [(int) Auth::id(), $studentId, $studentId]
                );
                if (is_array($payRow) && isset($payRow['id'])) $payAppId = (int) $payRow['id'];
            }
        } catch (Throwable $e) {
            try {
                if ($studentId > 0) {
                    $payRow = Database::one(
                        "SELECT id FROM enrollment_applications
                          WHERE user_id = ? AND status IN ('awaiting_payment','approved') AND student_id = ?
                          ORDER BY (status = 'awaiting_payment') DESC, created_at DESC LIMIT 1",
                        [(int) Auth::id(), $studentId]
                    );
                    if (is_array($payRow) && isset($payRow['id'])) $payAppId = (int) $payRow['id'];
                }
            } catch (Throwable $e2) {
                $payAppId = null;
            }
        }

        $this->view('student/dashboard', [
            'title'          => 'My dashboard',
            'student'        => $student,
            'payAppId'       => $payAppId,
            'avgGrade'       => $avgGrade === null ? null : round((float) $avgGrade, 2),
            'balance'        => (float) max(0, (float) $balance),
            'dueAssignments' => $dueAssignments,
            'announcements'  => $announcements,
            // Enhanced permit vars
            'examPermits'    => $examPermits,
            'currentAy'      => $currentAy,
            // Google Classroom-style joined subjects
            'joinedClassrooms' => $joinedClassrooms,
            'classroomTasks'   => $classroomTasks,
            // Fully-approved exam permits per AY (badges on permit-gated exams)
            'approvedPermitPeriods' => self::rows(
                "SELECT exam_period, academic_year_id FROM exam_permits
                  WHERE student_id = ? AND status = 'fully_approved'",
                [$studentId]
            ),
            // Next-enrollment cycle
            'cycle'            => $cycle,
            // Join-subjects-first gate for exam permits
            'permitMinSubjects' => $permitMinSubjects,
            'joinedTermCount'   => $joinedTermCount,
            'subjectsReady'     => $joinedTermCount >= $permitMinSubjects,
            // Enrollment-aware subject eligibility
            'acadCtx'           => $acadCtx,
            'eligibleCount'     => $eligibleCount,
        ]);
    }

    /* ================================ PARENT ================================ */

    private function parentDashboard(): void
    {
        // Self-heal historical cases: approved parent-filed enrollments whose
        // child was never linked (linking used to be a manual registrar step
        // with no UI). Idempotent — already-linked rows are untouched.
        try {
            if (class_exists(\App\Core\GuardianLinks::class)) {
                \App\Core\GuardianLinks::repairForParent((int) Auth::id(), (int) Auth::id());
            }
        } catch (Throwable $e) {
        }
        // Banner context: does the parent have applications still in process,
        // or approved-but-unlinked leftovers the repair couldn't fix?
        $hasPendingApp = false;
        $hasApprovedUnlinked = false;
        try {
            $hasPendingApp = (bool) Database::one(
                "SELECT 1 FROM enrollment_applications WHERE user_id = ? AND status IN ('pending','accounting_approved','awaiting_payment','action_required') LIMIT 1",
                [(int) Auth::id()]
            );
            $hasApprovedUnlinked = (bool) Database::one(
                "SELECT 1 FROM enrollment_applications ea
                  WHERE ea.user_id = ? AND ea.status = 'approved' AND ea.student_id IS NOT NULL
                    AND NOT EXISTS (SELECT 1 FROM guardian_students gs JOIN guardians g ON g.id = gs.guardian_id
                                    WHERE g.user_id = ea.user_id AND gs.student_id = ea.student_id)
                  LIMIT 1",
                [(int) Auth::id()]
            );
        } catch (Throwable $e) {
        }
        // Open filings not yet linked to any child card (e.g. a parent's
        // newly filed child — linking happens at approval). Shown as their
        // own strip so the filing is visible from day one.
        $pendingFilings = [];
        try {
            $pendingFilings = self::rows(
                "SELECT ea.id, ea.application_no, ea.status, ea.created_at, gl.name AS level_name,
                        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(ks.first_name,''),' ',COALESCE(ks.last_name,''))), ''), '—') AS child_name
                   FROM enrollment_applications ea
                   LEFT JOIN grade_levels gl ON gl.id = ea.grade_level_applied_id
                   LEFT JOIN students ks ON ks.id = COALESCE(ea.applicant_child_id, ea.student_id)
                  WHERE ea.user_id = ?
                    AND ea.status IN ('pending','accounting_approved','awaiting_payment','action_required')
                    AND NOT EXISTS (SELECT 1 FROM guardian_students gs JOIN guardians g ON g.id = gs.guardian_id
                                     WHERE g.user_id = ea.user_id AND (gs.student_id = ea.student_id OR gs.student_id = ea.applicant_child_id))
                  ORDER BY ea.created_at DESC LIMIT 10",
                [(int) Auth::id()]
            );
        } catch (Throwable $e) {
            try {
                $pendingFilings = self::rows(
                    "SELECT ea.id, ea.application_no, ea.status, ea.created_at, gl.name AS level_name,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(ks.first_name,''),' ',COALESCE(ks.last_name,''))), ''), '—') AS child_name
                       FROM enrollment_applications ea
                       LEFT JOIN grade_levels gl ON gl.id = ea.grade_level_applied_id
                       LEFT JOIN students ks ON ks.id = ea.student_id
                      WHERE ea.user_id = ?
                        AND ea.status IN ('pending','accounting_approved','awaiting_payment','action_required')
                        AND NOT EXISTS (SELECT 1 FROM guardian_students gs JOIN guardians g ON g.id = gs.guardian_id
                                         WHERE g.user_id = ea.user_id AND gs.student_id = ea.student_id)
                      ORDER BY ea.created_at DESC LIMIT 10",
                    [(int) Auth::id()]
                );
            } catch (Throwable $e2) {
            }
        }
        $children = self::rows(
            "SELECT st.*, gl.name AS level_name, gl.code AS level_code, sec.name AS section_name,
                    " . \App\Core\GuardianLinks::nameSelect('st', 'u') . ",
                    (SELECT COUNT(*) FROM classroom_members cm WHERE cm.student_id = st.id) AS subject_count
               FROM guardians g
               JOIN guardian_students gs ON gs.guardian_id = g.id
               JOIN students st ON st.id = gs.student_id
               LEFT JOIN grade_levels gl ON gl.id = st.grade_level_id
               LEFT JOIN sections sec ON sec.id = st.section_id
               LEFT JOIN users u ON u.id = st.user_id
              WHERE g.user_id = ?
              ORDER BY st.id",
            [(int) Auth::id()]
        );
        // Per-child context: latest application, current period, balance.
        // Fail-open per child — one bad row never breaks the dashboard.
        foreach ($children as &$c) {
            $sid = (int) ($c['id'] ?? 0);
            $c['latest_app'] = null;
            $c['period_label'] = '';
            $c['balance'] = 0.0;
            try {
                $c['latest_app'] = Database::one(
                    "SELECT application_no, status, created_at FROM enrollment_applications
                      WHERE student_id = ? OR (applicant_child_id = ?)
                      ORDER BY created_at DESC, id DESC LIMIT 1",
                    [$sid, $sid]
                );
            } catch (Throwable $e) {
                try {
                    $c['latest_app'] = Database::one(
                        "SELECT application_no, status, created_at FROM enrollment_applications
                          WHERE student_id = ? ORDER BY created_at DESC, id DESC LIMIT 1",
                        [$sid]
                    );
                } catch (Throwable $e2) {
                }
            }
            try {
                if (class_exists(\App\Core\EnrollmentCycle::class)) {
                    $cur = \App\Core\EnrollmentCycle::currentPeriod($sid);
                    if ($cur !== null) {
                        $c['period_label'] = trim(($cur['level_name'] ?? '') . ' · ' . ($cur['period_label'] ?? '') . ' · ' . ($cur['ay_label'] ?? ''), ' ·');
                    }
                }
            } catch (Throwable $e) {
            }
            try {
                $bal = Database::value(
                    "SELECT COALESCE((SELECT SUM(amount) FROM student_charges WHERE student_id = ?),0)
                            - COALESCE((SELECT SUM(pa.amount_applied) FROM payment_allocations pa
                                         JOIN payments p ON p.id = pa.payment_id WHERE p.status = 'paid'
                                           AND pa.charge_id IN (SELECT id FROM student_charges WHERE student_id = ?)),0)",
                    [$sid, $sid]
                );
                $c['balance'] = (float) max(0, (float) $bal);
            } catch (Throwable $e) {
            }
        }
        unset($c);
        // Active parent announcements only (published + started + not expired).
        $announcements = Announcement::activeFor('parent', (int) Auth::id(), 4);
        // Overview stats (real counts, same account scope as everything else).
        $stats = ['pending' => 0, 'action' => 0, 'unpaid' => 0, 'enrolled' => 0];
        try {
            $uid = (int) Auth::id();
            $stats['pending'] = (int) Database::value(
                "SELECT COUNT(*) FROM enrollment_applications WHERE user_id = ? AND status IN ('pending','accounting_approved','awaiting_payment')", [$uid]
            );
            $stats['action'] = (int) Database::value(
                "SELECT COUNT(*) FROM enrollment_applications WHERE user_id = ? AND status = 'action_required'", [$uid]
            );
            $stats['unpaid'] = (int) Database::value(
                "SELECT COUNT(*) FROM enrollment_applications WHERE user_id = ? AND status = 'awaiting_payment'", [$uid]
            );
            foreach ($children as $c) {
                if (($c['status'] ?? '') === 'enrolled') $stats['enrolled']++;
            }
        } catch (Throwable $e) {
        }
        $parentInfo = null;
        try {
            $parentInfo = Database::one(
                "SELECT first_name, middle_name, last_name, email, phone FROM users WHERE id = ? LIMIT 1",
                [(int) Auth::id()]
            );
        } catch (Throwable $e) {
        }

        $this->view('parent/dashboard', [
            'title'         => 'Parent portal',
            'children'      => $children,
            'announcements' => $announcements,
            'hasPendingApp' => $hasPendingApp,
            'hasApprovedUnlinked' => $hasApprovedUnlinked,
            'pendingFilings' => $pendingFilings,
            'stats'         => $stats,
            'parentInfo'    => $parentInfo,
        ]);
    }

    /* ============================== internals ============================== */

    /** Scalar query that never throws (returns 0 on failure). */
    private static function scalar(string $sql, array $params = []): float|int
    {
        try {
            $v = Database::value($sql, $params);
            return $v === null ? 0 : (float) $v;
        } catch (Throwable $e) {
            storage_log('dashboard scalar failed: ' . $e->getMessage());
            return 0;
        }
    }

    /** List query that never throws (returns [] on failure). */
    private static function rows(string $sql, array $params = []): array
    {
        try {
            return Database::all($sql, $params);
        } catch (Throwable $e) {
            storage_log('dashboard rows failed: ' . $e->getMessage());
            return [];
        }
    }
}