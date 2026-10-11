<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use Throwable;

/**
 * Google Classroom-style Subject Invite (Teacher creates → Student joins via code)
 * Teacher: POST /teacher/classrooms/create (generates invite_code X7K9M2)
 * Student: POST /student/classrooms/join (enter code)
 */
final class ClassroomController extends Controller
{
    /**
     * Exam terms that require an approved exam permit before a student may
     * open or answer the activity. Teachers mark an activity as an exam by
     * tagging it with one of these grading periods (Prelim/Midterm/
     * Prefinal/Finals) — untagged activities stay open to all members.
     */
    public const EXAM_PERIODS = ['Prelim', 'Midterm', 'Prefinal', 'Finals'];

    /** Exam period a task is tagged with, or null when it is not an exam. */
    public static function taskExamPeriod(array $task): ?string
    {
        $code = trim((string) ($task['period_code'] ?? ''));
        return in_array($code, self::EXAM_PERIODS, true) ? $code : null;
    }

    /** Fully-approved permit held by the student for (AY, period)? */
    public static function studentHasExamPermit(int $studentId, int $ayId, string $examPeriod): bool
    {
        if ($studentId <= 0 || $ayId <= 0 || $examPeriod === '') return false;
        try {
            return Database::one(
                "SELECT id FROM exam_permits WHERE student_id = ? AND academic_year_id = ? AND exam_period = ? AND status = 'fully_approved' LIMIT 1",
                [$studentId, $ayId, $examPeriod]
            ) !== null;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Validate + store a teacher's task attachment.
     * @return array{rel:string,orig:string,mime:string,size:int}|array{} empty when no file sent.
     * @throws \RuntimeException on any upload problem.
     */
    private static function storeTaskFile(array $file, string $prefix): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [];
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('File upload error.');
        }
        if (($file['size'] ?? 0) > UPLOAD_MAX_BYTES) {
            throw new \RuntimeException('File too large (max 10 MB).');
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : 'application/octet-stream';
        if ($finfo) finfo_close($finfo);
        $allowedExtra = ['text/plain', 'application/zip', 'application/x-zip-compressed'];
        if (!in_array($mime, UPLOAD_ALLOWED_MIME, true) && !str_starts_with((string) $mime, 'image/') && !in_array($mime, $allowedExtra, true)) {
            throw new \RuntimeException('File type not allowed (' . $mime . '). Allowed: PDF, JPG, PNG, WebP, DOC/DOCX, TXT, ZIP.');
        }
        $ext = \App\Core\Uploads::safeExt((string) ($file['name'] ?? ''), (string) $mime);
        if ($ext === null) {
            throw new \RuntimeException('File extension not allowed. Allowed: PDF, JPG, PNG, WebP, DOC/DOCX, TXT, ZIP.');
        }
        $dir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . 'task_files';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $stored = \App\Core\Uploads::storedName($prefix, $ext);
        $dest = $dir . DIRECTORY_SEPARATOR . $stored;
        if (!is_uploaded_file($file['tmp_name']) || !move_uploaded_file($file['tmp_name'], $dest)) {
            throw new \RuntimeException('Could not save file.');
        }
        return ['rel' => 'task_files/' . $stored, 'orig' => (string) ($file['name'] ?? 'file'), 'mime' => (string) $mime, 'size' => (int) ($file['size'] ?? 0)];
    }

    /** Best-effort unlink of a task attachment (path-jail checked). */
    private static function deleteTaskFile(?string $rel): void
    {
        $rel = trim((string) $rel);
        if ($rel === '') return;
        try {
            $base = realpath(rtrim(UPLOAD_DIR, '/\\'));
            $full = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($rel, '/\\'));
            $real = realpath($full);
            if ($real !== false && $base !== false && str_starts_with($real, $base) && is_file($real)) {
                @unlink($real);
            }
        } catch (Throwable $e) {
        }
    }
    /**
     * Course/section guard: a BSIT student must not join a nursing section
     * even if they somehow get its code. Returns error message or null.
     * Fail-open for generic section names (only hard-blocks when the
     * classroom prefix is a known program code different from the student's).
     */
    private static function sectionMismatch(?array $student, array $classroom): ?string
    {
        $classSec = strtoupper(trim((string) ($classroom['section_name'] ?? '')));
        if ($classSec === '') return null;
        $prog = strtoupper(trim((string) ($student['program_code'] ?? $student['program_id'] ?? '')));
        // program_id fallback is numeric — treat as unknown (fail-open below).
        if ($prog !== '' && ctype_digit($prog)) $prog = '';
        $sec = strtoupper(trim((string) ($student['section_label'] ?? '')));
        if ($prog === '' && $sec === '') return null;
        if ($sec !== '' && $sec === $classSec) return null;
        $classPre = preg_split('/[-\s\/]+/', $classSec)[0] ?? '';
        $secPre = $sec !== '' ? (preg_split('/[-\s\/]+/', $sec)[0] ?? '') : '';
        if ($prog !== '' && $classPre !== '' && $prog === $classPre) return null;
        if ($secPre !== '' && $classPre !== '' && $secPre === $classPre) return null;
        try {
            $known = Database::all("SELECT code FROM programs WHERE is_active = 1 LIMIT 100");
            $codes = [];
            foreach ($known as $k) {
                $c = strtoupper(trim((string) ($k['code'] ?? '')));
                if ($c !== '') $codes[] = $c;
            }
            if ($classPre !== '' && in_array($classPre, $codes, true)) {
                if ($prog !== '' && $prog !== $classPre) {
                    return 'This subject (' . ($classroom['subject_code'] ?? 'class') . ' — ' . $classroom['section_name'] . ') is not for your course' . ' (' . $prog . '). Ask your teacher for the correct invite code.';
                }
                if ($prog === '' && $secPre !== '' && $secPre !== $classPre) {
                    return 'This subject (' . $classroom['section_name'] . ') is not for your section (' . $student['section_label'] . '). Ask your teacher for the correct invite code.';
                }
            }
        } catch (Throwable $e) {
        }
        return null;
    }

    private static function studentCourseProfile(int $studentId): ?array
    {
        try {
            return Database::one(
                "SELECT s.*, p.code AS program_code, sec.name AS section_label
                   FROM students s
                   LEFT JOIN programs p ON p.id = s.program_id
                   LEFT JOIN sections sec ON sec.id = s.section_id
                  WHERE s.id = ? LIMIT 1",
                [$studentId]
            );
        } catch (Throwable $e) {
            return null;
        }
    }

    private function generateInviteCode(int $len = 6): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'; // no 0/O/1/I
        $max = strlen($alphabet) - 1;
        $code = '';
        for ($i = 0; $i < $len; $i++) $code .= $alphabet[random_int(0, $max)];
        // Optional dash for readability when len >=6: XXX-XXX
        if ($len === 6) $code = substr($code, 0, 3) . substr($code, 3, 3);
        // For len 7-8 keep as is
        return $code;
    }

    /* ============ TEACHER self-create DISABLED (Admin-managed only) ============ */
    public function teacherCreateBlocked(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('teacher');
        set_flash('error', 'Subject creation is Admin-managed. Contact the admin to create/assign your class — it will appear here automatically.');
        redirect('/dashboard');
    }

    public function teacherRegenerateBlocked(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('teacher');
        set_flash('error', 'Invite codes are Admin-managed. Ask the admin to regenerate the student code.');
        redirect('/dashboard');
    }

    public function teacherDeleteBlocked(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('teacher');
        set_flash('error', 'Subjects are Admin-managed and cannot be deleted by teachers.');
        redirect('/dashboard');
    }

    /* Teacher: post activity/task to a subject classroom (Google Classroom) */
    public function teacherPostTask(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('teacher');
        $teacher = Database::one("SELECT id FROM teachers WHERE user_id = ? LIMIT 1", [(int)Auth::id()]);
        if (!$teacher) { set_flash('error','Teacher not found'); redirect('/dashboard'); }
        $teacherId = (int)$teacher['id'];

        $classroomId = (int)($_POST['classroom_id'] ?? 0);
        $title       = trim((string)($_POST['title'] ?? ''));
        $instructions= trim((string)($_POST['instructions'] ?? ''));
        $categoryId  = (int)($_POST['category_id'] ?? 0);
        $maxScore    = trim((string)($_POST['max_score'] ?? '100'));
        $periodCode  = trim((string)($_POST['period'] ?? ''));
        $dueAtRaw    = trim((string)($_POST['due_at'] ?? ''));
        // ── Date Validation: prevent invalid or past due dates (shared with edit) ──
        $dueCheck = self::normalizeDue($dueAtRaw);
        if (!$dueCheck['ok']) {
            set_flash('error', $dueCheck['error']);
            redirect('/dashboard');
        }
        $dueAt = $dueCheck['due_at'];

        if ($classroomId <= 0 || $title === '') {
            set_flash('error','Select a subject and enter a title.');
            redirect('/dashboard');
        }
        $catRow = $categoryId > 0 ? Database::one("SELECT id, code FROM assessment_categories WHERE id = ? AND is_active = 1 LIMIT 1", [$categoryId]) : null;
        $maxCheck = \App\Core\GradeEngine::validateMax($maxScore, $catRow['code'] ?? null);
        if (!$maxCheck['ok']) {
            set_flash('error', $maxCheck['error']);
            redirect('/dashboard');
        }
        $maxScore = (string) $maxCheck['value'];
        // Optional teacher-decided passing raw score (e.g. total 50, passing 30).
        $passingRaw = trim((string)($_POST['passing_score'] ?? ''));
        $passCheck = \App\Core\GradeEngine::validatePassing($passingRaw, (float) $maxScore);
        if (!$passCheck['ok']) {
            set_flash('error', $passCheck['error']);
            redirect('/dashboard');
        }
        $periodRow = $periodCode !== '' ? Database::one("SELECT id FROM grade_periods WHERE code = ? AND is_active = 1 LIMIT 1", [$periodCode]) : null;
        if ($periodCode !== '' && $periodRow === null) {
            set_flash('error','Invalid grading period.');
            redirect('/dashboard');
        }
        $class = Database::one("SELECT id, subject_code, subject_title FROM subject_classrooms WHERE id = ? AND teacher_id = ? LIMIT 1", [$classroomId, $teacherId]);
        if (!$class) { set_flash('error','Invalid subject or not yours.'); redirect('/dashboard'); }

        // Optional exam paper / worksheet for students (same 10 MB rule as submissions).
        $attach = [];
        try {
            if (!empty($_FILES['task_file'])) {
                $attach = self::storeTaskFile($_FILES['task_file'], 'task_' . $classroomId);
            }
        } catch (\RuntimeException $e) {
            set_flash('error', $e->getMessage());
            redirect('/dashboard');
        }

        try {
            $taskRow = [
                'classroom_id' => $classroomId,
                'title'        => $title,
                'instructions' => $instructions ?: null,
                'category_id'  => $catRow ? (int) $catRow['id'] : null,
                'max_score'    => round((float) $maxScore, 2),
                'period_id'    => $periodRow ? (int) $periodRow['id'] : null,
            ];
            if ($passCheck['value'] !== null && \App\Core\GradeEngine::passingColExists()) {
                $taskRow['passing_score'] = $passCheck['value'];
            }
            $taskId = Database::insert('classroom_tasks', $taskRow + [
                'due_at'       => $dueAt,
                'posted_by'    => (int)Auth::id(),
                'file_path'    => $attach['rel'] ?? null,
                'original_filename' => $attach['orig'] ?? null,
                'mime_type'    => $attach['mime'] ?? null,
                'file_size_bytes' => $attach['size'] ?? null,
            ]);
            // Notify members (single batched insert, not N round trips)
            $members = Database::all("SELECT s.user_id FROM classroom_members cm JOIN students s ON s.id=cm.student_id WHERE cm.classroom_id = ?", [$classroomId]);
            $examTag = $periodRow ? (string) Database::value("SELECT code FROM grade_periods WHERE id = ? LIMIT 1", [(int) $periodRow['id']]) : '';
            $examTag = in_array($examTag, self::EXAM_PERIODS, true) ? $examTag : '';
            \App\Controllers\NotificationController::pushMany(
                array_map(static fn($m) => (int) ($m['user_id'] ?? 0), $members),
                'New activity: ' . $title,
                'Teacher posted "' . $title . '" in ' . $class['subject_code'] . ' — ' . $class['subject_title'] . ($dueAt ? ' (due ' . date('M j, Y g:ia', strtotime($dueAt)) . ')' : '') . (!empty($attach) ? ' [File attached: ' . $attach['orig'] . ']' : '') . ($examTag !== '' ? ' [Exam — ' . $examTag . ' permit required to open]' : ''),
                'task',
                url('/classroom-tasks/' . $taskId)
            );
            Database::insert('audit_logs', ['actor_user_id'=>(int)Auth::id(),'action'=>'classroom.task_posted','entity_type'=>'subject_classrooms','entity_id'=>$classroomId,'ip_address'=>client_ip()]);
            set_flash('success', 'Activity "' . $title . '" posted to ' . $class['subject_code'] . ' for ' . count($members) . ' student(s).');
        } catch (Throwable $e) {
            storage_log('classroom task post failed: '.$e->getMessage());
            set_flash('error','Could not post activity: '.$e->getMessage());
        }
        redirect('/dashboard');
    }

    /* ============ TEACHER: edit / delete / archive own activities ============ */

    /**
     * Derived archive state: an activity is archived once its deadline
     * passes. No cron and no extra column (shared hosting): every list
     * derives this from due_at. NULL deadline = never archives.
     */
    public static function taskArchived(array $task): bool
    {
        if (empty($task['due_at'])) return false;
        $ts = strtotime((string) $task['due_at']);
        return $ts !== false && $ts <= time();
    }

    /**
     * Shared due-date policy with post (future only, sane year range).
     * @return array{ok:bool,due_at:?string,error:string}
     */
    private static function normalizeDue(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') return ['ok' => true, 'due_at' => null, 'error' => ''];
        $dueAt = str_replace('T', ' ', $raw);
        if (strlen($dueAt) === 16) $dueAt .= ':00';
        $ts = strtotime($dueAt);
        if ($ts === false) return ['ok' => false, 'due_at' => null, 'error' => 'Invalid due date format.'];
        if ($ts <= time() + 300) {
            return ['ok' => false, 'due_at' => null, 'error' => 'Please choose a future due date — at least 5 minutes from now.'];
        }
        $y = (int) date('Y', $ts);
        $curY = (int) date('Y');
        if ($y < $curY || $y > $curY + 5) {
            return ['ok' => false, 'due_at' => null, 'error' => 'Due date year must be between ' . $curY . ' and ' . ($curY + 5) . '.'];
        }
        return ['ok' => true, 'due_at' => $dueAt, 'error' => ''];
    }

    /** @return array{0:array,1:int} task + teacher id; redirects unless the task is theirs */
    private function ownedTaskOrFail(int $taskId): array
    {
        Auth::requireRole('teacher');
        $teacher = Database::one("SELECT id FROM teachers WHERE user_id = ? LIMIT 1", [(int) Auth::id()]);
        if ($teacher === null) {
            set_flash('error', 'Teacher not found.');
            redirect('/dashboard');
        }
        $task = Database::one(
            "SELECT ct.*, sc.subject_code, sc.subject_title, sc.invite_code
               FROM classroom_tasks ct JOIN subject_classrooms sc ON sc.id = ct.classroom_id
              WHERE ct.id = ? AND sc.teacher_id = ? LIMIT 1",
            [$taskId, (int) $teacher['id']]
        );
        if ($task === null) {
            set_flash('error', 'Activity not found or not yours.');
            redirect('/dashboard');
        }
        return [$task, (int) $teacher['id']];
    }

    /* Teacher: edit form for one owned activity */
    public function editTask(array $params = []): void
    {
        [$task] = $this->ownedTaskOrFail((int) ($params['id'] ?? 0));
        $cats = Database::all("SELECT * FROM assessment_categories WHERE is_active = 1 ORDER BY sort_order ASC");
        $periods = Database::all("SELECT * FROM grade_periods WHERE is_active = 1 ORDER BY sort_order ASC");
        $subCount = (int) Database::value("SELECT COUNT(*) FROM classroom_task_submissions WHERE task_id = ?", [(int) $task['id']]);
        $this->view('classrooms/task_edit', [
            'title' => 'Edit — ' . ($task['title'] ?? 'Activity'),
            'task' => $task, 'cats' => $cats, 'periods' => $periods,
            'subCount' => $subCount, 'isArchived' => self::taskArchived($task),
        ]);
    }

    /* Teacher: save edits (title/instructions/due always; grading fields locked once submissions exist) */
    public function updateTask(array $params = []): void
    {
        Csrf::guard();
        [$task] = $this->ownedTaskOrFail((int) ($params['id'] ?? 0));
        $taskId = (int) $task['id'];

        $title = trim((string) ($_POST['title'] ?? ''));
        $instructions = trim((string) ($_POST['instructions'] ?? ''));
        $maxScore = trim((string) ($_POST['max_score'] ?? '100'));
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $periodCode = trim((string) ($_POST['period'] ?? ''));
        $due = self::normalizeDue((string) ($_POST['due_at'] ?? ''));
        if (!$due['ok']) {
            set_flash('error', $due['error']);
            redirect('/teacher/classroom-tasks/' . $taskId . '/edit');
        }
        if ($title === '') {
            set_flash('error', 'Title is required.');
            redirect('/teacher/classroom-tasks/' . $taskId . '/edit');
        }
        $catRow = $categoryId > 0 ? Database::one("SELECT id, code FROM assessment_categories WHERE id = ? AND is_active = 1 LIMIT 1", [$categoryId]) : null;
        if ($categoryId > 0 && $catRow === null) {
            set_flash('error', 'Invalid category.');
            redirect('/teacher/classroom-tasks/' . $taskId . '/edit');
        }
        $maxCheck = \App\Core\GradeEngine::validateMax($maxScore, $catRow['code'] ?? null);
        if (!$maxCheck['ok']) {
            set_flash('error', $maxCheck['error']);
            redirect('/teacher/classroom-tasks/' . $taskId . '/edit');
        }
        $maxScore = (string) $maxCheck['value'];
        // Passing threshold stays editable (it only relabels PASSED/NOT PASSED, never rewrites scores).
        $passCheck = \App\Core\GradeEngine::validatePassing(trim((string)($_POST['passing_score'] ?? '')), (float) $maxScore);
        if (!$passCheck['ok']) {
            set_flash('error', $passCheck['error']);
            redirect('/teacher/classroom-tasks/' . $taskId . '/edit');
        }
        $periodRow = $periodCode !== '' ? Database::one("SELECT id FROM grade_periods WHERE code = ? AND is_active = 1 LIMIT 1", [$periodCode]) : null;
        if ($periodCode !== '' && $periodRow === null) {
            set_flash('error', 'Invalid grading period.');
            redirect('/teacher/classroom-tasks/' . $taskId . '/edit');
        }

        // Grading fields lock once any submission exists (scores already reference them).
        $subCount = (int) Database::value("SELECT COUNT(*) FROM classroom_task_submissions WHERE task_id = ?", [$taskId]);
        $locked = [];
        if ($subCount > 0) {
            $newCat = $catRow ? (int) $catRow['id'] : null;
            $newPer = $periodRow ? (int) $periodRow['id'] : null;
            if ($newCat !== ($task['category_id'] === null ? null : (int) $task['category_id'])) $locked[] = 'category';
            if (abs((float) $maxScore - (float) ($task['max_score'] ?? 100)) > 0.001) $locked[] = 'max score';
            if ($newPer !== ($task['period_id'] === null ? null : (int) $task['period_id'])) $locked[] = 'grading period';
            if ($locked !== []) {
                set_flash('error', 'Cannot change ' . implode(', ', $locked) . ' — ' . $subCount . ' submission(s) already reference this activity. Title, instructions, and due date remain editable.');
                redirect('/teacher/classroom-tasks/' . $taskId . '/edit');
            }
        }

        $oldDue = $task['due_at'] ?? null;
        $newDue = $due['due_at'];
        $dueChanged = ($oldDue === null ? null : substr((string) $oldDue, 0, 16)) !== ($newDue === null ? null : substr((string) $newDue, 0, 16));
        // Attachment: replace with a new upload, or remove via checkbox.
        $newFile = null;
        $removeFile = !empty($_POST['remove_file']);
        try {
            if (!empty($_FILES['task_file'])) {
                $up = self::storeTaskFile($_FILES['task_file'], 'task_' . (int) $task['classroom_id'] . '_' . $taskId);
                if ($up !== []) $newFile = $up;
            }
        } catch (\RuntimeException $e) {
            set_flash('error', $e->getMessage());
            redirect('/teacher/classroom-tasks/' . $taskId . '/edit');
        }
        try {
            if ($newFile !== null || $removeFile) {
                Database::run(
                    "UPDATE classroom_tasks SET file_path = ?, original_filename = ?, mime_type = ?, file_size_bytes = ? WHERE id = ?",
                    [$newFile['rel'] ?? null, $newFile['orig'] ?? null, $newFile['mime'] ?? null, $newFile['size'] ?? null, $taskId]
                );
                self::deleteTaskFile((string) ($task['file_path'] ?? ''));
            }
            Database::run(
                "UPDATE classroom_tasks SET title = ?, instructions = ?, category_id = ?, max_score = ?, period_id = ?, due_at = ? WHERE id = ?",
                [$title, $instructions !== '' ? $instructions : null, $catRow ? (int) $catRow['id'] : null, round((float) $maxScore, 2), $periodRow ? (int) $periodRow['id'] : null, $due['due_at'], $taskId]
            );
            if (\App\Core\GradeEngine::passingColExists()) {
                Database::run("UPDATE classroom_tasks SET passing_score = ? WHERE id = ?", [$passCheck['value'], $taskId]);
            }
            Database::insert('audit_logs', ['actor_user_id' => (int) Auth::id(), 'action' => 'classroom.task_updated', 'entity_type' => 'classroom_tasks', 'entity_id' => $taskId, 'ip_address' => client_ip()]);
            try {
                $members = Database::all("SELECT s.user_id FROM classroom_members cm JOIN students s ON s.id = cm.student_id WHERE cm.classroom_id = ?", [(int) $task['classroom_id']]);
                \App\Controllers\NotificationController::pushMany(
                    array_map(static fn($m) => (int) ($m['user_id'] ?? 0), $members),
                    'Activity updated: ' . $title,
                    'Your teacher edited "' . $title . '" in ' . ($task['subject_code'] ?? 'your subject') . ($dueChanged ? ' — new deadline: ' . ($due['due_at'] ? date('M j, Y g:ia', strtotime($due['due_at'])) : 'no deadline') . '.' : '.'),
                    'task', '/classroom-tasks/' . $taskId
                );
            } catch (Throwable $e) {
            }
            set_flash('success', 'Activity updated.');
        } catch (Throwable $e) {
            storage_log('classroom task update failed ' . $taskId . ': ' . $e->getMessage());
            set_flash('error', 'Could not update activity.');
        }
        redirect('/classroom-tasks/' . $taskId);
    }

    /* Teacher: delete one owned activity (submissions cascade; files removed; audited) */
    public function deleteTask(array $params = []): void
    {
        Csrf::guard();
        [$task] = $this->ownedTaskOrFail((int) ($params['id'] ?? 0));
        $taskId = (int) $task['id'];
        $title = (string) ($task['title'] ?? ('Activity #' . $taskId));
        try {
            // NOTE: submissions carry file_path only (there is no stored_path
            // column — selecting it broke every delete).
            $subs = Database::all("SELECT id, file_path FROM classroom_task_submissions WHERE task_id = ?", [$taskId]);
            $base = realpath(rtrim(UPLOAD_DIR, '/\\'));
            $removed = 0;
            foreach ($subs as $s) {
                $rel = (string) ($s['file_path'] ?? '');
                if ($rel === '') continue;
                $full = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($rel, '/\\'));
                $real = realpath($full);
                if ($real !== false && $base !== false && str_starts_with($real, $base) && is_file($real)) {
                    if (@unlink($real)) $removed++;
                }
            }
            $n = count($subs);
            Database::transaction(function () use ($taskId) {
                Database::run("DELETE FROM classroom_task_submissions WHERE task_id = ?", [$taskId]);
                Database::run("DELETE FROM classroom_tasks WHERE id = ?", [$taskId]);
            });
            self::deleteTaskFile((string) ($task['file_path'] ?? ''));
            Database::insert('audit_logs', ['actor_user_id' => (int) Auth::id(), 'action' => 'classroom.task_deleted', 'entity_type' => 'subject_classrooms', 'entity_id' => (int) $task['classroom_id'], 'new_values_json' => json_encode(['task_id' => $taskId, 'title' => $title, 'submissions' => $n, 'files_removed' => $removed]), 'ip_address' => client_ip()]);
            try {
                $members = Database::all("SELECT s.user_id FROM classroom_members cm JOIN students s ON s.id = cm.student_id WHERE cm.classroom_id = ?", [(int) $task['classroom_id']]);
                \App\Controllers\NotificationController::pushMany(
                    array_map(static fn($m) => (int) ($m['user_id'] ?? 0), $members),
                    'Activity removed: ' . $title,
                    'Your teacher removed "' . $title . '" from ' . ($task['subject_code'] ?? 'your subject') . '. Its submissions no longer count toward grades.',
                    'task', '/dashboard'
                );
            } catch (Throwable $e) {
            }
            set_flash('success', 'Activity "' . $title . '" deleted (' . $n . ' submission(s) removed).');
        } catch (Throwable $e) {
            storage_log('classroom task delete failed ' . $taskId . ': ' . $e->getMessage());
            set_flash('error', 'Could not delete activity.');
        }
        redirect('/dashboard');
    }

    /**
     * Who is being enrolled: the student themselves, or — for parent
     * accounts — one OWNED linked child (?child_id= validated against
     * guardian_students, else the first linked child).
     * @return array{student:array,via_parent:bool,child_id:int,child_name:string}|array{rejected:true}|null
     */
    private function resolveActingStudent(): ?array
    {
        $userId = (int) Auth::id();
        if (Auth::is('student')) {
            $s = Database::one(
                "SELECT s.*, u.first_name, u.last_name FROM students s LEFT JOIN users u ON u.id = s.user_id WHERE s.user_id = ? LIMIT 1",
                [$userId]
            );
            if (!$s) return null;
            return ['student' => $s, 'via_parent' => false, 'child_id' => 0, 'child_name' => ''];
        }
        if (Auth::is('parent')) {
            $g = Database::one('SELECT id FROM guardians WHERE user_id = ? LIMIT 1', [$userId]);
            if (!$g) return null;
            $kid = (int) ($_REQUEST['child_id'] ?? 0);
            if ($kid > 0) {
                $owned = Database::one(
                    'SELECT s.*, u.first_name, u.last_name FROM guardian_students gs JOIN students s ON s.id = gs.student_id LEFT JOIN users u ON u.id = s.user_id WHERE gs.guardian_id = ? AND s.id = ? LIMIT 1',
                    [(int) $g['id'], $kid]
                );
                if (!$owned) return ['rejected' => true];
            } else {
                $owned = Database::one(
                    'SELECT s.*, u.first_name, u.last_name FROM guardian_students gs JOIN students s ON s.id = gs.student_id LEFT JOIN users u ON u.id = s.user_id WHERE gs.guardian_id = ? ORDER BY s.id LIMIT 1',
                    [(int) $g['id']]
                );
                if (!$owned) return null;
            }
            $name = trim(trim((string) ($owned['first_name'] ?? '')) . ' ' . trim((string) ($owned['last_name'] ?? '')));
            return ['student' => $owned, 'via_parent' => true, 'child_id' => (int) $owned['id'], 'child_name' => $name !== '' ? $name : ('Student #' . $owned['id'])];
        }
        return null;
    }

    /* ====================== STUDENT: preview invite -> confirm join ====================== */
    public function previewJoin(array $params = []): void
    {
        Auth::requireRole('student', 'parent');
        $code = strtoupper(trim((string)($_GET['code'] ?? $_GET['invite_code'] ?? '')));
        $code = preg_replace('/[^A-Z0-9]/', '', $code);
        if ($code === '') { set_flash('error', 'Enter a student invite code.'); redirect('/dashboard'); }
        $row = Database::one(
            "SELECT sc.*, ay.school_year, TRIM(CONCAT(COALESCE(tu.first_name,''),' ',COALESCE(tu.last_name,''))) AS teacher_name
               FROM subject_classrooms sc JOIN academic_years ay ON ay.id = sc.academic_year_id
               LEFT JOIN teachers t ON t.id = sc.teacher_id LEFT JOIN users tu ON tu.id = t.user_id
              WHERE UPPER(sc.invite_code) = ? LIMIT 1",
            [strtoupper($code)]
        );
        if (!$row) { set_flash('error', 'Invalid invite code. Ask your teacher for the official subject code.'); redirect('/dashboard'); }
        // Resolve who is being enrolled (student self, or parent on behalf of
        // an owned linked child). Tampered child_id is rejected, never trusted.
        $acting = $this->resolveActingStudent();
        if ($acting !== null && isset($acting['rejected'])) {
            set_flash('error', 'That child is not linked to your guardian account.');
            redirect('/dashboard');
        }
        if ($acting !== null) {
            $meId = (int) $acting['student']['id'];
            // Legacy section guard stays as an extra safety net (never the primary rule).
            $deny = self::sectionMismatch(self::studentCourseProfile($meId), $row);
            if ($deny !== null) { set_flash('error', $deny); redirect('/dashboard'); }
            // Enrollment-aware eligibility preview (authoritative academic rule).
            if (class_exists(\App\Core\CollegeEligibility::class)) {
                $check = \App\Core\CollegeEligibility::checkJoin($meId, (int) $row['id']);
                $ctx = $check['context'] ?? null;
                $elig = $check['ok']
                    ? ['state' => 'eligible', 'label' => 'Join Subject', 'reason' => '']
                    : \App\Core\CollegeEligibility::displayState($meId, (int) $row['id']);
                $this->view('classrooms/join_preview', [
                    'title' => 'Join Subject', 'offering' => $row, 'code' => $code,
                    'acadCtx' => $ctx, 'elig' => $elig,
                    'viaParent' => $acting['via_parent'], 'childId' => $acting['child_id'], 'childName' => $acting['child_name'],
                ]);
                return;
            }
        }
        $this->view('classrooms/join_preview', ['title' => 'Join Subject', 'offering' => $row, 'code' => $code]);
    }

    /* ====================== STUDENT: join via invite code ====================== */
    public function studentJoin(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('student', 'parent');

        $code = strtoupper(trim((string)($_POST['invite_code'] ?? $_POST['code'] ?? '')));
        // Normalize: allow with or without dash/spaces
        $code = preg_replace('/[^A-Z0-9]/', '', $code);
        // Our codes are 6 chars like X7K9M2 (stored without dash if we used dash logic, but we stored without dash)
        // The teacher create stores 6 chars without dash? Actually we did dash insertion for 6 -> 3+3 without dash? We did XXX + XXX = XXXXXX no dash. So accept 6.
        // If code is like ABC-DEF, normalize will already remove dash.

        if ($code === '' || strlen($code) < 5) {
            set_flash('error', 'Please enter a valid invite code (e.g., X7K9M2).');
            redirect('/dashboard');
        }

        // Normalize for lookup: try both plain and dash variants
        $candidates = [$code];
        // If user entered with dash, already stripped; also try stored format (maybe with dash)
        // Our stored codes are 6 chars no dash (e.g., X7KM2P) — but we also support dashes if manually inserted.
        // To be safe, try lookup with both
        $codeWithDash = substr($code,0,3) . '-' . substr($code,3);
        if ($codeWithDash !== $code) $candidates[] = $codeWithDash;

        $userId = (int)Auth::id();
        // Students join themselves; parents join ON BEHALF of an owned linked
        // child (?child_id= ownership-checked inside; tampering is rejected).
        $acting = $this->resolveActingStudent();
        if ($acting !== null && isset($acting['rejected'])) {
            set_flash('error', 'That child is not linked to your guardian account.');
            redirect('/dashboard');
        }
        $student = $acting !== null ? $acting['student'] : null;
        $viaParent = $acting !== null && !empty($acting['via_parent']);
        $childName = $acting !== null ? (string) ($acting['child_name'] ?? '') : '';
        if (!$student) {
            set_flash('error', Auth::is('parent') ? 'No linked child found. The registrar links your guardian profile after enrollment approval.' : 'Student profile not found. Complete enrollment first.');
            redirect('/dashboard');
        }
        if (($student['status'] ?? '') !== 'enrolled') {
            $who = $viaParent ? $childName . ' must be' : 'You must be';
            set_flash('warning', $who . ' fully enrolled (Accounting & Registrar approved + PayRex paid) before joining subjects. Check My Applications.');
            redirect('/my-enrollments');
        }
        $studentId = (int)$student['id'];

        // Find classroom by invite_code (case-sensitive uppercase)
        $classroom = null;
        foreach ($candidates as $c) {
            $classroom = Database::one("SELECT * FROM subject_classrooms WHERE invite_code = ? AND is_active = 1 LIMIT 1", [$c]);
            if ($classroom) break;
            $classroom = Database::one("SELECT * FROM subject_classrooms WHERE UPPER(invite_code) = UPPER(?) AND is_active = 1 LIMIT 1", [$c]);
            if ($classroom) break;
        }
        // Also try exact upper
        if (!$classroom) {
            $classroom = Database::one("SELECT * FROM subject_classrooms WHERE UPPER(invite_code) = ? AND is_active = 1 LIMIT 1", [strtoupper($code)]);
        }

        if (!$classroom) {
            set_flash('error', 'Invalid invite code: ' . e($code) . '. Ask your teacher for the official subject code.');
            redirect('/dashboard');
        }
        try {
            $st = Database::value("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subject_classrooms' AND COLUMN_NAME = 'status'");
            if ($st && ($classroom['status'] ?? 'open') !== 'open') { set_flash('error', 'This subject is ' . $classroom['status'] . ' and not open for joining.'); redirect('/dashboard'); }
        } catch (Throwable $e) {}

        // Course/section guard stays as an extra safety net (never the primary rule).
        $deny = self::sectionMismatch(self::studentCourseProfile($studentId), $classroom);
        if ($deny !== null) { set_flash('error', $deny); redirect('/dashboard'); }

        // Enrollment-aware academic eligibility (BACKEND-AUTHORITATIVE).
        // A valid invite code alone NEVER grants access to the wrong
        // school year / semester / year level / program.
        if (class_exists(\App\Core\CollegeEligibility::class)) {
            $check = \App\Core\CollegeEligibility::checkJoin($studentId, (int) $classroom['id']);
            if (!$check['ok']) {
                try {
                    Database::insert('audit_logs', [
                        'actor_user_id' => $userId,
                        'action' => 'classroom.join_rejected',
                        'entity_type' => 'subject_classrooms',
                        'entity_id' => (int) $classroom['id'],
                        'new_values_json' => json_encode(['code' => $check['code'], 'reason' => $check['error']]),
                        'ip_address' => client_ip(),
                    ]);
                } catch (Throwable $e) {
                }
                $code = (string) ($check['code'] ?? '');
                if ($code === 'DUPLICATE') {
                    set_flash('info', ($viaParent ? $childName . ' already joined ' : 'You already joined ') . $classroom['subject_code'] . ' — ' . $classroom['subject_title'] . '.');
                } else {
                    set_flash('error', $check['error'] !== '' ? $check['error'] : 'You are not eligible for this subject.');
                    try {
                        Database::insert('notifications', [
                            'user_id' => $userId,
                            'title' => 'Subject join blocked',
                            'message' => ($check['error'] !== '' ? $check['error'] : 'Not eligible.') . ' (' . ($classroom['subject_code'] ?? 'subject') . ')',
                            'type' => 'warning',
                            'link_url' => url('/student/class-offerings'),
                        ]);
                    } catch (Throwable $e) {
                    }
                }
                redirect('/dashboard');
            }
        }

        // Check already joined (re-verified inside the transaction below as well)
        $already = Database::one("SELECT id FROM classroom_members WHERE classroom_id = ? AND student_id = ? LIMIT 1", [(int)$classroom['id'], $studentId]);
        if ($already) {
            set_flash('info', ($viaParent ? $childName . ' already joined ' : 'You already joined ') . $classroom['subject_code'] . ' — ' . $classroom['subject_title'] . '.');
            redirect('/dashboard');
        }

        // Capacity pre-check (re-checked inside the transaction with a row lock)
        $count = (int)Database::value("SELECT COUNT(*) FROM classroom_members WHERE classroom_id = ?", [(int)$classroom['id']]);
        if ($count >= (int)$classroom['max_students']) {
            set_flash('error', 'Classroom is full (' . $classroom['max_students'] . ' students).');
            redirect('/dashboard');
        }

        try {
            Database::transaction(static function () use ($classroom, $studentId) {
                $pdo = Database::pdo();
                $st = $pdo->prepare('SELECT max_students FROM subject_classrooms WHERE id = ? FOR UPDATE');
                $st->execute([(int) $classroom['id']]);
                $locked = $st->fetch(\PDO::FETCH_ASSOC);
                if (!$locked) throw new \RuntimeException('NOT_FOUND');
                $st = $pdo->prepare('SELECT id FROM classroom_members WHERE classroom_id = ? AND student_id = ? LIMIT 1');
                $st->execute([(int) $classroom['id'], $studentId]);
                if ($st->fetch(\PDO::FETCH_ASSOC)) throw new \RuntimeException('DUPLICATE');
                $st = $pdo->prepare('SELECT COUNT(*) FROM classroom_members WHERE classroom_id = ?');
                $st->execute([(int) $classroom['id']]);
                if ((int) $st->fetchColumn() >= (int) $locked['max_students']) throw new \RuntimeException('FULL');
                $st = $pdo->prepare('INSERT INTO classroom_members (classroom_id, student_id) VALUES (?, ?)');
                $st->execute([(int) $classroom['id'], $studentId]);
            });
            Database::insert('audit_logs', [
                'actor_user_id'=> $userId,
                'action'       => 'classroom.joined',
                'entity_type'  => 'subject_classrooms',
                'entity_id'    => (int)$classroom['id'],
                'new_values_json' => json_encode(['student_id' => $studentId, 'via_parent' => $viaParent ? 1 : 0]),
                'ip_address'   => client_ip(),
            ]);
            $joinedMsg = $viaParent
                ? 'You enrolled ' . $childName . ' in ' . ($classroom['subject_code'] ?? '') . ' — ' . ($classroom['subject_title'] ?? '') . ' successfully.'
                : 'You joined ' . ($classroom['subject_code'] ?? '') . ' — ' . ($classroom['subject_title'] ?? '') . ' successfully.';
            try {
                Database::insert('notifications', [
                    'user_id' => $userId,
                    'title' => 'Subject joined: ' . ($classroom['subject_code'] ?? ''),
                    'message' => $joinedMsg,
                    'type' => 'success',
                    'link_url' => url('/student/my-classes'),
                ]);
            } catch (Throwable $e) {
            }
            // Teacher roster notification (existing system, no duplicate table).
            try {
                $owner = Database::one(
                    "SELECT t.user_id FROM subject_classrooms sc JOIN teachers t ON t.id = sc.teacher_id WHERE sc.id = ? LIMIT 1",
                    [(int) $classroom['id']]
                );
                if ($owner && !empty($owner['user_id'])) {
                    \App\Controllers\NotificationController::push(
                        (int) $owner['user_id'],
                        'New student joined ' . ($classroom['subject_code'] ?? 'your subject'),
                        ($student['student_no'] ?? 'A student') . ' joined ' . ($classroom['subject_code'] ?? '') . ' — ' . ($classroom['subject_title'] ?? '') . ($viaParent ? ' (enrolled by parent).' : '.'),
                        'info',
                        '/classrooms/' . (int) $classroom['id']
                    );
                }
            } catch (Throwable $e) {
            }
            set_flash('success', ($viaParent ? 'Enrolled ' . $childName . ' in ' : 'Joined ') . $classroom['subject_code'] . ' — ' . $classroom['subject_title'] . ' successfully! Invite: ' . $classroom['invite_code']);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if ($msg === 'DUPLICATE') {
                set_flash('info', ($viaParent ? $childName . ' already joined ' : 'You already joined ') . $classroom['subject_code'] . ' — ' . $classroom['subject_title'] . '.');
            } elseif ($msg === 'FULL') {
                set_flash('error', 'Classroom is full (' . $classroom['max_students'] . ' students).');
            } else {
                storage_log('classroom join failed: ' . $e->getMessage());
                if ($e instanceof \PDOException && ($e->errorInfo[1] ?? 0) === 1062) {
                    set_flash('info', 'You already joined ' . $classroom['subject_code'] . ' — ' . $classroom['subject_title'] . '.');
                } else {
                    set_flash('error', 'Could not join classroom. Please try again.');
                }
            }
        }
        redirect('/dashboard');
    }

    /* ============ CODE-FREE JOIN: request -> teacher verifies -> member ============ */

    private function hasJoinRequestTable(): bool
    {
        try {
            return (bool) Database::value("SHOW TABLES LIKE 'class_join_requests'");
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Student (or parent on behalf of an owned child) taps "Request to Join".
     * No invite code needed: enrollment eligibility is auto-checked now, and
     * the assigned teacher verifies before anyone becomes a member.
     */
    public function requestJoin(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('student', 'parent');
        if (!$this->hasJoinRequestTable()) {
            set_flash('error', 'Join requests are not set up yet — ask the administrator to import database/patch_class_join_requests.sql.');
            redirect('/student/class-offerings');
        }
        $cid = (int) ($_POST['classroom_id'] ?? 0);
        if ($cid <= 0) {
            set_flash('error', 'Invalid subject selected.');
            redirect('/student/class-offerings');
        }
        $back = '/student/class-offerings/' . $cid;

        $userId = (int) Auth::id();
        $acting = $this->resolveActingStudent();
        if ($acting !== null && isset($acting['rejected'])) {
            set_flash('error', 'That child is not linked to your guardian account.');
            redirect('/dashboard');
        }
        $student = $acting !== null ? $acting['student'] : null;
        $viaParent = $acting !== null && !empty($acting['via_parent']);
        $childName = $acting !== null ? (string) ($acting['child_name'] ?? '') : '';
        if (!$student) {
            set_flash('error', Auth::is('parent') ? 'No linked child found. The registrar links your guardian profile after enrollment approval.' : 'Student profile not found. Complete enrollment first.');
            redirect('/dashboard');
        }
        if (($student['status'] ?? '') !== 'enrolled') {
            set_flash('warning', ($viaParent ? $childName . ' must be' : 'You must be') . ' fully enrolled before requesting subjects.');
            redirect('/my-enrollments');
        }
        $studentId = (int) $student['id'];

        $classroom = Database::one("SELECT * FROM subject_classrooms WHERE id = ? AND is_active = 1 LIMIT 1", [$cid]);
        if (!$classroom) {
            set_flash('error', 'This subject is no longer available.');
            redirect('/student/class-offerings');
        }
        try {
            $st = Database::value("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subject_classrooms' AND COLUMN_NAME = 'status'");
            if ($st && ($classroom['status'] ?? 'open') !== 'open') {
                set_flash('error', 'This subject is ' . $classroom['status'] . ' and not open for joining.');
                redirect($back);
            }
        } catch (Throwable $e) {
        }
        $deny = self::sectionMismatch(self::studentCourseProfile($studentId), $classroom);
        if ($deny !== null) {
            set_flash('error', $deny);
            redirect($back);
        }

        $already = Database::one("SELECT id FROM classroom_members WHERE classroom_id = ? AND student_id = ? LIMIT 1", [$cid, $studentId]);
        if ($already) {
            set_flash('info', ($viaParent ? $childName . ' already joined ' : 'You already joined ') . $classroom['subject_code'] . ' — ' . $classroom['subject_title'] . '.');
            redirect($back);
        }
        $existing = Database::one("SELECT id, status FROM class_join_requests WHERE classroom_id = ? AND student_id = ? LIMIT 1", [$cid, $studentId]);
        if ($existing && ($existing['status'] ?? '') === 'pending') {
            set_flash('info', 'Request already pending — the teacher will verify it soon.');
            redirect($back);
        }

        // Auto eligibility gate (same authoritative check as invite-code joins).
        if (class_exists(\App\Core\CollegeEligibility::class)) {
            $check = \App\Core\CollegeEligibility::checkJoin($studentId, $cid);
            if (!$check['ok'] && ($check['code'] ?? '') !== 'DUPLICATE') {
                try {
                    Database::insert('audit_logs', [
                        'actor_user_id' => $userId, 'action' => 'classroom.request_rejected',
                        'entity_type' => 'subject_classrooms', 'entity_id' => $cid,
                        'new_values_json' => json_encode(['code' => $check['code'], 'reason' => $check['error'], 'student_id' => $studentId, 'via_parent' => $viaParent ? 1 : 0]),
                        'ip_address' => client_ip(),
                    ]);
                } catch (Throwable $e) {
                }
                set_flash('error', $check['error'] !== '' ? $check['error'] : 'Not eligible for this subject.');
                redirect($back);
            }
        }

        try {
            if ($existing) {
                Database::run(
                    "UPDATE class_join_requests SET status = 'pending', requested_by = ?, teacher_remarks = NULL, reviewed_by = NULL, reviewed_at = NULL, requested_at = NOW() WHERE id = ?",
                    [$userId, (int) $existing['id']]
                );
                $reqId = (int) $existing['id'];
            } else {
                $reqId = Database::insert('class_join_requests', [
                    'classroom_id' => $cid, 'student_id' => $studentId,
                    'requested_by' => $userId, 'status' => 'pending',
                ]);
            }
            Database::insert('audit_logs', [
                'actor_user_id' => $userId, 'action' => 'classroom.request_created',
                'entity_type' => 'class_join_requests', 'entity_id' => $reqId,
                'new_values_json' => json_encode(['classroom_id' => $cid, 'student_id' => $studentId, 'via_parent' => $viaParent ? 1 : 0]),
                'ip_address' => client_ip(),
            ]);
            // Teacher verification queue.
            try {
                $owner = Database::one(
                    "SELECT t.user_id FROM subject_classrooms sc JOIN teachers t ON t.id = sc.teacher_id WHERE sc.id = ? LIMIT 1",
                    [$cid]
                );
                if ($owner && !empty($owner['user_id'])) {
                    \App\Controllers\NotificationController::push(
                        (int) $owner['user_id'],
                        'Join request: ' . ($classroom['subject_code'] ?? 'subject'),
                        ($student['student_no'] ?? 'A student') . ' requests to join ' . ($classroom['subject_code'] ?? '') . ($viaParent ? ' (filed by parent)' : '') . ' — Approve or Decline.',
                        'warning',
                        '/classrooms/' . $cid
                    );
                }
            } catch (Throwable $e) {
            }
            try {
                Database::insert('notifications', [
                    'user_id' => $userId,
                    'title' => 'Join request sent: ' . ($classroom['subject_code'] ?? ''),
                    'message' => ($viaParent ? $childName . '\'s request' : 'Your request') . ' for ' . ($classroom['subject_code'] ?? '') . ' was sent — the teacher will verify it before enrollment.',
                    'type' => 'info',
                    'link_url' => url('/student/my-classes'),
                ]);
            } catch (Throwable $e) {
            }
            set_flash('success', 'Request sent for ' . $classroom['subject_code'] . ' — the teacher will verify it. No invite code needed.');
        } catch (Throwable $e) {
            storage_log('join request failed: ' . $e->getMessage());
            set_flash('error', 'Could not send the request. Please try again.');
        }
        redirect($back);
    }

    /** Requester (or their parent) cancels a pending request. */
    public function cancelRequest(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('student', 'parent');
        if (!$this->hasJoinRequestTable()) {
            set_flash('error', 'Join requests are not set up yet.');
            redirect('/student/my-classes');
        }
        $id = (int) ($params['id'] ?? 0);
        $userId = (int) Auth::id();
        $req = Database::one("SELECT * FROM class_join_requests WHERE id = ? LIMIT 1", [$id]);
        if (!$req) {
            set_flash('error', 'Request not found.');
            redirect('/student/my-classes');
        }
        $allowed = (int) ($req['requested_by'] ?? 0) === $userId;
        if (!$allowed && Auth::is('parent') && class_exists(\App\Core\GuardianLinks::class)) {
            try {
                $g = Database::one("SELECT id FROM guardians WHERE user_id = ? LIMIT 1", [$userId]);
                if ($g) {
                    $allowed = (bool) Database::one(
                        "SELECT 1 FROM guardian_students WHERE guardian_id = ? AND student_id = ? LIMIT 1",
                        [(int) $g['id'], (int) $req['student_id']]
                    );
                }
            } catch (Throwable $e) {
            }
        }
        if (!$allowed) {
            set_flash('error', 'You cannot cancel this request.');
            redirect('/student/my-classes');
        }
        if (($req['status'] ?? '') !== 'pending') {
            set_flash('info', 'This request is already ' . ($req['status'] ?? 'decided') . '.');
            redirect('/student/my-classes');
        }
        Database::run("UPDATE class_join_requests SET status = 'cancelled' WHERE id = ?", [$id]);
        try {
            Database::insert('audit_logs', [
                'actor_user_id' => $userId, 'action' => 'classroom.request_cancelled',
                'entity_type' => 'class_join_requests', 'entity_id' => $id, 'ip_address' => client_ip(),
            ]);
        } catch (Throwable $e) {
        }
        set_flash('success', 'Join request cancelled.');
        redirect('/student/my-classes');
    }

    /** Assigned teacher (or admin) approves: eligibility re-validated, seat locked. */
    public function approveRequest(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('teacher', 'admin');
        if (!$this->hasJoinRequestTable()) {
            set_flash('error', 'Join requests are not set up yet.');
            redirect('/dashboard');
        }
        $id = (int) ($params['id'] ?? 0);
        $userId = (int) Auth::id();
        $req = Database::one(
            "SELECT jr.*, sc.teacher_id, sc.subject_code, sc.subject_title, sc.max_students
               FROM class_join_requests jr JOIN subject_classrooms sc ON sc.id = jr.classroom_id
              WHERE jr.id = ? LIMIT 1",
            [$id]
        );
        if (!$req) {
            set_flash('error', 'Request not found.');
            redirect('/dashboard');
        }
        if (Auth::role() !== 'admin') {
            $t = Database::one("SELECT id FROM teachers WHERE user_id = ? LIMIT 1", [$userId]);
            if (!$t || (int) $t['id'] !== (int) ($req['teacher_id'] ?? 0)) {
                set_flash('error', 'Only the assigned teacher can verify this request.');
                redirect('/dashboard');
            }
        }
        if (($req['status'] ?? '') !== 'pending') {
            set_flash('info', 'This request is already ' . ($req['status'] ?? 'decided') . '.');
            redirect('/classrooms/' . (int) $req['classroom_id']);
        }
        $studentId = (int) $req['student_id'];
        $cid = (int) $req['classroom_id'];

        // Re-validate at decision time: enrollment may have changed since filing.
        if (class_exists(\App\Core\CollegeEligibility::class)) {
            $check = \App\Core\CollegeEligibility::checkJoin($studentId, $cid);
            if (!$check['ok'] && ($check['code'] ?? '') !== 'DUPLICATE') {
                if (($check['code'] ?? '') === 'FULL') {
                    set_flash('error', 'Class is full — request kept pending. Approve again once a slot frees up.');
                    redirect('/classrooms/' . $cid);
                }
                Database::run(
                    "UPDATE class_join_requests SET status = 'rejected', teacher_remarks = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?",
                    [mb_substr($check['error'] !== '' ? $check['error'] : 'No longer eligible.', 0, 500), $userId, $id]
                );
                try {
                    Database::insert('audit_logs', [
                        'actor_user_id' => $userId, 'action' => 'classroom.request_auto_rejected',
                        'entity_type' => 'class_join_requests', 'entity_id' => $id,
                        'new_values_json' => json_encode(['code' => $check['code']]),
                        'ip_address' => client_ip(),
                    ]);
                } catch (Throwable $e) {
                }
                $this->notifyRequester((int) $req['requested_by'], $studentId, 'Join request declined: ' . ($req['subject_code'] ?? ''), ($check['error'] !== '' ? $check['error'] : 'No longer eligible.'));
                set_flash('warning', 'Student is no longer eligible — request auto-declined with the reason.');
                redirect('/classrooms/' . $cid);
            }
        }

        try {
            Database::transaction(static function () use ($cid, $studentId) {
                $pdo = Database::pdo();
                $st = $pdo->prepare('SELECT max_students FROM subject_classrooms WHERE id = ? FOR UPDATE');
                $st->execute([$cid]);
                $locked = $st->fetch(\PDO::FETCH_ASSOC);
                if (!$locked) throw new \RuntimeException('NOT_FOUND');
                $st = $pdo->prepare('SELECT id FROM classroom_members WHERE classroom_id = ? AND student_id = ? LIMIT 1');
                $st->execute([$cid, $studentId]);
                if ($st->fetch(\PDO::FETCH_ASSOC)) throw new \RuntimeException('DUPLICATE');
                $st = $pdo->prepare('SELECT COUNT(*) FROM classroom_members WHERE classroom_id = ?');
                $st->execute([$cid]);
                if ((int) $st->fetchColumn() >= (int) $locked['max_students']) throw new \RuntimeException('FULL');
                $st = $pdo->prepare('INSERT INTO classroom_members (classroom_id, student_id) VALUES (?, ?)');
                $st->execute([$cid, $studentId]);
            });
            Database::run(
                "UPDATE class_join_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), teacher_remarks = NULL WHERE id = ?",
                [$userId, $id]
            );
            Database::insert('audit_logs', [
                'actor_user_id' => $userId, 'action' => 'classroom.request_approved',
                'entity_type' => 'class_join_requests', 'entity_id' => $id,
                'new_values_json' => json_encode(['classroom_id' => $cid, 'student_id' => $studentId]),
                'ip_address' => client_ip(),
            ]);
            $this->notifyRequester((int) $req['requested_by'], $studentId, 'Join request approved: ' . ($req['subject_code'] ?? ''), 'The teacher verified and enrolled the student in ' . ($req['subject_code'] ?? '') . ' — ' . ($req['subject_title'] ?? '') . '.');
            set_flash('success', 'Student enrolled in ' . ($req['subject_code'] ?? 'the subject') . '. They now appear in your roster.');
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if ($msg === 'DUPLICATE') {
                Database::run("UPDATE class_join_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?", [$userId, $id]);
                set_flash('info', 'Student was already a member — request marked approved.');
            } elseif ($msg === 'FULL') {
                set_flash('error', 'Class is full — request kept pending. Approve again once a slot frees up.');
            } else {
                storage_log('join approve failed: ' . $e->getMessage());
                set_flash('error', 'Could not approve. Please try again.');
            }
        }
        redirect('/classrooms/' . $cid);
    }

    /** Assigned teacher (or admin) declines with an optional reason. */
    public function declineRequest(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('teacher', 'admin');
        if (!$this->hasJoinRequestTable()) {
            set_flash('error', 'Join requests are not set up yet.');
            redirect('/dashboard');
        }
        $id = (int) ($params['id'] ?? 0);
        $userId = (int) Auth::id();
        $reason = mb_substr(trim((string) ($_POST['reason'] ?? $_POST['teacher_remarks'] ?? '')), 0, 500);
        $req = Database::one(
            "SELECT jr.*, sc.teacher_id, sc.subject_code FROM class_join_requests jr
               JOIN subject_classrooms sc ON sc.id = jr.classroom_id
              WHERE jr.id = ? LIMIT 1",
            [$id]
        );
        if (!$req) {
            set_flash('error', 'Request not found.');
            redirect('/dashboard');
        }
        if (Auth::role() !== 'admin') {
            $t = Database::one("SELECT id FROM teachers WHERE user_id = ? LIMIT 1", [$userId]);
            if (!$t || (int) $t['id'] !== (int) ($req['teacher_id'] ?? 0)) {
                set_flash('error', 'Only the assigned teacher can decline this request.');
                redirect('/dashboard');
            }
        }
        if (($req['status'] ?? '') !== 'pending') {
            set_flash('info', 'This request is already ' . ($req['status'] ?? 'decided') . '.');
            redirect('/classrooms/' . (int) $req['classroom_id']);
        }
        Database::run(
            "UPDATE class_join_requests SET status = 'rejected', teacher_remarks = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?",
            [$reason !== '' ? $reason : null, $userId, $id]
        );
        try {
            Database::insert('audit_logs', [
                'actor_user_id' => $userId, 'action' => 'classroom.request_rejected',
                'entity_type' => 'class_join_requests', 'entity_id' => $id,
                'new_values_json' => json_encode(['reason' => $reason]),
                'ip_address' => client_ip(),
            ]);
        } catch (Throwable $e) {
        }
        $this->notifyRequester(
            (int) $req['requested_by'], (int) $req['student_id'],
            'Join request declined: ' . ($req['subject_code'] ?? ''),
            $reason !== '' ? $reason : 'The teacher declined the request. Contact them for details.'
        );
        set_flash('success', 'Request declined' . ($reason !== '' ? ' with reason.' : '.'));
        redirect('/classrooms/' . (int) $req['classroom_id']);
    }

    /** Notify the original requester AND the student's own account when different. */
    private function notifyRequester(int $requestedBy, int $studentId, string $title, string $message): void
    {
        $targets = [];
        if ($requestedBy > 0) $targets[$requestedBy] = true;
        try {
            $stuUser = Database::one("SELECT user_id FROM students WHERE id = ? LIMIT 1", [$studentId]);
            if ($stuUser && !empty($stuUser['user_id'])) $targets[(int) $stuUser['user_id']] = true;
        } catch (Throwable $e) {
        }
        foreach (array_keys($targets) as $uid) {
            try {
                \App\Controllers\NotificationController::push(
                    (int) $uid, $title, $message, 'info', '/student/my-classes'
                );
            } catch (Throwable $e) {
            }
        }
    }

    public function studentLeave(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('student', 'parent');
        $id = (int)($params['id'] ?? 0);
        $userId = (int)Auth::id();
        $acting = $this->resolveActingStudent();
        if ($acting !== null && isset($acting['rejected'])) {
            set_flash('error', 'That child is not linked to your guardian account.');
            redirect('/dashboard');
        }
        $student = $acting !== null ? $acting['student'] : null;
        $viaParent = $acting !== null && !empty($acting['via_parent']);
        if (!$student) { set_flash('error','Student not found'); redirect('/dashboard'); }
        $member = Database::one("SELECT id FROM classroom_members WHERE classroom_id = ? AND student_id = ? LIMIT 1", [$id, (int)$student['id']]);
        if (!$member) { set_flash('error','Not a member of this classroom.'); redirect('/dashboard'); }
        Database::run("DELETE FROM classroom_members WHERE id = ?", [(int)$member['id']]);
        try {
            Database::insert('audit_logs', [
                'actor_user_id' => $userId, 'action' => 'classroom.left',
                'entity_type' => 'subject_classrooms', 'entity_id' => $id,
                'new_values_json' => json_encode(['student_id' => (int) $student['id'], 'via_parent' => $viaParent ? 1 : 0]),
                'ip_address' => client_ip(),
            ]);
        } catch (Throwable $e) {
        }
        set_flash('success', $viaParent ? ('Removed ' . ($acting['child_name'] ?? 'child') . ' from the subject.') : 'Left classroom.');
        redirect('/dashboard');
    }

    /* ====================== STUDENT: view task & upload submission ====================== */
    public function showTask(array $params = []): void
    {
        Auth::requireRole('student', 'parent', 'teacher', 'admin');
        $taskId = (int)($params['id'] ?? 0);
        $task = Database::one(
            "SELECT ct.*, sc.subject_code, sc.subject_title, sc.invite_code, sc.teacher_id, sc.academic_year_id,
                    gp.code AS period_code,
                    ac.code AS category_code, ac.name AS category_name,
                    u.first_name AS teacher_first, u.last_name AS teacher_last
               FROM classroom_tasks ct
               JOIN subject_classrooms sc ON sc.id = ct.classroom_id
               LEFT JOIN grade_periods gp ON gp.id = ct.period_id
               LEFT JOIN assessment_categories ac ON ac.id = ct.category_id
               JOIN teachers t ON t.id = sc.teacher_id
               JOIN users u ON u.id = t.user_id
              WHERE ct.id = ? LIMIT 1",
            [$taskId]
        );
        if (!$task) { http_response_code(404); exit('Task not found'); }

        $role = Auth::role();
        $userId = (int)Auth::id();

        // Permission: assigned teacher only (never another teacher's class),
        // admin, member student, or parent of a member child.
        $canView = false;
        $studentId = 0;
        $mySubmission = null;
        if ($role === 'teacher') {
            $teacher = Database::one("SELECT id FROM teachers WHERE user_id = ? LIMIT 1", [$userId]);
            if ($teacher && (int)$teacher['id'] === (int)$task['teacher_id']) $canView = true;
        } elseif ($role === 'admin') {
            $canView = true;
        } elseif ($role === 'parent') {
            $kids = class_exists(\App\Core\GuardianLinks::class)
                ? \App\Core\GuardianLinks::linkedChildren($userId) : [];
            foreach ($kids as $kid) {
                $kidId = (int) ($kid['id'] ?? 0);
                if ($kidId <= 0) continue;
                $member = Database::one("SELECT id FROM classroom_members WHERE classroom_id = ? AND student_id = ? LIMIT 1", [(int)$task['classroom_id'], $kidId]);
                if ($member) {
                    $canView = true;
                    $studentId = $kidId;
                    $mySubmission = Database::one("SELECT * FROM classroom_task_submissions WHERE task_id = ? AND student_id = ? LIMIT 1", [$taskId, $kidId]);
                    break;
                }
            }
        } else {
            $student = Database::one("SELECT id FROM students WHERE user_id = ? LIMIT 1", [$userId]);
            if ($student) {
                $studentId = (int)$student['id'];
                $member = Database::one("SELECT id FROM classroom_members WHERE classroom_id = ? AND student_id = ? LIMIT 1", [(int)$task['classroom_id'], $studentId]);
                if ($member) $canView = true;
                $mySubmission = Database::one("SELECT * FROM classroom_task_submissions WHERE task_id = ? AND student_id = ? LIMIT 1", [$taskId, $studentId]);
            }
        }
        // Allow view if enrolled student but not yet joined? still allow if student is enrolled
        if (!$canView) { http_response_code(403); exit('Not authorized for this task'); }

        // Exam-permit gate (students + parents): a task tagged with a grading
        // period (Prelim/Midterm/Prefinal/Finals) is an exam — members need a
        // fully-approved permit for that period to see or answer it.
        // Teachers and admins always see everything (they grade it).
        $examPeriod = self::taskExamPeriod($task);
        $permitLocked = false;
        if ($examPeriod !== null && $studentId > 0 && $role !== 'teacher' && $role !== 'admin') {
            $permitLocked = !self::studentHasExamPermit($studentId, (int) ($task['academic_year_id'] ?? 0), $examPeriod);
        }

        // Fetch all submissions for teacher view
        $submissions = [];
        if ($role === 'teacher' || $role === 'admin') {
            $submissions = Database::all(
                "SELECT cts.*, s.student_no, u.first_name, u.last_name, u.email
                   FROM classroom_task_submissions cts
                   JOIN students s ON s.id = cts.student_id
                   JOIN users u ON u.id = s.user_id
                  WHERE cts.task_id = ?
                  ORDER BY cts.submitted_at DESC",
                [$taskId]
            );
        }

        // Assessment summary from actual records (no fake numbers).
        $taskMax = max(0.01, (float) ($task['max_score'] ?? 100));
        $taskPassing = isset($task['passing_score']) && $task['passing_score'] !== null && $task['passing_score'] !== ''
            ? (float) $task['passing_score'] : null;
        $assessSummary = null;
        if ($role === 'teacher' || $role === 'admin') {
            $gradedScores = [];
            $pending = 0;
            $passed = 0;
            foreach ($submissions as $gs) {
                if (($gs['status'] ?? '') === 'graded' && $gs['score'] !== null && $gs['score'] !== '') {
                    $gradedScores[] = (float) $gs['score'];
                    if ($taskPassing !== null && (float) $gs['score'] >= $taskPassing) $passed++;
                } elseif (in_array($gs['status'] ?? '', ['submitted', 'late'], true)) {
                    $pending++;
                }
            }
            $memberCount = (int) Database::value("SELECT COUNT(*) FROM classroom_members WHERE classroom_id = ?", [(int) $task['classroom_id']]);
            $assessSummary = [
                'members' => $memberCount,
                'submissions' => count($submissions),
                'graded' => count($gradedScores),
                'pending' => $pending,
                'not_scored' => max(0, $memberCount - count($gradedScores)),
                'highest' => $gradedScores === [] ? null : max($gradedScores),
                'lowest' => $gradedScores === [] ? null : min($gradedScores),
                'average' => $gradedScores === [] ? null : array_sum($gradedScores) / count($gradedScores),
                'passed' => $taskPassing === null ? null : $passed,
                'needs_review' => $taskPassing === null ? $pending : (count($gradedScores) - $passed + $pending),
            ];
            if ($assessSummary['average'] !== null) {
                $assessSummary['average_pct'] = ($assessSummary['average'] / $taskMax) * 100;
            } else {
                $assessSummary['average_pct'] = null;
            }
        }

        $this->view('classrooms/task_show', [
            'title'        => $task['title'],
            'task'         => $task,
            'mySubmission' => $mySubmission,
            'submissions'  => $submissions,
            'isTeacher'    => $role === 'teacher' || $role === 'admin',
            'isOwner'      => $role === 'teacher' && isset($teacher['id']) && (int) $teacher['id'] === (int) $task['teacher_id'],
            'isParent'     => $role === 'parent',
            'permitLocked' => $permitLocked,
            'lockPeriod'   => $examPeriod,
            'assessSummary' => $assessSummary,
            'taskMax' => $taskMax,
            'taskPassing' => $taskPassing,
        ]);
    }

    /**
     * Permit-checked download of the teacher's task attachment.
     * Exam files must NOT be direct public links: students without a
     * fully-approved permit get 403 here even if they guess the flow.
     */
    public function downloadTaskFile(array $params = []): void
    {
        Auth::requireRole('student', 'parent', 'teacher', 'admin');
        $taskId = (int) ($params['id'] ?? 0);
        $task = Database::one(
            "SELECT ct.id, ct.classroom_id, ct.file_path, ct.original_filename, ct.mime_type,
                    sc.teacher_id, sc.academic_year_id, gp.code AS period_code
               FROM classroom_tasks ct
               JOIN subject_classrooms sc ON sc.id = ct.classroom_id
               LEFT JOIN grade_periods gp ON gp.id = ct.period_id
              WHERE ct.id = ? LIMIT 1",
            [$taskId]
        );
        if ($task === null || empty($task['file_path'])) {
            http_response_code(404);
            exit('File not found');
        }
        $role = Auth::role();
        $userId = (int) Auth::id();
        $allowed = false;
        $studentId = 0;
        if ($role === 'admin') {
            $allowed = true;
        } elseif ($role === 'teacher') {
            $t = Database::one("SELECT id FROM teachers WHERE user_id = ? LIMIT 1", [$userId]);
            $allowed = $t !== null && (int) $t['id'] === (int) $task['teacher_id'];
        } else {
            $kids = [];
            if ($role === 'parent' && class_exists(\App\Core\GuardianLinks::class)) {
                $kids = \App\Core\GuardianLinks::linkedChildren($userId);
            } elseif ($role === 'student') {
                $s = Database::one("SELECT id FROM students WHERE user_id = ? LIMIT 1", [$userId]);
                if ($s !== null) $kids = [['id' => (int) $s['id']]];
            }
            foreach ($kids as $kid) {
                $kidId = (int) ($kid['id'] ?? 0);
                if ($kidId <= 0) continue;
                $member = Database::one(
                    "SELECT id FROM classroom_members WHERE classroom_id = ? AND student_id = ? LIMIT 1",
                    [(int) $task['classroom_id'], $kidId]
                );
                if ($member !== null) {
                    $allowed = true;
                    $studentId = $kidId;
                    break;
                }
            }
            // Exam-permit gate for learners.
            if ($allowed && $studentId > 0) {
                $examPeriod = self::taskExamPeriod($task);
                if ($examPeriod !== null && !self::studentHasExamPermit($studentId, (int) ($task['academic_year_id'] ?? 0), $examPeriod)) {
                    http_response_code(403);
                    exit('This ' . $examPeriod . ' exam needs a fully approved permit first.');
                }
            }
        }
        if (!$allowed) {
            http_response_code(403);
            exit('Not authorized for this file');
        }
        $base = realpath(rtrim(UPLOAD_DIR, '/\\'));
        $full = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim((string) $task['file_path'], '/\\'));
        $real = realpath($full);
        if ($real === false || $base === false || !str_starts_with($real, $base) || !is_file($real)) {
            http_response_code(404);
            exit('File not found');
        }
        $mime = (string) ($task['mime_type'] ?? 'application/octet-stream');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($real));
        header('Content-Disposition: inline; filename="' . str_replace('"', '', (string) ($task['original_filename'] ?? basename($real))) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($real);
        exit;
    }

    public function submitTask(array $params = []): void
    {
        Csrf::guard();
        // Submissions are the student's own work: student accounts only.
        // Parents monitor via the read-only task view.
        Auth::requireRole('student');
        $taskId = (int)($params['id'] ?? 0);
        $userId = (int)Auth::id();
        $student = Database::one("SELECT * FROM students WHERE user_id = ? LIMIT 1", [$userId]);
        if (!$student) { set_flash('error','Student profile not found.'); redirect('/dashboard'); }
        if (($student['status'] ?? '') !== 'enrolled') { set_flash('warning','Fully enrolled students only. Complete payment first.'); redirect('/my-enrollments'); }
        $studentId = (int)$student['id'];

        $task = Database::one(
            "SELECT ct.*, sc.subject_code, sc.subject_title, sc.academic_year_id, gp.code AS period_code
               FROM classroom_tasks ct
               JOIN subject_classrooms sc ON sc.id = ct.classroom_id
               LEFT JOIN grade_periods gp ON gp.id = ct.period_id
              WHERE ct.id = ? LIMIT 1",
            [$taskId]
        );
        if (!$task) { set_flash('error','Task not found.'); redirect('/dashboard'); }

        // Must be member of the classroom
        $member = Database::one("SELECT id FROM classroom_members WHERE classroom_id = ? AND student_id = ? LIMIT 1", [(int)$task['classroom_id'], $studentId]);
        if (!$member) { set_flash('error','You are not a member of this subject. Join via invite first.'); redirect('/dashboard'); }

        // Exam-permit gate: no fully-approved permit, no answer (server-side —
        // the locked page alone is never the security control).
        $examPeriod = self::taskExamPeriod($task);
        if ($examPeriod !== null && !self::studentHasExamPermit($studentId, (int) ($task['academic_year_id'] ?? 0), $examPeriod)) {
            set_flash('error', "This {$examPeriod} exam needs a fully approved {$examPeriod} permit first. Request it under My Permits, then come back to answer.");
            redirect('/student/permits');
        }

        // Check due date: block late submission? Allow but mark late. For now allow.
        $isLate = false;
        if (!empty($task['due_at']) && strtotime($task['due_at']) !== false) {
            if (time() > strtotime($task['due_at'])) $isLate = true;
        }

        $note = trim((string)($_POST['note'] ?? ''));
        $hasFile = !empty($_FILES['submission_file']) && $_FILES['submission_file']['error'] !== UPLOAD_ERR_NO_FILE;

        if (!$hasFile && $note === '') {
            set_flash('error','Please provide a file or a note to submit.');
            redirect('/classroom-tasks/'.$taskId);
        }

        $filePath = null; $originalFilename = null; $mimeType = null; $fileSize = null;
        if ($hasFile) {
            $file = $_FILES['submission_file'];
            if ($file['error'] !== UPLOAD_ERR_OK) { set_flash('error','File upload error.'); redirect('/classroom-tasks/'.$taskId); }
            if ($file['size'] > UPLOAD_MAX_BYTES) { set_flash('error','File too large (max 10 MB).'); redirect('/classroom-tasks/'.$taskId); }
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : ($file['type'] ?? 'application/octet-stream');
            if ($finfo) finfo_close($finfo);
            if (!in_array($mime, UPLOAD_ALLOWED_MIME, true) && !str_starts_with($mime, 'image/')) {
                // Allow images generically, block others not in allow list
                // Also allow common submission types: pdf, doc, docx, txt, zip
                $allowedExtra = ['text/plain','application/zip','application/x-zip-compressed'];
                if (!in_array($mime, $allowedExtra, true)) {
                    set_flash('error','File type not allowed ('.e($mime).'). Allowed: PDF, JPG, PNG, WebP, DOC/DOCX, TXT, ZIP.');
                    redirect('/classroom-tasks/'.$taskId);
                }
            }
            $ext = \App\Core\Uploads::safeExt((string) $file['name'], (string) $mime);
            if ($ext === null) {
                set_flash('error','File extension not allowed. Allowed: PDF, JPG, PNG, WebP, DOC/DOCX, TXT, ZIP.');
                redirect('/classroom-tasks/'.$taskId);
            }
            $stored = \App\Core\Uploads::storedName('cts_'.$taskId.'_'.$studentId, $ext);
            $uploadDir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . 'submissions';
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
            $dest = $uploadDir . DIRECTORY_SEPARATOR . $stored;
            if (!is_uploaded_file($file['tmp_name']) || !move_uploaded_file($file['tmp_name'], $dest)) {
                set_flash('error','Could not save file.');
                redirect('/classroom-tasks/'.$taskId);
            }
            $filePath = 'submissions/'.$stored;
            $originalFilename = $file['name'];
            $mimeType = $mime;
            $fileSize = (int)$file['size'];
        }

        // Upsert submission — when already graded, lock (no more uploads)
        $existing = Database::one("SELECT id, status FROM classroom_task_submissions WHERE task_id = ? AND student_id = ? LIMIT 1", [$taskId, $studentId]);
        if ($existing && ($existing['status'] ?? '') === 'graded') {
            set_flash('error','Your submission has already been graded and is now locked — you cannot upload another file. If you need to resubmit, ask your teacher to return it.');
            redirect('/classroom-tasks/'.$taskId);
        }
        $status = $isLate ? 'late' : 'submitted';
        try {
            if ($existing) {
                // If new file uploaded, keep old file? For simplicity overwrite path
                $updateData = [
                    'note'       => $note ?: null,
                    'status'     => $status,
                    'submitted_at'=> date('Y-m-d H:i:s'),
                ];
                if ($filePath !== null) {
                    $updateData['file_path'] = $filePath;
                    $updateData['original_filename'] = $originalFilename;
                    $updateData['mime_type'] = $mimeType;
                    $updateData['file_size_bytes'] = $fileSize;
                }
                // Build dynamic update
                $set = []; $params = [];
                foreach ($updateData as $k=>$v) { $set[] = "`$k` = ?"; $params[] = $v; }
                $params[] = (int)$existing['id'];
                Database::run("UPDATE classroom_task_submissions SET ".implode(', ', $set)." WHERE id = ?", $params);
                set_flash('success','Submission updated ('.$status.').');
            } else {
                Database::insert('classroom_task_submissions', [
                    'task_id'           => $taskId,
                    'student_id'        => $studentId,
                    'file_path'         => $filePath,
                    'original_filename' => $originalFilename,
                    'mime_type'         => $mimeType,
                    'file_size_bytes'   => $fileSize,
                    'note'              => $note ?: null,
                    'status'            => $status,
                ]);
                set_flash('success','Task submitted successfully ('.$status.').');
            }
            Database::insert('audit_logs', ['actor_user_id'=>$userId,'action'=>'classroom.task_submitted','entity_type'=>'classroom_tasks','entity_id'=>$taskId,'ip_address'=>client_ip()]);
            // Notify the classroom teacher (shows in their bell on every page)
            try {
                $owner = Database::one(
                    "SELECT t.user_id FROM classroom_tasks ct JOIN subject_classrooms sc ON sc.id = ct.classroom_id JOIN teachers t ON t.id = sc.teacher_id WHERE ct.id = ? LIMIT 1",
                    [$taskId]
                );
                if ($owner && !empty($owner['user_id'])) {
                    NotificationController::push((int)$owner['user_id'], 'New submission: ' . ($task['title'] ?? 'task'), ($student['student_no'] ?? 'A student') . ' submitted in ' . ($task['subject_code'] ?? 'your subject') . ' (' . $status . ').', 'task', '/classroom-tasks/' . $taskId);
                }
            } catch (Throwable $e) {
            }
        } catch (Throwable $e) {
            storage_log('task submit failed: '.$e->getMessage());
            set_flash('error','Could not submit: '.$e->getMessage());
        }
        redirect('/classroom-tasks/'.$taskId);
    }

    /* ====================== TEACHER: grade / approve / reject submission ====================== */
    public function gradeSubmission(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('teacher','admin');
        $submissionId = (int)($params['id'] ?? 0);
        $sub = Database::one(
            "SELECT cts.*, ct.title AS task_title, ct.classroom_id, ct.max_score AS task_max, sc.teacher_id, sc.subject_code
               FROM classroom_task_submissions cts
               JOIN classroom_tasks ct ON ct.id = cts.task_id
               JOIN subject_classrooms sc ON sc.id = ct.classroom_id
              WHERE cts.id = ? LIMIT 1",
            [$submissionId]
        );
        if (!$sub) { set_flash('error','Submission not found.'); redirect('/dashboard'); }
        // Finalized submissions are view-only — cannot re-grade or re-reject.
        if (in_array($sub['status'] ?? '', ['graded','returned'], true)) {
            set_flash('error','This submission is already '.($sub['status']==='graded'?'GRADED':'REJECTED').' and is locked (view-only).');
            redirect('/classroom-tasks/'.$sub['task_id']);
        }
        // Registrar-approved grades are locked for everyone except Registrar review flow.
        if (($sub['review_status'] ?? '') === 'approved') {
            set_flash('error','This grade is Approved by the Registrar and locked. Contact Registrar for corrections.');
            redirect('/classroom-tasks/'.$sub['task_id']);
        }
        // Verify teacher owns the classroom (or admin)
        if (Auth::role() !== 'admin') {
            $teacher = Database::one("SELECT id FROM teachers WHERE user_id = ? LIMIT 1", [(int)Auth::id()]);
            if (!$teacher || (int)$teacher['id'] !== (int)$sub['teacher_id']) {
                set_flash('error','Not authorized to grade this submission.');
                redirect('/dashboard');
            }
        }
        $action   = trim((string)($_POST['action'] ?? 'grade')); // approve | reject | grade
        $scoreRaw = trim((string)($_POST['score'] ?? ''));
        $feedback = trim((string)($_POST['feedback'] ?? ''));
        if ($action === 'reject' && mb_strlen($feedback) < 5) {
            set_flash('error','Returning a submission requires feedback for the student (min 5 characters).');
            redirect('/classroom-tasks/'.$sub['task_id']);
        }
        // Raw score is validated against THIS task's Total Items / Max Score
        // (backend-authoritative — frontend max is only a hint).
        $taskMax = max(0.01, (float) ($sub['task_max'] ?? 100));
        $score = null;
        if ($scoreRaw !== '') {
            $chk = \App\Core\GradeEngine::validateRaw($scoreRaw, $taskMax);
            if (!$chk['ok']) {
                set_flash('error', $chk['error']);
                redirect('/classroom-tasks/'.$sub['task_id']);
            }
            $score = $chk['value'];
        }
        // Approving records a raw score — required (0–max). Rejecting
        // sends work back for revision, so its score stays optional.
        if (($action === 'approve' || $action === 'grade') && $score === null) {
            set_flash('error','Approving requires a raw score (0–' . rtrim(rtrim(number_format($taskMax,2,'.',''), '0'), '.') . '). Enter the score, or Reject with feedback instead.');
            redirect('/classroom-tasks/'.$sub['task_id']);
        }
        // Map action to status: approve/grade → graded, reject → returned
        $status = 'graded';
        if ($action === 'reject') $status = 'returned';
        elseif ($action === 'approve' || $action === 'grade') $status = 'graded';

        try {
            Database::run(
                "UPDATE classroom_task_submissions SET status = ?, score = COALESCE(?, score), feedback = ?, graded_by = ?, graded_at = NOW(), review_status = 'submitted', reviewed_by = NULL, reviewed_at = NULL WHERE id = ?",
                [$status, $score, $feedback !== '' ? $feedback : null, (int)Auth::id(), $submissionId]
            );
            // If only feedback/score without status change, keep graded
            if ($score !== null && $status === 'returned') {
                Database::run("UPDATE classroom_task_submissions SET score = ? WHERE id = ?", [$score, $submissionId]);
            }
            // Notify student — raw score label (never "final grade" for one assessment)
            $stuUser = Database::one("SELECT s.user_id, u.email FROM students s JOIN users u ON u.id=s.user_id WHERE s.id = ? LIMIT 1", [(int)$sub['student_id']]);
            $rawLabel = $score !== null ? \App\Core\GradeEngine::fmtRaw($score, $taskMax) : '';
            if ($stuUser) {
                $title = $status === 'graded' ? ($score !== null ? "Raw score: {$rawLabel} — {$sub['task_title']}" : "Approved: {$sub['task_title']}") : "Returned: {$sub['task_title']}";
                $msg   = $feedback !== '' ? $feedback : ($status === 'graded' ? 'Your work has been graded (' . $rawLabel . '). Check your dashboard.' : 'Your submission was returned. Please revise.');
                Database::insert('notifications', [
                    'user_id'  => (int)$stuUser['user_id'],
                    'title'    => $title,
                    'message'  => $msg,
                    'type'     => $status === 'graded' ? 'success' : 'warning',
                    'link_url' => url('/classroom-tasks/'.$sub['task_id']),
                ]);
            }
            Database::insert('audit_logs', ['actor_user_id'=>(int)Auth::id(),'action'=>'classroom.submission_'.$status,'entity_type'=>'classroom_task_submissions','entity_id'=>$submissionId,'old_values_json'=>json_encode(['old_score'=>$sub['score'] ?? null,'old_status'=>$sub['status'] ?? null]),'new_values_json'=>json_encode(['new_score'=>$score,'new_status'=>$status,'task_max'=>$taskMax]),'ip_address'=>client_ip()]);
            set_flash('success', $status === 'graded' ? 'Submission approved/graded successfully.' : 'Submission rejected/returned with feedback.');
        } catch (Throwable $e) {
            storage_log('grade submission failed: '.$e->getMessage());
            set_flash('error','Could not grade: '.$e->getMessage());
        }
        redirect('/classroom-tasks/'.$sub['task_id']);
    }

    /* ====================== Shared: view (optional detail page) ====================== */
    public function show(array $params = []): void
    {
        Auth::requireRole('teacher','student','parent','admin');
        $id = (int)($params['id'] ?? 0);
        $class = Database::one(
            "SELECT sc.*, ay.school_year, t.employee_no, u.first_name, u.last_name
               FROM subject_classrooms sc
               LEFT JOIN teachers t ON t.id = sc.teacher_id
               LEFT JOIN users u ON u.id = t.user_id
               JOIN academic_years ay ON ay.id = sc.academic_year_id
              WHERE sc.id = ? LIMIT 1",
            [$id]
        );
        if (!$class) { http_response_code(404); exit('Classroom not found'); }
        // Ownership scoping: students see only joined classes, parents only a
        // linked child's joined classes, teachers only assigned classes.
        // (Admin oversight retains full access.)
        $role = Auth::role();
        $userId = (int) Auth::id();
        if ($role === 'student') {
            $me = Database::one("SELECT id FROM students WHERE user_id = ? LIMIT 1", [$userId]);
            $ok = $me && Database::one("SELECT id FROM classroom_members WHERE classroom_id = ? AND student_id = ? LIMIT 1", [$id, (int) $me['id']]);
            if (!$ok) { http_response_code(403); exit('Not authorized for this classroom.'); }
        } elseif ($role === 'parent') {
            $ok = false;
            if (class_exists(\App\Core\GuardianLinks::class)) {
                foreach (\App\Core\GuardianLinks::linkedChildren($userId) as $kid) {
                    if (Database::one("SELECT id FROM classroom_members WHERE classroom_id = ? AND student_id = ? LIMIT 1", [$id, (int) ($kid['id'] ?? 0)])) {
                        $ok = true;
                        break;
                    }
                }
            }
            if (!$ok) { http_response_code(403); exit('Not authorized for this classroom.'); }
        } elseif ($role === 'teacher') {
            $t = Database::one("SELECT id FROM teachers WHERE user_id = ? LIMIT 1", [$userId]);
            if (!$t || (int) $t['id'] !== (int) ($class['teacher_id'] ?? 0)) {
                http_response_code(403); exit('Not authorized for this classroom.');
            }
        }
        $members = Database::all(
            "SELECT cm.joined_at, s.student_no, u.first_name, u.last_name, u.email
               FROM classroom_members cm
               JOIN students s ON s.id = cm.student_id
               JOIN users u ON u.id = s.user_id
              WHERE cm.classroom_id = ?
              ORDER BY cm.joined_at ASC",
            [$id]
        );
        // Pending code-free join requests (owner/admin only — verify before enrolling).
        $joinRequests = [];
        try {
            $isOwner = false;
            if (Auth::role() === 'admin') {
                $isOwner = true;
            } elseif (Auth::role() === 'teacher') {
                $t = Database::one("SELECT id FROM teachers WHERE user_id = ? LIMIT 1", [(int) Auth::id()]);
                $isOwner = $t && (int) $t['id'] === (int) ($class['teacher_id'] ?? 0);
            }
            if ($isOwner && $this->hasJoinRequestTable()) {
                $joinRequests = Database::all(
                    "SELECT jr.*, s.student_no, u.first_name, u.last_name,
                            ru.first_name AS req_first, ru.last_name AS req_last
                       FROM class_join_requests jr
                       JOIN students s ON s.id = jr.student_id
                       LEFT JOIN users u ON u.id = s.user_id
                       LEFT JOIN users ru ON ru.id = jr.requested_by
                      WHERE jr.classroom_id = ? AND jr.status = 'pending'
                      ORDER BY jr.requested_at ASC LIMIT 100",
                    [$id]
                );
            }
        } catch (Throwable $e) {
            $joinRequests = [];
        }
        // Activities/tasks for this classroom — include my submission + counts so uploaded files are visible here
        $tasks = [];
        try {
            $hasTasks = Database::value("SHOW TABLES LIKE 'classroom_tasks'");
            if ($hasTasks) {
                $mySid = 0;
                if (in_array(Auth::role(), ['student','parent'], true)) {
                    $stuTmp = Database::one("SELECT id FROM students WHERE user_id = ? LIMIT 1", [(int)Auth::id()]);
                    if ($stuTmp) $mySid = (int)$stuTmp['id'];
                }
                $tasks = Database::all(
                    "SELECT ct.*, u.first_name, u.last_name,
                            mycts.id AS my_submission_id, mycts.file_path AS my_file_path, mycts.original_filename AS my_original_filename, mycts.status AS my_status, mycts.submitted_at AS my_submitted_at,
                            (SELECT COUNT(*) FROM classroom_task_submissions cts2 WHERE cts2.task_id = ct.id) AS submission_count,
                            (SELECT COUNT(*) FROM classroom_task_submissions cts3 WHERE cts3.task_id = ct.id AND cts3.file_path IS NOT NULL) AS file_count
                       FROM classroom_tasks ct
                       LEFT JOIN users u ON u.id = ct.posted_by
                       LEFT JOIN classroom_task_submissions mycts ON mycts.task_id = ct.id AND mycts.student_id = ?
                      WHERE ct.classroom_id = ?
                      ORDER BY ct.created_at DESC
                      LIMIT 50",
                    [$mySid, $id]
                );
            }
        } catch (Throwable $e) { $tasks = []; }
        $this->view('classrooms/show', ['title'=> $class['subject_code'].' — '.$class['subject_title'], 'classroom'=>$class, 'members'=>$members, 'tasks'=>$tasks, 'joinRequests'=>$joinRequests]);
    }
}
