<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use Throwable;

/**
 * Admin-managed Subjects / Class Offerings.
 * Official flow: ADMIN creates subject -> assigns registered teacher ->
 * appears in Class Offerings + teacher dashboard -> students join via
 * unique student invite code (single-offering, non-guessable, regen-able).
 * Manages `subject_classrooms` as the official offering store.
 */
final class ClassOfferingController extends Controller
{
    private function hasCol(string $col): bool
    {
        try {
            return (bool) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subject_classrooms' AND COLUMN_NAME = ?",
                [$col]
            );
        } catch (Throwable $e) { return false; }
    }

    private function generateCode(int $len = 7): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $max = strlen($alphabet) - 1;
        for ($tries = 0; $tries < 20; $tries++) {
            $out = '';
            for ($i = 0; $i < $len; $i++) $out .= $alphabet[random_int(0, $max)];
            $exists = Database::one("SELECT id FROM subject_classrooms WHERE invite_code = ? LIMIT 1", [$out]);
            if (!$exists) return $out;
        }
        return 'CS' . strtoupper(bin2hex(random_bytes(3)));
    }

    public function index(array $params = []): void
    {
        Auth::requireRole('admin');
        $search = trim((string)($_GET['q'] ?? ''));
        $statusF = trim((string)($_GET['status'] ?? ''));
        $teacherF = (int)($_GET['teacher_id'] ?? 0);
        $hasStatus = $this->hasCol('status');
        $hasSem = $this->hasCol('semester');
        $hasSched = $this->hasCol('schedule');
        $hasRoom = $this->hasCol('room');

        $where = [];
        $bind = [];
        if ($search !== '') {
            $where[] = '(sc.subject_code LIKE ? OR sc.subject_title LIKE ? OR sc.section_name LIKE ? OR sc.invite_code LIKE ?)';
            $like = '%' . $search . '%';
            $bind = array_merge($bind, [$like, $like, $like, $like]);
        }
        if ($statusF !== '' && $hasStatus) { $where[] = 'sc.status = ?'; $bind[] = $statusF; }
        elseif ($statusF === 'active') { $where[] = 'sc.is_active = 1'; }
        elseif ($statusF === 'inactive') { $where[] = 'sc.is_active = 0'; }
        if ($teacherF > 0) { $where[] = 'sc.teacher_id = ?'; $bind[] = $teacherF; }
        $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $extra = '';
        if ($hasSem) $extra .= ', sc.semester';
        if ($hasSched) $extra .= ', sc.schedule';
        if ($hasRoom) $extra .= ', sc.room';
        if ($hasStatus) $extra .= ', sc.status';
        if ($this->hasCol('subject_id')) $extra .= ', sc.subject_id';
        if ($this->hasCol('grade_level_id')) $extra .= ', sc.grade_level_id, gl.name AS level_name, gl.code AS level_code, gl.category AS level_category';
        if ($this->hasCol('program_id')) $extra .= ', sc.program_id, p.code AS program_code, p.name AS program_name';
        if ($this->hasCol('period_code')) $extra .= ', sc.period_code';
        $lvlJoin = $this->hasCol('grade_level_id') ? 'LEFT JOIN grade_levels gl ON gl.id = sc.grade_level_id' : '';
        $progJoin = $this->hasCol('program_id') ? 'LEFT JOIN programs p ON p.id = sc.program_id' : '';

        $rows = Database::all(
            "SELECT sc.id, sc.subject_code, sc.subject_title, sc.description, sc.section_name,
                    sc.invite_code, sc.max_students, sc.is_active, sc.teacher_id, sc.academic_year_id,
                    ay.school_year, CONCAT(tu.first_name,' ',tu.last_name) AS teacher_name, tu.email AS teacher_email,
                    t.employee_no{$extra},
                    (SELECT COUNT(*) FROM classroom_members cm WHERE cm.classroom_id = sc.id) AS enrolled
               FROM subject_classrooms sc
               JOIN academic_years ay ON ay.id = sc.academic_year_id
               LEFT JOIN teachers t ON t.id = sc.teacher_id
               LEFT JOIN users tu ON tu.id = t.user_id
               {$lvlJoin}
               {$progJoin}
               {$whereSql}
               ORDER BY sc.created_at DESC LIMIT 200",
            $bind
        );
        $teachers = Database::all(
            "SELECT t.id, t.employee_no, t.department, CONCAT(u.first_name,' ',u.last_name) AS name, u.email
               FROM teachers t JOIN users u ON u.id = t.user_id
              WHERE u.status = 'active'
              ORDER BY u.first_name, u.last_name LIMIT 300"
        );
        $ays = Database::all("SELECT id, school_year, is_current FROM academic_years ORDER BY school_year DESC LIMIT 20");
        $subjects = [];
        $gradeLevels = [];
        $programs = [];
        try {
            $subjPerCol = $this->hasCol('period_code') && (bool) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subjects' AND COLUMN_NAME = 'period_code'"
            );
            $subjPerSel = $subjPerCol ? ', period_code' : '';
            $subjects = Database::all("SELECT id, code, title, grade_level_id, semester{$subjPerSel} FROM subjects WHERE is_active = 1 ORDER BY code LIMIT 500");
        } catch (Throwable $e) {
        }
        try {
            $gradeLevels = Database::all("SELECT id, code, name, category FROM grade_levels ORDER BY sort_order");
        } catch (Throwable $e) {
        }
        try {
            $programs = Database::all("SELECT id, code, name FROM programs WHERE is_active = 1 ORDER BY code");
        } catch (Throwable $e) {
        }
        $periodOptions = [];
        try {
            if (class_exists(\App\Core\AcademicPeriod::class)) {
                $periodOptions = [
                    'basic' => \App\Core\AcademicPeriod::periodsFor(\App\Core\AcademicPeriod::BAND_BASIC),
                    'shs' => \App\Core\AcademicPeriod::periodsFor(\App\Core\AcademicPeriod::BAND_SHS),
                    'college' => \App\Core\AcademicPeriod::periodsFor(\App\Core\AcademicPeriod::BAND_COLLEGE),
                    'basic_noun' => \App\Core\AcademicPeriod::noun(\App\Core\AcademicPeriod::BAND_BASIC),
                ];
            }
        } catch (Throwable $e) {
        }
        $this->view('admin/class_offerings/index', [
            'title' => 'Subjects & Class Offerings',
            'rows' => $rows, 'teachers' => $teachers, 'ays' => $ays,
            'subjects' => $subjects, 'gradeLevels' => $gradeLevels, 'programs' => $programs,
            'periodOptions' => $periodOptions,
            'filters' => ['q' => $search, 'status' => $statusF, 'teacher_id' => $teacherF],
            'hasStatus' => $hasStatus,
            'hasAcadCols' => $this->hasCol('grade_level_id') && $this->hasCol('program_id'),
            'hasPeriodCol' => $this->hasCol('period_code'),
        ]);
    }

    public function store(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');
        $code = strtoupper(trim((string)($_POST['subject_code'] ?? '')));
        $title = trim((string)($_POST['subject_title'] ?? $_POST['subject_name'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));
        $ayId = (int)($_POST['academic_year_id'] ?? 0);
        $semester = trim((string)($_POST['semester'] ?? ''));
        $section = trim((string)($_POST['section_name'] ?? $_POST['section'] ?? ''));
        $schedule = trim((string)($_POST['schedule'] ?? ''));
        $room = trim((string)($_POST['room'] ?? ''));
        $status = trim((string)($_POST['status'] ?? 'open'));
        // Teacher is OPTIONAL at creation (draft-then-assign: admin may still be
        // finding a suitable teacher). Teacherless subjects stay hidden from
        // students until assigned.
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $max = max(1, min(500, (int)($_POST['max_students'] ?? 40)));
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $gradeLevelId = (int)($_POST['grade_level_id'] ?? 0);
        $programId = (int)($_POST['program_id'] ?? 0);
        $periodCode = trim((string)($_POST['period_code'] ?? ''));

        // When linked to the canonical curriculum record, derive code/title/level/period from it.
        if ($subjectId > 0) {
            try {
                $canon = Database::one('SELECT * FROM subjects WHERE id = ? LIMIT 1', [$subjectId]);
                if ($canon) {
                    if ($code === '') $code = strtoupper(trim((string) $canon['code']));
                    if ($title === '') $title = trim((string) $canon['title']);
                    if ($gradeLevelId <= 0 && !empty($canon['grade_level_id'])) $gradeLevelId = (int) $canon['grade_level_id'];
                    if ($semester === '' && !empty($canon['semester'])) $semester = (string) $canon['semester'];
                    if ($periodCode === '' && !empty($canon['period_code'] ?? null)) $periodCode = trim((string) $canon['period_code']);
                } else {
                    $subjectId = 0;
                }
            } catch (Throwable $e) {
                $subjectId = 0;
            }
        }
        // Auto-link by code so year-level eligibility works even if admin skips the dropdown.
        if ($subjectId <= 0 && $code !== '') {
            try {
                $canon = Database::one('SELECT * FROM subjects WHERE code = ? AND is_active = 1 ORDER BY id LIMIT 1', [$code]);
                if ($canon) {
                    $subjectId = (int) $canon['id'];
                    if ($gradeLevelId <= 0 && !empty($canon['grade_level_id'])) $gradeLevelId = (int) $canon['grade_level_id'];
                    if ($periodCode === '' && !empty($canon['period_code'] ?? null)) $periodCode = trim((string) $canon['period_code']);
                }
            } catch (Throwable $e) {
            }
        }

        if ($code === '' || $title === '' || $ayId <= 0) {
            set_flash('error', 'Subject code, name, and school year are required. Teacher can be assigned later.');
            redirect('/admin/class-offerings');
        }
        if ($gradeLevelId > 0) {
            try {
                $lv = Database::one('SELECT id FROM grade_levels WHERE id = ? LIMIT 1', [$gradeLevelId]);
                if (!$lv) $gradeLevelId = 0;
            } catch (Throwable $e) {
                $gradeLevelId = 0;
            }
        }
        if ($programId > 0) {
            try {
                $pg = Database::one('SELECT id FROM programs WHERE id = ? LIMIT 1', [$programId]);
                if (!$pg) $programId = 0;
            } catch (Throwable $e) {
                $programId = 0;
            }
        }
        if (!in_array($semester, ['1st', '2nd', 'Summer', ''], true)) $semester = '';
        // Band-correct period: Basic-ed (K–G10) uses quarters/terms in
        // period_code with semester left NULL; SHS/College use semester.
        $band = null;
        if ($gradeLevelId > 0 && class_exists(\App\Core\AcademicPeriod::class)) {
            $band = \App\Core\AcademicPeriod::bandForLevel($gradeLevelId);
        }
        if ($band === \App\Core\AcademicPeriod::BAND_BASIC && $this->hasCol('period_code')) {
            if ($periodCode === '' || !\App\Core\AcademicPeriod::isValid($band, $periodCode)) {
                set_flash('error', 'Select the ' . \App\Core\AcademicPeriod::noun($band) . ' for this Basic Education subject (e.g. ' . \App\Core\AcademicPeriod::firstPeriod($band) . ').');
                redirect('/admin/class-offerings');
            }
            $semester = '';
            // Basic-ed subjects have no college programs and no semesters —
            // never store them, so they can't wrongly restrict joining later.
            $programId = 0;
        } elseif ($band === \App\Core\AcademicPeriod::BAND_BASIC) {
            $semester = '';
            $programId = 0;
        } else {
            $periodCode = '';
        }
        if (!in_array($status, ['open', 'closed', 'archived'], true)) $status = 'open';
        if ($teacherId > 0) {
            $t = Database::one("SELECT t.id FROM teachers t JOIN users u ON u.id = t.user_id WHERE t.id = ? AND u.status = 'active' LIMIT 1", [$teacherId]);
            if (!$t) { set_flash('error', 'Selected teacher is not registered/active.'); redirect('/admin/class-offerings'); }
        }
        $ay = Database::one("SELECT id FROM academic_years WHERE id = ? LIMIT 1", [$ayId]);
        if (!$ay) { set_flash('error', 'Invalid school year.'); redirect('/admin/class-offerings'); }

        try {
            $invite = $this->generateCode(7);
            $data = [
                'invite_code' => $invite, 'subject_code' => $code, 'subject_title' => $title,
                'description' => $desc ?: null, 'teacher_id' => $teacherId > 0 ? $teacherId : null, 'academic_year_id' => $ayId,
                'section_name' => $section ?: null, 'max_students' => $max, 'is_active' => $status === 'open' ? 1 : 0,
            ];
            if ($this->hasCol('semester')) $data['semester'] = $semester ?: null;
            if ($this->hasCol('schedule')) $data['schedule'] = $schedule ?: null;
            if ($this->hasCol('room')) $data['room'] = $room ?: null;
            if ($this->hasCol('status')) $data['status'] = $status;
            if ($this->hasCol('subject_id')) $data['subject_id'] = $subjectId > 0 ? $subjectId : null;
            if ($this->hasCol('grade_level_id')) $data['grade_level_id'] = $gradeLevelId > 0 ? $gradeLevelId : null;
            if ($this->hasCol('program_id')) $data['program_id'] = $programId > 0 ? $programId : null;
            if ($this->hasCol('period_code')) $data['period_code'] = $periodCode !== '' ? $periodCode : null;
            if ($this->hasCol('created_by')) $data['created_by'] = (int)Auth::id();
            $id = Database::insert('subject_classrooms', $data);
            Database::insert('audit_logs', ['actor_user_id' => (int)Auth::id(), 'action' => 'admin.subject_created', 'entity_type' => 'subject_classrooms', 'entity_id' => $id, 'ip_address' => client_ip()]);
            if ($teacherId > 0) {
                set_flash('success', "Subject {$code} created, assigned, and published to Class Offerings. Student invite code: {$invite}");
            } else {
                set_flash('success', "Subject {$code} created as a draft (no teacher yet) — invite code: {$invite}. Assign a teacher to publish it to students.");
            }
        } catch (Throwable $e) {
            storage_log('admin subject create failed: ' . $e->getMessage());
            set_flash('error', 'Could not create subject: ' . $e->getMessage());
        }
        redirect('/admin/class-offerings');
    }

    public function update(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');
        $id = (int)($params['id'] ?? 0);
        $sc = Database::one("SELECT * FROM subject_classrooms WHERE id = ? LIMIT 1", [$id]);
        if (!$sc) { set_flash('error', 'Subject not found.'); redirect('/admin/class-offerings'); }
        $data = [];
        foreach (['subject_code' => 'subject_code', 'subject_title' => 'subject_title', 'subject_name' => 'subject_title', 'description' => 'description', 'section_name' => 'section_name', 'section' => 'section_name', 'schedule' => 'schedule', 'room' => 'room', 'semester' => 'semester'] as $post => $col) {
            if (!array_key_exists($post, $_POST)) continue;
            if (!$this->hasCol($col) && !in_array($col, ['subject_code', 'subject_title', 'description', 'section_name'], true)) continue;
            $v = trim((string)$_POST[$post]);
            if ($col === 'subject_code') $v = strtoupper($v);
            $data[$col] = $v !== '' ? substr($v, 0, 255) : null;
        }
        if (isset($_POST['max_students'])) $data['max_students'] = max(1, min(500, (int)$_POST['max_students']));
        if (isset($_POST['academic_year_id']) && (int)$_POST['academic_year_id'] > 0) $data['academic_year_id'] = (int)$_POST['academic_year_id'];
        foreach (['subject_id' => 'subject_id', 'grade_level_id' => 'grade_level_id', 'program_id' => 'program_id'] as $post => $col) {
            if (!array_key_exists($post, $_POST) || !$this->hasCol($col)) continue;
            $v = (int) $_POST[$post];
            $data[$col] = $v > 0 ? $v : null;
        }
        if (array_key_exists('period_code', $_POST) && $this->hasCol('period_code')) {
            $pv = trim((string) $_POST['period_code']);
            $targetLevel = (int) ($data['grade_level_id'] ?? $sc['grade_level_id'] ?? 0);
            $targetBand = ($targetLevel > 0 && class_exists(\App\Core\AcademicPeriod::class))
                ? \App\Core\AcademicPeriod::bandForLevel($targetLevel) : null;
            if ($targetBand === \App\Core\AcademicPeriod::BAND_BASIC) {
                if ($pv === '' || !\App\Core\AcademicPeriod::isValid($targetBand, $pv)) {
                    set_flash('error', 'Select a valid ' . \App\Core\AcademicPeriod::noun($targetBand) . ' for this Basic Education subject.');
                    redirect('/admin/class-offerings');
                }
                $data['period_code'] = $pv;
                if ($this->hasCol('semester')) $data['semester'] = null;
            } else {
                $data['period_code'] = null;
            }
        }
        // Single-source invariant: explicitly setting a semester clears any
        // basic-ed period_code (SHS/College rows use semester only).
        if (array_key_exists('semester', $data) && $data['semester'] !== null && $this->hasCol('period_code')) {
            $data['period_code'] = null;
        }
        // Band invariant on level change: moving a subject INTO Basic-ed
        // drops any college program/semester; moving it OUT drops the quarter.
        $targetLevel = (int) ($data['grade_level_id'] ?? $sc['grade_level_id'] ?? 0);
        if ($targetLevel > 0 && class_exists(\App\Core\AcademicPeriod::class)) {
            $targetBand = \App\Core\AcademicPeriod::bandForLevel($targetLevel);
            if ($targetBand === \App\Core\AcademicPeriod::BAND_BASIC) {
                if ($this->hasCol('program_id')) $data['program_id'] = null;
                if ($this->hasCol('semester')) $data['semester'] = null;
            } elseif ($this->hasCol('period_code')) {
                $data['period_code'] = null;
            }
        }
        // Keep canonical link consistent: changing subject re-derives an unset year level / period.
        if (!empty($data['subject_id']) && !array_key_exists('grade_level_id', $data) && $this->hasCol('grade_level_id')) {
            try {
                $canon = Database::one('SELECT grade_level_id FROM subjects WHERE id = ? LIMIT 1', [(int) $data['subject_id']]);
                if ($canon && !empty($canon['grade_level_id']) && empty($sc['grade_level_id'])) {
                    $data['grade_level_id'] = (int) $canon['grade_level_id'];
                }
            } catch (Throwable $e) {
            }
        }
        if (!empty($data['subject_id']) && !array_key_exists('period_code', $data) && $this->hasCol('period_code')) {
            try {
                $canon = Database::one('SELECT period_code FROM subjects WHERE id = ? LIMIT 1', [(int) $data['subject_id']]);
                if ($canon && !empty($canon['period_code']) && empty($sc['period_code'] ?? null)) {
                    $data['period_code'] = trim((string) $canon['period_code']);
                    if ($this->hasCol('semester')) $data['semester'] = null;
                }
            } catch (Throwable $e) {
            }
        }
        if (empty($data)) { set_flash('info', 'No changes.'); redirect('/admin/class-offerings'); }
        try {
            $set = []; $bind = [];
            foreach ($data as $k => $v) { $set[] = "`{$k}` = ?"; $bind[] = $v; }
            $bind[] = $id;
            Database::run('UPDATE subject_classrooms SET ' . implode(', ', $set) . ' WHERE id = ?', $bind);
            Database::insert('audit_logs', ['actor_user_id' => (int)Auth::id(), 'action' => 'admin.subject_updated', 'entity_type' => 'subject_classrooms', 'entity_id' => $id, 'ip_address' => client_ip()]);
            set_flash('success', 'Subject updated.');
        } catch (Throwable $e) { set_flash('error', 'Update failed: ' . $e->getMessage()); }
        redirect('/admin/class-offerings');
    }

    public function assign(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');
        $id = (int)($params['id'] ?? 0);
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $sc = Database::one("SELECT * FROM subject_classrooms WHERE id = ? LIMIT 1", [$id]);
        if (!$sc) { set_flash('error', 'Subject not found.'); redirect('/admin/class-offerings'); }
        $t = Database::one("SELECT t.id FROM teachers t JOIN users u ON u.id = t.user_id WHERE t.id = ? AND u.status = 'active' LIMIT 1", [$teacherId]);
        if (!$t) { set_flash('error', 'Select a registered, active teacher.'); redirect('/admin/class-offerings'); }
        Database::run("UPDATE subject_classrooms SET teacher_id = ? WHERE id = ?", [$teacherId, $id]);
        Database::insert('audit_logs', ['actor_user_id' => (int)Auth::id(), 'action' => 'admin.subject_teacher_assigned', 'entity_type' => 'subject_classrooms', 'entity_id' => $id, 'ip_address' => client_ip()]);
        $tu = Database::one("SELECT user_id FROM teachers WHERE id = ? LIMIT 1", [$teacherId]);
        if ($tu) {
            try {
                Database::insert('notifications', ['user_id' => (int)$tu['user_id'], 'title' => 'New class assigned: ' . $sc['subject_code'], 'message' => 'Admin assigned you to ' . $sc['subject_code'] . ' — ' . $sc['subject_title'] . '. It is now in your dashboard.', 'type' => 'info', 'link_url' => url('/dashboard')]);
            } catch (Throwable $e) {}
        }
        set_flash('success', 'Teacher assigned. Subject is now in their dashboard and visible to eligible students.');
        redirect('/admin/class-offerings');
    }

    public function removeTeacher(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');
        $id = (int)($params['id'] ?? 0);
        $sc = Database::one("SELECT subject_code FROM subject_classrooms WHERE id = ? LIMIT 1", [$id]);
        if (!$sc) { set_flash('error', 'Subject not found.'); redirect('/admin/class-offerings'); }
        Database::run("UPDATE subject_classrooms SET teacher_id = NULL WHERE id = ?", [$id]);
        Database::insert('audit_logs', ['actor_user_id' => (int)Auth::id(), 'action' => 'admin.subject_teacher_unassigned', 'entity_type' => 'subject_classrooms', 'entity_id' => $id, 'ip_address' => client_ip()]);
        set_flash('success', 'Teacher unassigned (' . $sc['subject_code'] . ' is now a draft, hidden from students until reassigned).');
        redirect('/admin/class-offerings');
    }

    public function regenerateCode(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');
        $id = (int)($params['id'] ?? 0);
        $sc = Database::one("SELECT * FROM subject_classrooms WHERE id = ? LIMIT 1", [$id]);
        if (!$sc) { set_flash('error', 'Subject not found.'); redirect('/admin/class-offerings'); }
        $new = $this->generateCode(7);
        Database::run("UPDATE subject_classrooms SET invite_code = ? WHERE id = ?", [$new, $id]);
        Database::insert('audit_logs', ['actor_user_id' => (int)Auth::id(), 'action' => 'admin.subject_code_regenerated', 'entity_type' => 'subject_classrooms', 'entity_id' => $id, 'ip_address' => client_ip()]);
        set_flash('success', "New student invite code: {$new} (old code invalidated).");
        redirect('/admin/class-offerings');
    }

    public function setStatus(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');
        $id = (int)($params['id'] ?? 0);
        $to = trim((string)($_POST['status'] ?? ''));
        if (!in_array($to, ['open', 'closed', 'archived'], true)) { set_flash('error', 'Invalid status.'); redirect('/admin/class-offerings'); }
        $sc = Database::one("SELECT * FROM subject_classrooms WHERE id = ? LIMIT 1", [$id]);
        if (!$sc) { set_flash('error', 'Subject not found.'); redirect('/admin/class-offerings'); }
        if ($this->hasCol('status')) Database::run("UPDATE subject_classrooms SET status = ?, is_active = ? WHERE id = ?", [$to, $to === 'open' ? 1 : 0, $id]);
        else Database::run("UPDATE subject_classrooms SET is_active = ? WHERE id = ?", [$to === 'open' ? 1 : 0, $id]);
        Database::insert('audit_logs', ['actor_user_id' => (int)Auth::id(), 'action' => 'admin.subject_status_' . $to, 'entity_type' => 'subject_classrooms', 'entity_id' => $id, 'ip_address' => client_ip()]);
        set_flash('success', "Subject marked {$to}.");
        redirect('/admin/class-offerings');
    }

    public function destroy(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');
        $id = (int)($params['id'] ?? 0);
        $sc = Database::one("SELECT subject_code FROM subject_classrooms WHERE id = ? LIMIT 1", [$id]);
        if (!$sc) { set_flash('error', 'Subject not found.'); redirect('/admin/class-offerings'); }
        Database::run("DELETE FROM subject_classrooms WHERE id = ?", [$id]);
        Database::insert('audit_logs', ['actor_user_id' => (int)Auth::id(), 'action' => 'admin.subject_deleted', 'entity_type' => 'subject_classrooms', 'entity_id' => $id, 'ip_address' => client_ip()]);
        set_flash('success', 'Subject deleted (' . $sc['subject_code'] . '). Enrollments and tasks removed.');
        redirect('/admin/class-offerings');
    }

    public function show(array $params = []): void
    {
        Auth::requireRole('admin');
        $id = (int)($params['id'] ?? 0);
        $extra = '';
        if ($this->hasCol('semester')) $extra .= ', sc.semester';
        if ($this->hasCol('schedule')) $extra .= ', sc.schedule';
        if ($this->hasCol('room')) $extra .= ', sc.room';
        if ($this->hasCol('status')) $extra .= ', sc.status';
        if ($this->hasCol('subject_id')) $extra .= ', sc.subject_id';
        if ($this->hasCol('grade_level_id')) $extra .= ', sc.grade_level_id, gl.name AS level_name, gl.code AS level_code';
        if ($this->hasCol('program_id')) $extra .= ', sc.program_id, p.code AS program_code, p.name AS program_name';
        $lvlJoin = $this->hasCol('grade_level_id') ? 'LEFT JOIN grade_levels gl ON gl.id = sc.grade_level_id' : '';
        $progJoin = $this->hasCol('program_id') ? 'LEFT JOIN programs p ON p.id = sc.program_id' : '';
        $offering = Database::one(
            "SELECT sc.*, ay.school_year, CONCAT(COALESCE(tu.first_name,''),' ',COALESCE(tu.last_name,'')) AS teacher_name, tu.email AS teacher_email, t.employee_no{$extra}
               FROM subject_classrooms sc JOIN academic_years ay ON ay.id = sc.academic_year_id
               LEFT JOIN teachers t ON t.id = sc.teacher_id LEFT JOIN users tu ON tu.id = t.user_id
               {$lvlJoin} {$progJoin}
              WHERE sc.id = ? LIMIT 1",
            [$id]
        );
        if (!$offering) { http_response_code(404); exit('Subject not found.'); }
        $regs = Database::all(
            "SELECT cm.classroom_id, cm.student_id, cm.joined_at AS registered_at, s.student_no, CONCAT(u.first_name,' ',u.last_name) AS name, u.email
               FROM classroom_members cm JOIN students s ON s.id = cm.student_id
               JOIN users u ON u.id = s.user_id WHERE cm.classroom_id = ? ORDER BY cm.joined_at DESC",
            [$id]
        );
        $this->view('admin/class_offerings/show', ['title' => 'Subject Students', 'offering' => $offering, 'regs' => $regs]);
    }

    /* Legacy: class-offering registration removal (old class_offerings table) */
    public function removeRegistration(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');
        $regId = (int)($params['id'] ?? 0);
        $reg = Database::one('SELECT * FROM student_class_registrations WHERE id = ? LIMIT 1', [$regId]);
        if (!$reg) { set_flash('error', 'Registration not found.'); redirect('/admin/class-offerings'); }
        try {
            Database::run("UPDATE student_class_registrations SET status='cancelled', updated_at=NOW() WHERE id = ?", [$regId]);
            Database::insert('audit_logs', ['actor_user_id' => (int)Auth::id(), 'action' => 'admin.registration_removed', 'entity_type' => 'student_class_registrations', 'entity_id' => $regId, 'ip_address' => client_ip()]);
            set_flash('success', 'Registration cancelled; slot released.');
        } catch (Throwable $e) { set_flash('error', 'Could not cancel registration.'); }
        redirect('/admin/class-offerings');
    }

    public function removeMember(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');
        $cid = (int)($params['id'] ?? 0);
        $sid = (int)($_POST['student_id'] ?? 0);
        if ($cid > 0 && $sid > 0) Database::run("DELETE FROM classroom_members WHERE classroom_id = ? AND student_id = ?", [$cid, $sid]);
        set_flash('success', 'Student removed from subject.');
        redirect('/admin/class-offerings/' . $cid);
    }
}
