<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\StudentDocs;
use Throwable;

/**
 * Student permanent-document portal (EXTENSION).
 * Uses StudentDocs::unified() — the ONE source of truth shared with the
 * enrollment page — so an upload recorded on any of the student's
 * applications is recognized here instead of shown as "missing".
 * Works even before a students row exists (matched by user_id).
 */
final class StudentEnrollmentDocsController extends Controller
{
    /**
     * Acting student: own row for students; for parents the owned linked
     * child (?child_id= validated, else first child). A forged id bounces —
     * never silently swaps to another child.
     * @return array{row:?array,children:array,tampered:bool}
     */
    private function actingStudent(): array
    {
        $uid = (int) Auth::id();
        if (!Auth::is('parent')) {
            $s = Database::one("SELECT id FROM students WHERE user_id = ? LIMIT 1", [$uid]);
            return ['row' => $s, 'children' => [], 'tampered' => false];
        }
        if (!class_exists(\App\Core\GuardianLinks::class)) {
            return ['row' => null, 'children' => [], 'tampered' => false];
        }
        try {
            $children = \App\Core\GuardianLinks::linkedChildren($uid);
        } catch (Throwable $e) {
            $children = [];
        }
        $asked = (int) ($_GET['child_id'] ?? $_POST['child_id'] ?? 0);
        if ($asked > 0) {
            try {
                $owned = \App\Core\GuardianLinks::ownedChild($uid, $asked);
            } catch (Throwable $e) {
                $owned = null;
            }
            if ($owned !== null) {
                return ['row' => $owned, 'children' => $children, 'tampered' => false];
            }
            return ['row' => null, 'children' => $children, 'tampered' => true];
        }
        if ($children === []) {
            return ['row' => null, 'children' => [], 'tampered' => false];
        }
        try {
            $first = \App\Core\GuardianLinks::ownedChild($uid, 0);
        } catch (Throwable $e) {
            $first = null;
        }
        return ['row' => $first, 'children' => $children, 'tampered' => false];
    }

    private function myStudentId(): int
    {
        $s = Database::one("SELECT id FROM students WHERE user_id = ? LIMIT 1", [(int) Auth::id()]);
        return $s ? (int) $s['id'] : 0;
    }

    public function index(array $params = []): void
    {
        Auth::requireRole('student', 'parent');
        if (!class_exists(StudentDocs::class)) {
            set_flash('error', 'Document store is being set up — please try again later or contact the Registrar.');
            redirect('/dashboard');
        }
        $uid = (int) Auth::id();
        $acting = $this->actingStudent();
        if (!empty($acting['tampered'])) {
            set_flash('error', 'That child is not linked to your guardian account.');
            redirect('/dashboard');
        }
        $sid = $acting['row'] !== null ? (int) $acting['row']['id'] : 0;
        // Level-aware requirements: ECCD is kinder-only, Form 137/138 and good
        // moral vary by level (StudentDocs::requiredForLevel). College students
        // must never be asked for preschool records.
        $level = null;
        try {
            if ($sid > 0) {
                $level = Database::one(
                    "SELECT gl.code, gl.category FROM students s LEFT JOIN grade_levels gl ON gl.id = s.grade_level_id WHERE s.id = ? LIMIT 1",
                    [$sid]
                );
            }
            if ($level === null) {
                $appScopeId = ($acting['row'] !== null && Auth::is('parent')) ? (int) $acting['row']['id'] : 0;
                $level = Database::one(
                    "SELECT gl.code, gl.category FROM enrollment_applications ea JOIN grade_levels gl ON gl.id = ea.grade_level_applied_id
                      WHERE (ea.user_id = ? OR (? > 0 AND (ea.student_id = ? OR ea.applicant_child_id = ?)))
                      ORDER BY ea.id DESC LIMIT 1",
                    [$uid, $appScopeId, $appScopeId, $appScopeId]
                );
            }
            if ($level !== null && trim((string) ($level['code'] ?? '')) === '' && trim((string) ($level['category'] ?? '')) === '') $level = null;
        } catch (Throwable $e) {
            $level = null;
        }
        $levelCtx = $level !== null ? ['level' => $level, 'applicant' => 'new'] : null;
        $unified = StudentDocs::unified($sid, $uid);
        $needed = StudentDocs::neededForEnrollment($sid, $uid, 0, false, $levelCtx);
        $required = $level !== null ? StudentDocs::requiredForLevel($level) : StudentDocs::REQUIRED;
        $student = $sid > 0 ? Database::one(
            "SELECT s.student_no, u.first_name, u.last_name FROM students s LEFT JOIN users u ON u.id = s.user_id WHERE s.id = ? LIMIT 1",
            [$sid]
        ) : null;
        $this->view('student/enrollment_docs', [
            'title' => 'My Enrollment Documents',
            'student' => $student, 'studentId' => $sid,
            'unified' => $unified, 'needed' => $needed,
            'types' => StudentDocs::TYPES,
            'required' => $required, 'level' => $level,
            'children' => $acting['children'],
            'activeChildId' => $acting['row'] !== null ? (int) $acting['row']['id'] : 0,
        ]);
    }

    public function store(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('student', 'parent');
        $uid = (int) Auth::id();
        $acting = $this->actingStudent();
        if (!empty($acting['tampered'])) {
            set_flash('error', 'That child is not linked to your guardian account.');
            redirect('/dashboard');
        }
        $sid = $acting['row'] !== null ? (int) $acting['row']['id'] : 0;
        $back = '/student/enrollment-docs' . ($acting['row'] !== null && Auth::is('parent') ? '?child_id=' . (int) $acting['row']['id'] : '');
        $docType = trim((string) ($_POST['doc_type'] ?? ''));
        if (!array_key_exists($docType, StudentDocs::TYPES)) {
            set_flash('error', 'Select a valid document type.');
            redirect($back);
        }
        // Permanent versions need a students row; pre-approval uploads live
        // on the enrollment application itself (already recognized below).
        if ($sid <= 0) {
            set_flash('info', 'Your student record is created on approval — files attached to your enrollment application are already with the reviewers and shown below.');
            redirect($back);
        }
        // Backend rule: new upload allowed only when missing / rejected, or
        // accounting explicitly flagged needs_resubmission. Anything already
        // on file (either table) or under review is reused, not re-uploaded.
        // Level rule: a missing document that is NOT required for this
        // student's level (e.g. ECCD checklist for college) is rejected —
        // preschool records on a college file look unprofessional.
        $uni = StudentDocs::unified($sid, $uid);
        $curStatus = $uni[$docType]['status'] ?? 'missing';
        if ($curStatus === 'missing') {
            try {
                $lv = $sid > 0 ? Database::one(
                    "SELECT gl.code, gl.category FROM students s LEFT JOIN grade_levels gl ON gl.id = s.grade_level_id WHERE s.id = ? LIMIT 1",
                    [$sid]
                ) : null;
                $req = $lv !== null ? StudentDocs::requiredForLevel($lv) : StudentDocs::REQUIRED;
            } catch (Throwable $e) {
                $req = StudentDocs::REQUIRED;
            }
            $hasHistory = !empty($uni[$docType]['history']);
            if (!in_array($docType, $req, true) && !$hasHistory) {
                set_flash('info', StudentDocs::label($docType) . ' is not required for your level — no upload needed. It will not be listed as missing.');
                redirect($back);
            }
        }
        if (in_array($curStatus, ['verified', 'submitted'], true)) {
            set_flash('info', StudentDocs::label($docType) . ' is already on file (' . $curStatus . ') — no re-upload needed. It will be reused automatically.');
            redirect($back);
        }
        if (empty($_FILES['document']) || ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            set_flash('error', 'Choose a file to upload (PDF/JPG/PNG/WebP, max 10 MB).');
            redirect($back);
        }
        $file = $_FILES['document'];
        if ($file['size'] > UPLOAD_MAX_BYTES) {
            set_flash('error', 'File too large — max 10 MB.');
            redirect($back);
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : ($file['type'] ?? 'application/octet-stream');
        if ($finfo) finfo_close($finfo);
        if (!in_array($mime, UPLOAD_ALLOWED_MIME, true) && !str_starts_with((string) $mime, 'image/')) {
            set_flash('error', 'Accepted: PDF, JPG, PNG, WebP.');
            redirect($back);
        }
        $uploadDir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . 'documents';
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
        $ext = \App\Core\Uploads::safeExt((string) $file['name'], (string) $mime);
        if ($ext === null) {
            set_flash('error', 'File extension not allowed. Accepted: PDF, JPG, PNG, WebP.');
            redirect($back);
        }
        $stored = \App\Core\Uploads::storedName('stu_' . $sid . '_' . $docType . '_v' . (StudentDocs::nextVersion($sid, $docType)), $ext);
        $dest = $uploadDir . DIRECTORY_SEPARATOR . $stored;
        if (!is_uploaded_file($file['tmp_name']) || !move_uploaded_file($file['tmp_name'], $dest)) {
            set_flash('error', 'Could not save upload. Try again.');
            redirect($back);
        }
        try {
            $newId = StudentDocs::storeVersion(
                $sid, $docType, (string) $file['name'], 'documents/' . $stored, (string) $mime, (int) $file['size']
            );
            Database::insert('audit_logs', [
                'actor_user_id' => (int) Auth::id(), 'action' => 'enrollment_doc.uploaded',
                'entity_type' => 'student_documents', 'entity_id' => $newId, 'ip_address' => client_ip(),
            ]);
            // Notify accounting reviewers.
            try {
                NotificationController::pushToAccounting(
                    'Document uploaded: ' . StudentDocs::label($docType),
                    'Student #' . $sid . ' uploaded ' . StudentDocs::label($docType) . ' (v' . StudentDocs::nextVersion($sid, $docType) - 1 . ') — review in Accounting Documents.',
                    'info', '/accounting/documents'
                );
            } catch (Throwable $e) {}
            set_flash('success', StudentDocs::label($docType) . ' uploaded — previous versions kept in history below. Accounting will review it.');
        } catch (Throwable $e) {
            storage_log('student enrollment doc upload failed: ' . $e->getMessage());
            set_flash('error', 'Upload failed: ' . $e->getMessage());
        }
        redirect($back);
    }
}

