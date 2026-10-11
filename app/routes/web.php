<?php
declare(strict_types=1);

/**
 * Web route table.
 *
 *   Auth (Module 1)   -> AuthController
 *   Dashboards        -> DashboardController
 *   Verify email link -> inline closure (target of PHPMailer link)
 */

use App\Controllers\AuthController;
use App\Controllers\ClassroomController;
use App\Controllers\ClearanceController;
use App\Controllers\DashboardController;
use App\Controllers\EnrollmentController;
use App\Controllers\ExamPermitController;
use App\Controllers\NotificationController;
use App\Controllers\HomeController;
use App\Controllers\ProfileController;
use App\Controllers\StudentPortalController;
use App\Controllers\Student\ClassRegistrationController;
use App\Controllers\Admin\ClassOfferingController as AdminClassOfferingController;
use App\Controllers\Admin\EnrollmentController as AdminEnrollmentController;
use App\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Controllers\Admin\TeacherController as AdminTeacherController;
use App\Controllers\Admin\UserController as AdminUserController;
use App\Controllers\Admin\EvaluationController as AdminEvaluationController;
use App\Controllers\Admin\StaffController as AdminStaffController;
use App\Controllers\Admin\AcademicCalendarController as AdminAcademicCalendarController;
use App\Controllers\Admin\AnalyticsController as AdminAnalyticsController;
use App\Controllers\RegistrarController;
use App\Controllers\AccountingController;
use App\Controllers\TeacherGradesController;
use App\Controllers\DocumentController;
use App\Controllers\StudentDocumentsController;
use App\Controllers\BlogController;
use App\Controllers\AnnouncementController;
use App\Controllers\Admin\BlogController as AdminBlogController;
use App\Controllers\Admin\AnnouncementController as AdminAnnouncementController;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Router;

return static function (Router $r): void {

    /* ------------------------------------------------------------- home -- */
    $r->get('/', [HomeController::class, 'index']);

    /* --------------------------------------- public legal & support ------- */
    $r->get('/privacy', [\App\Controllers\LegalController::class, 'privacy']);
    $r->get('/terms', [\App\Controllers\LegalController::class, 'terms']);
    $r->get('/support', [\App\Controllers\LegalController::class, 'support']);

    /* ------------------------------------------- auth: login / logout ---- */
    $r->get('/login',  [AuthController::class, 'showLogin']);
    $r->post('/login', [AuthController::class, 'login']);
    $r->post('/logout', [AuthController::class, 'logout']);

    /* ------------------------------------------- auth: registration ------ */
    $r->get('/register',  [AuthController::class, 'showRegister']);
    $r->post('/register', [AuthController::class, 'register']);
    // Secure teacher registration (invite-only, hashed single-use code)
    $r->get('/register-teacher',  [AuthController::class, 'showRegisterTeacher']);
    $r->post('/register-teacher', [AuthController::class, 'registerTeacher']);

    /* ------------------------------- auth: email verification flows ------ */
    $r->get('/verify-pending',      [AuthController::class, 'verifyPending']);
    $r->get('/verify-status',       [AuthController::class, 'verifyStatus']);
    $r->post('/verify/resend-link', [AuthController::class, 'resendLink']);
    $r->post('/verify/send-otp',    [AuthController::class, 'sendOtp']);
    $r->post('/verify/otp',         [AuthController::class, 'checkOtp']);

    /* --------------------------------------- auth: password reset -------- */
    $r->get('/forgot-password',  [AuthController::class, 'showForgotPassword']);
    $r->post('/forgot-password', [AuthController::class, 'sendResetLink']);
    $r->get('/reset-password',   [AuthController::class, 'showResetPassword']);
    $r->post('/reset-password',  [AuthController::class, 'doResetPassword']);

    /* ---------------- auth: MFA + new-device verification (step-up) ------- */
    $r->get('/mfa/verify',   [\App\Controllers\SecurityController::class, 'showMfaVerify']);
    $r->post('/mfa/verify',  [\App\Controllers\SecurityController::class, 'verifyMfa']);
    $r->get('/mfa/setup',    [\App\Controllers\SecurityController::class, 'showSetup']);
    $r->post('/mfa/setup',   [\App\Controllers\SecurityController::class, 'confirmSetup']);
    $r->get('/mfa/codes',    [\App\Controllers\SecurityController::class, 'showCodes']);
    $r->get('/login/device', [\App\Controllers\SecurityController::class, 'showDevicePending']);
    $r->get('/login/device/status', [\App\Controllers\SecurityController::class, 'deviceStatus']);
    $r->post('/login/device/resend', [\App\Controllers\SecurityController::class, 'resendDevice']);
    $r->get('/verify-device', [\App\Controllers\SecurityController::class, 'verifyDevice']);
    $r->post('/login/cancel', [\App\Controllers\SecurityController::class, 'cancelPending']);

    /* ------------------------------ security settings -------------------- */
    $r->get('/security',                             [\App\Controllers\SecurityController::class, 'index']);
    $r->post('/security/mfa/disable',                [\App\Controllers\SecurityController::class, 'disableMfa']);
    $r->post('/security/mfa/codes',                  [\App\Controllers\SecurityController::class, 'regenCodes']);
    $r->post('/security/devices/{id}/revoke',        [\App\Controllers\SecurityController::class, 'revokeDevice']);
    $r->post('/security/devices/{id}/forget',         [\App\Controllers\SecurityController::class, 'forgetDevice']);
    $r->post('/security/devices/remember',            [\App\Controllers\SecurityController::class, 'rememberDevice']);
    $r->post('/security/sessions/{id}/revoke',       [\App\Controllers\SecurityController::class, 'revokeSession']);
    $r->post('/security/sessions/revoke-others',     [\App\Controllers\SecurityController::class, 'revokeOtherSessions']);

    /* ------------------------------------------------ dashboards --------- */
    $r->get('/dashboard', [DashboardController::class, 'index']);

    /* ------------------------ site-wide notifications -------------------- */
    $r->get('/notifications',              [NotificationController::class, 'index']);
    $r->get('/notifications/feed',         [NotificationController::class, 'feed']);
    $r->post('/notifications/{id}/read',   [NotificationController::class, 'markRead']);
    $r->post('/notifications/read-all',    [NotificationController::class, 'markAllRead']);

    /* ------------------------ global topbar search (JSON) ------------------ */
    $r->get('/search/suggest', [\App\Controllers\SearchController::class, 'suggest']);

    /* ------------------------ blog / school news (public) ------------------ */
    $r->get('/news',                 [BlogController::class, 'index']);
    $r->get('/news/feed',            [BlogController::class, 'feed']);
    $r->get('/news/{slug}',          [BlogController::class, 'show']);

    /* ------------------------ announcements (role-filtered) ---------------- */
    $r->get('/announcements',              [AnnouncementController::class, 'index']);
    $r->get('/announcements/feed',         [AnnouncementController::class, 'feed']);
    $r->get('/announcements/{id}',         [AnnouncementController::class, 'show']);
    $r->post('/announcements/{id}/read',   [AnnouncementController::class, 'markRead']);

    /* ------------------------ admin: content management -------------------- */
    $r->get('/admin/content',                        [AdminBlogController::class, 'contentDashboard']);
    $r->get('/admin/blog',                           [AdminBlogController::class, 'index']);
    $r->get('/admin/blog/create',                    [AdminBlogController::class, 'create']);
    $r->post('/admin/blog',                          [AdminBlogController::class, 'store']);
    $r->get('/admin/blog/{id}/edit',                 [AdminBlogController::class, 'edit']);
    $r->post('/admin/blog/{id}',                     [AdminBlogController::class, 'update']);
    $r->get('/admin/blog/{id}/preview',              [AdminBlogController::class, 'preview']);
    $r->post('/admin/blog/{id}/delete',              [AdminBlogController::class, 'destroy']);
    $r->post('/admin/blog/{id}/archive',             [AdminBlogController::class, 'archive']);
    $r->get('/admin/announcements',                  [AdminAnnouncementController::class, 'index']);
    $r->get('/admin/announcements/create',           [AdminAnnouncementController::class, 'create']);
    $r->post('/admin/announcements',                 [AdminAnnouncementController::class, 'store']);
    $r->get('/admin/announcements/{id}',             [AdminAnnouncementController::class, 'show']);
    $r->get('/admin/announcements/{id}/edit',        [AdminAnnouncementController::class, 'edit']);
    $r->post('/admin/announcements/{id}',            [AdminAnnouncementController::class, 'update']);
    $r->post('/admin/announcements/{id}/delete',     [AdminAnnouncementController::class, 'destroy']);

    /* ------------------------ profile ---------------------------------- */
    $r->get('/profile',              [ProfileController::class, 'show']);
    $r->post('/profile/update',      [ProfileController::class, 'update']);
    $r->post('/profile/password',    [ProfileController::class, 'updatePassword']);

    /* ------------------------------------------ enrollment (Module 2) ---- */
    $r->get('/enroll',        [EnrollmentController::class, 'showForm']);
    $r->post('/enroll',       [EnrollmentController::class, 'submit']);
    $r->get('/enrollment/eligibility', [EnrollmentController::class, 'eligibility']);
    $r->get('/my-enrollments', [EnrollmentController::class, 'myApplications']);
    $r->get('/enroll/{id}/pay',  [EnrollmentController::class, 'checkout']);
    $r->post('/enroll/{id}/pay', [EnrollmentController::class, 'pay']);
    $r->post('/enroll/{id}/plan', [EnrollmentController::class, 'choosePlan']);
    $r->post('/enroll/{id}/plan/clear', [EnrollmentController::class, 'clearPlan']);
    $r->get('/enroll/{id}/payment-status', [EnrollmentController::class, 'paymentStatus']);
    // Official College Enrollment Form (one per academic period, from DB records)
    $r->get('/enroll/{id}/form', [DocumentController::class, 'printEnrollmentForm']);

    /* ---- permanent reusable enrollment documents (student portal) ---- */
    $r->get('/student/enrollment-docs',  [\App\Controllers\StudentEnrollmentDocsController::class, 'index']);
    $r->post('/student/enrollment-docs', [\App\Controllers\StudentEnrollmentDocsController::class, 'store']);

    /* ------------------------------- admin: enrollment queue --------------- */
    $r->get('/admin/enrollments',                        [AdminEnrollmentController::class, 'index']);
    $r->get('/admin/enrollments/{id}',                   [AdminEnrollmentController::class, 'show']);
    $r->post('/admin/enrollments/{id}/approve',          [AdminEnrollmentController::class, 'approve']);
    $r->post('/admin/enrollments/{id}/reject',           [AdminEnrollmentController::class, 'reject']);
    $r->post('/admin/enrollments/{id}/action-required',  [AdminEnrollmentController::class, 'actionRequired']);
    $r->post('/admin/enrollments/{id}/mark-paid',       [AdminEnrollmentController::class, 'markPaid']);

    /* ------------------------ admin: teacher management (secure) --------- */
    $r->get('/admin/teachers',                            [AdminTeacherController::class, 'index']);
    $r->get('/admin/teachers/invite',                     [AdminTeacherController::class, 'showInviteForm']);
    $r->post('/admin/teachers/invite',                    [AdminTeacherController::class, 'generateInvite']);
    $r->post('/admin/teachers/invite/{id}/revoke',        [AdminTeacherController::class, 'revokeInvite']);
    $r->post('/admin/teachers/invite/{id}/reveal',        [AdminTeacherController::class, 'revealInvite']);
    $r->post('/admin/teachers/create',                    [AdminTeacherController::class, 'createDirect']);

    /* ------------------------ admin: all accounts overview (new) ----------- */
    $r->get('/admin/users',                                 [AdminUserController::class, 'index']);
    $r->post('/admin/users/{id}/hold',                      [AdminUserController::class, 'hold']);
    $r->post('/admin/users/{id}/ban',                       [AdminUserController::class, 'ban']);
    $r->post('/admin/users/{id}/revoke',                    [AdminUserController::class, 'revoke']);
    $r->post('/admin/users/{id}/remove',                    [AdminUserController::class, 'remove']);
    $r->post('/admin/users/{id}/restore',                   [AdminUserController::class, 'restore']);
    $r->post('/admin/users/{id}/reset',                     [AdminUserController::class, 'resetAccount']);
    $r->post('/admin/users/{id}/factory-reset',             [AdminUserController::class, 'factoryReset']);
    $r->post('/admin/users/{id}/resend-verification',       [AdminUserController::class, 'resendVerification']);
    $r->get('/admin/backups',                               [AdminUserController::class, 'backups']);
    $r->post('/admin/backups/{id}/restore',                 [AdminUserController::class, 'restoreBackup']);
    $r->post('/admin/backups/{id}/delete',                  [AdminUserController::class, 'deleteBackup']);
    // Admin security overview (MFA status, devices, sessions — audited)
    $r->get('/admin/users/{id}/security',                 [AdminUserController::class, 'security']);
    $r->post('/admin/users/{id}/devices/revoke',          [AdminUserController::class, 'revokeDevices']);
    $r->post('/admin/users/{id}/sessions/revoke',         [AdminUserController::class, 'revokeSessions']);
    // Authorized Student Number correction (admin/registrar only, audit-logged)
    $r->post('/admin/students/{id}/student-no',              [AdminUserController::class, 'correctStudentNo']);

    /* ------------------------ admin: staff (registrar/accounting) ---------- */
    $r->get('/admin/staff',                                 [AdminStaffController::class, 'index']);
    $r->post('/admin/staff/create',                         [AdminStaffController::class, 'create']);

    /* ------------------------ registrar office --------------------------- */
    $r->get('/registrar/students',                          [RegistrarController::class, 'students']);
    $r->get('/registrar/students/{id}',                     [RegistrarController::class, 'studentShow']);
    $r->get('/registrar/grades',                            [RegistrarController::class, 'grades']);
    $r->post('/registrar/grades/{id}/review',               [RegistrarController::class, 'reviewGrade']);
    $r->get('/registrar/permits',                           [RegistrarController::class, 'permits']);
    $r->post('/registrar/permits/{id}/approve',             [RegistrarController::class, 'approvePermit']);
    $r->post('/registrar/permits/{id}/reject',              [RegistrarController::class, 'rejectPermit']);
    $r->get('/registrar/documents',                         [RegistrarController::class, 'documents']);
    $r->get('/registrar/documents/{id}',                    [RegistrarController::class, 'documentsShow']);
    $r->post('/registrar/documents/issue',                  [RegistrarController::class, 'issueDocument']);
    $r->post('/registrar/documents/{id}/release',           [RegistrarController::class, 'releaseDocument']);
    $r->get('/registrar/sheets',                            [RegistrarController::class, 'sheets']);
    $r->get('/registrar/sheets/{id}',                       [RegistrarController::class, 'sheetShow']);
    $r->post('/registrar/sheets/{id}/action',               [RegistrarController::class, 'sheetAction']);
    $r->get('/registrar/scales',                            [RegistrarController::class, 'scales']);
    $r->post('/registrar/scales',                           [RegistrarController::class, 'scaleStore']);
    $r->post('/registrar/scales/{id}/delete',               [RegistrarController::class, 'scaleDelete']);
    $r->post('/registrar/weights',                          [RegistrarController::class, 'weightsStore']);
    $r->post('/registrar/conversions',                      [RegistrarController::class, 'conversionsStore']);
    $r->post('/registrar/conversions/{id}/delete',          [RegistrarController::class, 'conversionDelete']);
    $r->get('/registrar/enrollments',                       [RegistrarController::class, 'enrollments']);
    $r->post('/registrar/enrollments/{id}/approve',         [RegistrarController::class, 'approveEnrollment']);
    $r->get('/registrar/clearances',                        [RegistrarController::class, 'clearances']);

    /* ------------------------ accounting office -------------------------- */
    $r->get('/accounting/enrollments',                      [AccountingController::class, 'enrollments']);
    $r->post('/accounting/enrollments/{id}/verify',         [AccountingController::class, 'verifyEnrollment']);
    $r->post('/accounting/enrollments/{id}/mark-paid',      [AccountingController::class, 'markPaid']);
    $r->get('/accounting/students',                         [AccountingController::class, 'students']);
    $r->get('/accounting/students/{id}',                    [AccountingController::class, 'studentAccount']);
    $r->get('/accounting/payments',                         [AccountingController::class, 'payments']);
    $r->post('/accounting/payments/{id}/approve',           [AccountingController::class, 'approvePayment']);
    $r->post('/accounting/payments/{id}/reject',            [AccountingController::class, 'rejectPayment']);
    $r->get('/accounting/clearances',                       [AccountingController::class, 'clearances']);
    $r->post('/accounting/clearances/{id}/status',          [AccountingController::class, 'updateClearance']);
    $r->get('/accounting/permits',                          [AccountingController::class, 'permits']);
    $r->post('/accounting/permits/{id}/approve',            [AccountingController::class, 'approvePermit']);
    $r->post('/accounting/permits/{id}/reject',             [AccountingController::class, 'rejectPermit']);
    // Permanent enrollment documents: verify / request resubmission (reason required)
    $r->get('/accounting/documents',                        [AccountingController::class, 'documents']);
    $r->post('/accounting/documents/{id}/verify',           [AccountingController::class, 'verifyDocument']);
    $r->post('/accounting/documents/{id}/request-resubmission', [AccountingController::class, 'requestResubmission']);
    // Per-application uploads (what students actually attached to applications)
    $r->post('/accounting/uploads/{id}/verify',             [AccountingController::class, 'verifyUpload']);
    $r->post('/accounting/uploads/{id}/request-resubmission', [AccountingController::class, 'requestUploadResubmission']);

    /* ------------------------ teacher grade sheets ----------------------- */
    $r->get('/teacher/grades',                              [TeacherGradesController::class, 'index']);
    $r->get('/teacher/grades/{classroom}/{period}',         [TeacherGradesController::class, 'sheet']);
    $r->post('/teacher/grades/{id}/save',                   [TeacherGradesController::class, 'save']);
    $r->post('/teacher/grades/{id}/apply-computed',         [TeacherGradesController::class, 'applyComputed']);
    $r->post('/teacher/grades/{id}/submit',                 [TeacherGradesController::class, 'submit']);

    /* ------------------------ student academic documents --------------- */
    $r->get('/student/documents',                           [StudentDocumentsController::class, 'index']);
    $r->post('/student/documents',                          [StudentDocumentsController::class, 'store']);
    $r->get('/student/documents/{id}',                      [StudentDocumentsController::class, 'show']);
    $r->post('/student/documents/{id}/request-again',       [StudentDocumentsController::class, 'requestAgain']);
    $r->post('/student/documents/{id}/cancel',              [StudentDocumentsController::class, 'cancel']);

    /* ------------------------ generated official documents ------------- */
    $r->get('/documents/{id}/print',                        [DocumentController::class, 'print']);

    /* ------------------------ admin: payments (PayRex) --------------------- */
    $r->get('/admin/payments',                              [AdminPaymentController::class, 'index']);
    $r->post('/admin/payments/{id}/approve',                [AdminPaymentController::class, 'approve']);
    $r->post('/admin/payments/{id}/reject',                 [AdminPaymentController::class, 'reject']);
    $r->get('/admin/tuition-plans',                         [\App\Controllers\Admin\TuitionPlanController::class, 'index']);
    $r->post('/admin/tuition-plans',                        [\App\Controllers\Admin\TuitionPlanController::class, 'store']);
    $r->post('/admin/tuition-plans/{id}/activate',          [\App\Controllers\Admin\TuitionPlanController::class, 'activate']);
    $r->post('/admin/payments/{id}/resend-receipt',         [AdminPaymentController::class, 'resendReceipt']);

    /* ------------------------ exam permits: multi-stage workflow --------- */
    $r->post('/student/permits/request',                  [ExamPermitController::class, 'request']);
    $r->post('/student/permits/{id}/request-again',         [ExamPermitController::class, 'requestAgain']);
    $r->get('/student/permits',                           [ExamPermitController::class, 'studentIndex']);
    $r->get('/student/permits/{id}',                      [ExamPermitController::class, 'studentShow']);
    $r->get('/student/permits/{id}/print',                [ExamPermitController::class, 'printCard']);
    $r->get('/admin/permits',                             [ExamPermitController::class, 'adminQueue']);
    $r->post('/admin/permits/{id}/approve-accounting',    [ExamPermitController::class, 'approveAccounting']);
    $r->post('/admin/permits/{id}/approve-registrar',     [ExamPermitController::class, 'approveRegistrar']);
    $r->post('/admin/permits/{id}/reject',                [ExamPermitController::class, 'reject']);
    $r->get('/teacher/permits',                           [ExamPermitController::class, 'teacherQueue']);
    $r->get('/teacher/permits/{id}',                      [ExamPermitController::class, 'teacherShow']);
    $r->post('/teacher/permits/{id}/verify',              [ExamPermitController::class, 'teacherVerify']);
    $r->get('/verify-permit/{token}',                     [ExamPermitController::class, 'verifyQr']);

    /* ------------------- Digital Clearance Cycle (Semester → Next Semester) ---- */
    $r->get('/student/clearances',                         [ClearanceController::class, 'studentIndex']);
    $r->post('/student/clearances/request',                [ClearanceController::class, 'request']);
    $r->get('/clearances/{id}/print',                      [ClearanceController::class, 'print']);
    $r->get('/verify-clearance/{token}',                   [ClearanceController::class, 'verifyQr']);
    $r->get('/teacher/clearances',                         [ClearanceController::class, 'teacherQueue']);
    $r->post('/teacher/clearances/{id}/sign',              [ClearanceController::class, 'teacherSign']);
    $r->post('/teacher/clearances/{id}/return',            [ClearanceController::class, 'teacherReturn']);
    $r->post('/registrar/clearances/{id}/review',          [ClearanceController::class, 'registrarReview']);
    $r->post('/registrar/clearances/{id}/override',        [ClearanceController::class, 'override']);
    $r->get('/registrar/re-enrollment',                    [RegistrarController::class, 'reenrollment']);
    $r->get('/admin/clearances',                           [ClearanceController::class, 'adminQueue']);

    /* ------------------- Admin-controlled Online Teachers Evaluation ---- */
    $r->get('/admin/evaluations',                          [AdminEvaluationController::class, 'index']);
    $r->post('/admin/evaluations',                         [AdminEvaluationController::class, 'store']);
    $r->post('/admin/evaluations/{id}/open',               [AdminEvaluationController::class, 'open']);
    $r->post('/admin/evaluations/{id}/close',              [AdminEvaluationController::class, 'close']);

    /* ------------------- Admin: academic calendar (centralized periods) ---- */
    $r->get('/admin/academic-calendar',                      [AdminAcademicCalendarController::class, 'index']);
    $r->post('/admin/academic-calendar',                     [AdminAcademicCalendarController::class, 'store']);
    $r->post('/admin/academic-calendar/structure',           [AdminAcademicCalendarController::class, 'setStructure']);
    $r->post('/admin/academic-calendar/{id}/toggle',         [AdminAcademicCalendarController::class, 'toggle']);
    $r->post('/admin/academic-calendar/{id}/delete',         [AdminAcademicCalendarController::class, 'destroy']);

    /* ------------------- Admin: school analytics (read-only JSON) ---------- */
    $r->get('/admin/analytics/data',                        [AdminAnalyticsController::class, 'data']);

    /* --------- Admin-managed Subjects (ADMIN creates -> assigns teacher -> student joins) --------- */
    $r->post('/teacher/classrooms/create',               [ClassroomController::class, 'teacherCreateBlocked']);
    $r->post('/teacher/classrooms/{id}/regenerate',      [ClassroomController::class, 'teacherRegenerateBlocked']);
    $r->post('/teacher/classrooms/{id}/delete',          [ClassroomController::class, 'teacherDeleteBlocked']);
    $r->get('/classrooms/{id}',                           [ClassroomController::class, 'show']);
    $r->get('/student/classrooms/preview',               [ClassroomController::class, 'previewJoin']);
    $r->post('/student/classrooms/join',                  [ClassroomController::class, 'studentJoin']);
    $r->post('/student/classrooms/request',               [ClassroomController::class, 'requestJoin']);
    $r->post('/student/classrooms/{id}/leave',            [ClassroomController::class, 'studentLeave']);
    $r->post('/student/join-requests/{id}/cancel',        [ClassroomController::class, 'cancelRequest']);
    $r->post('/teacher/join-requests/{id}/approve',       [ClassroomController::class, 'approveRequest']);
    $r->post('/teacher/join-requests/{id}/decline',       [ClassroomController::class, 'declineRequest']);
    $r->post('/teacher/classroom-tasks',                  [ClassroomController::class, 'teacherPostTask']);
    $r->get('/teacher/classroom-tasks/{id}/edit',        [ClassroomController::class, 'editTask']);
    $r->post('/teacher/classroom-tasks/{id}/update',     [ClassroomController::class, 'updateTask']);
    $r->post('/teacher/classroom-tasks/{id}/delete',     [ClassroomController::class, 'deleteTask']);
    $r->get('/classroom-tasks/{id}',                       [ClassroomController::class, 'showTask']);
    $r->get('/classroom-tasks/{id}/file',                 [ClassroomController::class, 'downloadTaskFile']);
    $r->post('/classroom-tasks/{id}/submit',               [ClassroomController::class, 'submitTask']);
    $r->post('/classroom-submissions/{id}/grade',          [ClassroomController::class, 'gradeSubmission']);

    /* ---- teacher: assignments & announcements (wrapper-preserving) ---- */
    $r->post('/teacher/assignments', static function() {
        \App\Core\Auth::requireRole('teacher');
        \App\Core\Csrf::guard();
        $offeringId = (int)($_POST['class_offering_id'] ?? $_POST['classroom_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        if ($offeringId<=0 || $title==='') { set_flash('error','Select class and enter task title.'); redirect('/dashboard'); }
        $exists = Database::one("SELECT id FROM subject_classrooms WHERE id=? AND teacher_id=(SELECT id FROM teachers WHERE user_id=? LIMIT 1)", [$offeringId, (int)Auth::id()]);
        if (!$exists) { set_flash('error','Invalid class — only your assigned subjects.'); redirect('/dashboard'); }
        // ── Date Validation: prevent invalid/past dates (e.g., 1888) ──
        $dueAtRaw = trim((string)($_POST['due_at'] ?? ''));
        $dueAt = $dueAtRaw !== '' ? str_replace('T',' ', $dueAtRaw) : null;
        if ($dueAt !== null && strlen($dueAt)===16) $dueAt .= ':00';
        if ($dueAt !== null) {
            $ts = strtotime($dueAt);
            if ($ts===false){ set_flash('error','Invalid due date format.'); redirect('/dashboard');}
            if ($ts <= time()+300){ set_flash('error','Please choose a future due date — at least 5 minutes from now.'); redirect('/dashboard');}
            $y=(int)date('Y',$ts); $curY=(int)date('Y'); if($y<$curY || $y>$curY+5){ set_flash('error','Due date year must be between '.$curY.' and '.($curY+5).'.'); redirect('/dashboard');}
        }
        try {
            Database::insert('classroom_tasks', [
                'classroom_id'=> $offeringId,
                'title'            => $title,
                'instructions'     => trim((string)($_POST['instructions'] ?? '')) ?: null,
                'due_at'           => $dueAt,
                'posted_by'        => (int)Auth::id(),
            ]);
            set_flash('success','Task posted: ' . $title);
        } catch (Throwable $e) { storage_log('teacher assign create failed: '.$e->getMessage()); set_flash('error','Could not post task.'); }
        redirect('/dashboard');
    });
    $r->post('/teacher/announcements', static function() {
        \App\Core\Auth::requireRole('teacher','admin');
        \App\Core\Csrf::guard();
        $title = trim((string)($_POST['title'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        $audience = trim((string)($_POST['audience'] ?? 'students'));
        if ($title===''||$content==='') { set_flash('error','Title and content required.'); redirect('/dashboard'); }
        if (!in_array($audience, ['everyone','students','parents','teachers','staff','class'], true)) $audience='students';
        try {
            Database::insert('announcements', [
                'title'     => $title,
                'content'   => $content,
                'audience'  => $audience,
                'posted_by' => (int)Auth::id(),
                'publish_at'=> date('Y-m-d H:i:s'),
            ]);
            // Fan-out bell notification (shows on every page)
            try {
                if (in_array($audience, ['students','everyone','class'], true)) {
                    if (\App\Core\Auth::is('teacher')) {
                        $t = Database::one('SELECT id FROM teachers WHERE user_id = ? LIMIT 1', [(int)Auth::id()]);
                        if ($t) {
                            $members = Database::all(
                                "SELECT DISTINCT s.user_id FROM classroom_members cm JOIN students s ON s.id = cm.student_id JOIN subject_classrooms sc ON sc.id = cm.classroom_id WHERE sc.teacher_id = ? LIMIT 500",
                                [(int)$t['id']]
                            );
                            \App\Controllers\NotificationController::pushMany(
                                array_map(static fn($m) => (int) ($m['user_id'] ?? 0), $members),
                                'Announcement: ' . $title, mb_substr($content, 0, 140), 'info', '/dashboard'
                            );
                        }
                    } else {
                        \App\Controllers\NotificationController::pushToStudents('Announcement: ' . $title, mb_substr($content, 0, 140), 'info', '/dashboard');
                    }
                }
            } catch (Throwable $e) {}
            set_flash('success','Announcement published: ' . $title);
        } catch (Throwable $e) { storage_log('announce create failed: '.$e->getMessage()); set_flash('error','Could not publish.'); }
        redirect('/dashboard');
    });
    // Legacy quick-action targets (now integrated with the real modules)
    $r->get('/teacher/grades/{id}', static function(array $p){ Auth::requireRole('teacher'); redirect('/teacher/grades'); });
    $r->get('/teacher/roster/{id}', static function(array $p){ Auth::requireRole('teacher'); redirect('/classrooms/' . urlencode((string)($p['id'] ?? ''))); });
    $r->get('/teacher/assignments/create', static function(){ Auth::requireRole('teacher'); redirect('/dashboard'); });
    $r->get('/teacher/announcements/create', static function(){ Auth::requireRole('teacher','admin'); redirect('/dashboard'); });
    $r->get('/teacher/submissions/{id}', static function(array $p){ Auth::requireRole('teacher'); set_flash('info','Submission #'.($p['id']??'').' grading — integrate with submissions module.'); redirect('/dashboard'); });
    $r->get('/student/payments', static function(){ Auth::requireRole('student'); redirect('/student/ledger'); });

    /* ------------------------ parent portal ------------------------------ */
    $r->get('/parent/children/{id}',                     [\App\Controllers\ParentController::class, 'show']);
    $r->get('/parent/applications',                      [\App\Controllers\ParentController::class, 'applications']);

    /* ------------------------ student portal service hub ------------------- */
    $r->get('/student/class-offerings',                [ClassRegistrationController::class, 'index']);
    $r->get('/student/class-offerings/{id}',           [ClassRegistrationController::class, 'show']);
    $r->post('/student/class-offerings/{id}/register', [ClassRegistrationController::class, 'register']);
    $r->get('/student/my-classes',                     [ClassRegistrationController::class, 'myClasses']);
    $r->post('/student/my-classes/{id}/drop',          [ClassRegistrationController::class, 'drop']);
    $r->get('/student/schedule',                       [StudentPortalController::class, 'schedule']);
    $r->get('/student/grades',                         [StudentPortalController::class, 'grades']);
    $r->get('/student/ledger',                         [StudentPortalController::class, 'ledger']);
    $r->get('/student/flowchart',                      [StudentPortalController::class, 'flowchart']);
    $r->get('/student/evaluations',                    [StudentPortalController::class, 'evaluations']);
    $r->post('/student/evaluations',                   [StudentPortalController::class, 'submitEvaluation']);
    $r->get('/teacher/class-offerings/{id}/roster',    [ClassRegistrationController::class, 'teacherRoster']);

    /* ------------------------ admin: subjects & class-offerings (official) ---------- */
    $r->get('/admin/class-offerings',                  [AdminClassOfferingController::class, 'index']);
    $r->post('/admin/class-offerings',                 [AdminClassOfferingController::class, 'store']);
    $r->get('/admin/class-offerings/{id}',             [AdminClassOfferingController::class, 'show']);
    $r->post('/admin/class-offerings/{id}/update',     [AdminClassOfferingController::class, 'update']);
    $r->post('/admin/class-offerings/{id}/assign',     [AdminClassOfferingController::class, 'assign']);
    $r->post('/admin/class-offerings/{id}/remove-teacher', [AdminClassOfferingController::class, 'removeTeacher']);
    $r->post('/admin/class-offerings/{id}/regenerate', [AdminClassOfferingController::class, 'regenerateCode']);
    $r->post('/admin/class-offerings/{id}/status',     [AdminClassOfferingController::class, 'setStatus']);
    $r->post('/admin/class-offerings/{id}/delete',     [AdminClassOfferingController::class, 'destroy']);
    $r->post('/admin/class-offerings/{id}/remove-student', [AdminClassOfferingController::class, 'removeMember']);
    $r->post('/admin/registrations/{id}/remove',       [AdminClassOfferingController::class, 'removeRegistration']);

    /* ----------------------------------------------------------- ops ----- */
    $r->get('/health', static function () {
        json_response(['ok' => true, 'time' => date('c'), 'app' => APP_NAME]);
    });

    /* ------------------- live sync API (EduLive transport) --------------- */
    $r->get('/api/live/digest', [\App\Controllers\LiveController::class, 'digest']);

    /* Landing page for the emailed verification LINK (Module 1). */
    $r->get('/verify-email', static function () {
        header('Content-Type: text/html; charset=utf-8');
        $result = Auth::consumeVerification((string) ($_GET['token'] ?? ''));

        $ok    = !empty($result['ok']);
        $color = $ok ? '#166534' : '#b91c1c';
        $icon  = $ok ? '&#10004;' : '&#10006;';
        $next  = $ok
            ? '<a href="' . e(url('/login')) . '" style="display:inline-block;margin-top:18px;'
              . 'background:#2563eb;color:#fff;text-decoration:none;padding:10px 22px;border-radius:6px;">'
              . 'Go to sign in</a>'
            : '<a href="' . e(url('/login')) . '" style="color:#2563eb;">Back to sign in</a>';

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Email verification - ' . e(APP_NAME) . '</title></head>'
            . '<body style="margin:0;font-family:Segoe UI,Arial,sans-serif;background:#f3f5f9;">'
            . '<div style="max-width:520px;margin:10vh auto;text-align:center;background:#fff;'
            . 'padding:40px;border-radius:12px;border:1px solid #e2e6ee;">'
            . '<div style="font-size:42px;color:' . $color . ';">' . $icon . '</div>'
            . '<h1 style="color:#1e293b;">Email verification</h1>'
            . '<p style="color:' . $color . ';font-weight:600;">' . e($result['message'] ?? '') . '</p>'
            . $next
            . '</div></body></html>';
    });
};
