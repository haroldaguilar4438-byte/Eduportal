<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use Throwable;

/**
 * Teacher Term-Grade Sheets: per assigned subject × grading period.
 * Draft → Submitted → (Registrar) → Returned → resubmit.
 * Teachers can NEVER approve/finalize; finalized sheets are read-only.
 * Official grades live only in finalized sheets (see DocumentController).
 */
final class TeacherGradesController extends Controller
{
    private function myTeacherId(): int
    {
        $t = Database::one("SELECT id FROM teachers WHERE user_id = ? LIMIT 1", [(int) Auth::id()]);
        return $t ? (int) $t['id'] : 0;
    }

    private function periods(): array
    {
        return Database::all("SELECT * FROM grade_periods WHERE is_active = 1 ORDER BY sort_order ASC");
    }

    private static function log(int $sheetId, ?string $from, string $to, string $action, ?string $remarks): void
    {
        Database::insert('grade_sheet_logs', [
            'sheet_id' => $sheetId, 'from_status' => $from, 'to_status' => $to,
            'action' => $action, 'remarks' => $remarks, 'actor_user_id' => (int) Auth::id(),
        ]);
        Database::insert('audit_logs', [
            'actor_user_id' => (int) Auth::id(), 'action' => 'gradesheet.' . $action,
            'entity_type' => 'grade_sheets', 'entity_id' => $sheetId, 'ip_address' => client_ip(),
        ]);
    }

    /* ================= Assigned subjects + sheet matrix ================= */

    public function index(array $params = []): void
    {
        Auth::requireRole('teacher');
        $tid = $this->myTeacherId();
        if (!$tid) {
            set_flash('error', 'Teacher record not found.');
            redirect('/dashboard');
        }
        $classes = Database::all(
            "SELECT sc.*, ay.school_year,
                    (SELECT COUNT(*) FROM classroom_members cm WHERE cm.classroom_id = sc.id) AS member_count
               FROM subject_classrooms sc
               JOIN academic_years ay ON ay.id = sc.academic_year_id
              WHERE sc.teacher_id = ?
              ORDER BY ay.school_year DESC, sc.subject_code ASC LIMIT 60",
            [$tid]
        );
        $periods = $this->periods();
        $matrix = [];
        foreach ($classes as $c) {
                $row = [];
            foreach ($periods as $p) {
                $row[$p['code']] = Database::one(
                    "SELECT gs.id, gs.status, gs.updated_at,
                            (SELECT COUNT(*) FROM grade_sheet_rows r WHERE r.sheet_id = gs.id AND r.grade IS NULL AND COALESCE(r.remarks,'') <> 'INC') AS missing
                       FROM grade_sheets gs WHERE gs.classroom_id = ? AND gs.period_id = ? LIMIT 1",
                    [(int) $c['id'], (int) $p['id']]
                );
            }
            $matrix[(int) $c['id']] = $row;
        }
        $this->view('teacher/grades_index', [
            'title' => 'Grade Sheets', 'classes' => $classes, 'periods' => $periods, 'matrix' => $matrix,
        ]);
    }

    /* ================= Open (or auto-create draft) sheet ================= */

    public function sheet(array $params = []): void
    {
        Auth::requireRole('teacher');
        $tid = $this->myTeacherId();
        $classId = (int) ($params['classroom'] ?? 0);
        $periodCode = trim((string) ($params['period'] ?? ''));
        $class = Database::one(
            "SELECT sc.*, ay.school_year FROM subject_classrooms sc
              JOIN academic_years ay ON ay.id = sc.academic_year_id
             WHERE sc.id = ? AND sc.teacher_id = ? LIMIT 1",
            [$classId, $tid]
        );
        if ($class === null) {
            set_flash('error', 'Subject not found or not assigned to you.');
            redirect('/teacher/grades');
        }
        $period = Database::one("SELECT * FROM grade_periods WHERE code = ? AND is_active = 1 LIMIT 1", [$periodCode]);
        if ($period === null) {
            set_flash('error', 'Invalid grading period.');
            redirect('/teacher/grades');
        }
        try {
            $sheet = Database::transaction(function () use ($class, $classId, $period) {
                $s = Database::one("SELECT * FROM grade_sheets WHERE classroom_id = ? AND period_id = ? LIMIT 1", [$classId, (int) $period['id']]);
                if ($s === null) {
                    // Sheet carries the classroom's EFFECTIVE period (quarter/
                    // term for Basic-ed, semester for SHS/College) so grades
                    // never mix across periods.
                    $sheetPeriod = null;
                    if (class_exists(\App\Core\AcademicPeriod::class)) {
                        $sheetPeriod = \App\Core\AcademicPeriod::effectivePeriod($class);
                        if ($sheetPeriod === '') $sheetPeriod = null;
                    } else {
                        $sheetPeriod = $class['semester'] ?? null;
                    }
                    $sid = Database::insert('grade_sheets', [
                        'classroom_id' => $classId, 'academic_year_id' => $class['academic_year_id'],
                        'semester' => $sheetPeriod, 'period_id' => (int) $period['id'], 'status' => 'draft',
                    ]);
                    self::log($sid, null, 'draft', 'created', 'Sheet opened for ' . $period['code']);
                    $s = Database::one("SELECT * FROM grade_sheets WHERE id = ? LIMIT 1", [$sid]);
                }
                // Sync newly joined members as empty rows (never delete existing grades)
                $members = Database::all("SELECT student_id FROM classroom_members WHERE classroom_id = ?", [$classId]);
                foreach ($members as $m) {
                    try {
                        Database::insert('grade_sheet_rows', ['sheet_id' => (int) $s['id'], 'student_id' => (int) $m['student_id'], 'grade' => null]);
                    } catch (Throwable $e) {
                    }
                }
                return Database::one("SELECT * FROM grade_sheets WHERE id = ? LIMIT 1", [(int) $s['id']]);
            });
        } catch (Throwable $e) {
            storage_log('grade sheet open failed: ' . $e->getMessage());
            set_flash('error', 'Could not open grade sheet.');
            redirect('/teacher/grades');
        }
        $rows = Database::all(
            "SELECT r.*, s.student_no, CONCAT(u.first_name,' ',u.last_name) AS student_name
               FROM grade_sheet_rows r
               JOIN students s ON s.id = r.student_id
               JOIN users u ON u.id = s.user_id
              WHERE r.sheet_id = ? ORDER BY u.last_name ASC, u.first_name ASC LIMIT 300",
            [(int) $sheet['id']]
        );
        $logs = Database::all(
            "SELECT l.*, CONCAT(u.first_name,' ',u.last_name) AS actor FROM grade_sheet_logs l
              LEFT JOIN users u ON u.id = l.actor_user_id WHERE l.sheet_id = ? ORDER BY l.created_at DESC LIMIT 30",
            [(int) $sheet['id']]
        );
        $missing = 0;
        foreach ($rows as $r) {
            if ($r['grade'] === null && trim((string) ($r['remarks'] ?? '')) !== 'INC') $missing++;
        }
        $editable = in_array($sheet['status'], ['draft', 'returned'], true);
        $calc = class_exists(\App\Core\GradeEngine::class)
            ? \App\Core\GradeEngine::breakdown($classId, (int) $period['id'])
            : ['weights' => [], 'categories' => [], 'students' => [], 'untagged' => 0];
        $this->view('teacher/grade_sheet', [
            'title' => $class['subject_code'] . ' — ' . $period['code'],
            'class' => $class, 'period' => $period, 'sheet' => $sheet,
            'rows' => $rows, 'logs' => $logs, 'missing' => $missing, 'editable' => $editable,
            'calc' => $calc,
        ]);
    }

    /* ================= Save draft ================= */

    /* ================= Save draft (LOCKED — grades are engine-only) ================= */

    public function save(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('teacher');
        [$sheet] = $this->ownedSheetOrFail((int) ($params['id'] ?? 0), true);
        // Locked: teachers cannot type, edit, or clear final grades — not even
        // by direct POST. Grades come only from Apply computed grades (engine).
        // Corrections happen in the raw assessments, never here.
        self::log((int) $sheet['id'], $sheet['status'], $sheet['status'], 'saved', 'Manual grade entry blocked (engine-locked)');
        set_flash('info', 'Grades are locked to engine computation — click Apply computed grades, then Submit. Correct raw assessment scores instead.');
        redirect('/teacher/grades/' . (int) $sheet['classroom_id'] . '/' . urlencode($this->periodCode((int) $sheet['period_id'])));
    }

    /* ================= Apply engine-computed grades ================= */

    public function applyComputed(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('teacher');
        [$sheet] = $this->ownedSheetOrFail((int) ($params['id'] ?? 0), true);
        // Locked: always recompute everything from the engine. There are no
        // manual entries to preserve — any legacy typed grade is refreshed.
        try {
            $res = \App\Core\GradeEngine::pushToSheet((int) $sheet['id'], true);
        } catch (Throwable $e) {
            storage_log('applyComputed failed ' . $sheet['id'] . ': ' . $e->getMessage());
            set_flash('error', 'Auto-calculation is unavailable — ask the Registrar to import the weighted-grades update.');
            redirect('/teacher/grades/' . (int) $sheet['classroom_id'] . '/' . urlencode($this->periodCode((int) $sheet['period_id'])));
        }
        Database::run("UPDATE grade_sheets SET updated_at = NOW() WHERE id = ?", [(int) $sheet['id']]);
        // Stamp INC remarks for engine-incomplete students (missing major work);
        // clear stale auto-INC where the student has since become computable.
        $markedInc = 0;
        try {
            $calc = \App\Core\GradeEngine::breakdown((int) $sheet['classroom_id'], (int) $sheet['period_id']);
            $srows = Database::all("SELECT id, student_id, grade, remarks FROM grade_sheet_rows WHERE sheet_id = ?", [(int) $sheet['id']]);
            foreach ($srows as $sr) {
                $info = $calc['students'][(int) $sr['student_id']] ?? null;
                $rem = trim((string) ($sr['remarks'] ?? ''));
                if ($info !== null && !empty($info['inc'])) {
                    // Engine-incomplete: genuine state is grade NULL + INC.
                    // Any stored numeric grade (e.g. legacy typed) is cleared.
                    if ($sr['grade'] !== null || $rem !== 'INC') {
                        Database::run("UPDATE grade_sheet_rows SET grade = NULL, computed_grade = NULL, remarks = 'INC', source = 'auto' WHERE id = ?", [(int) $sr['id']]);
                        $markedInc++;
                    }
                    continue;
                }
                if ($sr['grade'] !== null) continue;
                if ($rem === 'INC') {
                    Database::run("UPDATE grade_sheet_rows SET remarks = NULL WHERE id = ?", [(int) $sr['id']]);
                }
            }
        } catch (Throwable $e) {
            storage_log('applyComputed INC stamp failed ' . $sheet['id'] . ': ' . $e->getMessage());
        }
        self::log((int) $sheet['id'], $sheet['status'], $sheet['status'], 'saved', 'Auto-applied ' . $res['applied'] . ' computed grade(s)' . ($markedInc > 0 ? ', ' . $markedInc . ' marked INC' : ''));
        set_flash(
            'success',
            'Computed grades applied: ' . $res['applied'] . ' filled'
            . ($res['skipped_missing'] > 0 ? ', ' . $res['skipped_missing'] . ' still missing data' : '')
            . (($res['skipped_incomplete'] ?? 0) > 0 ? ', ' . $res['skipped_incomplete'] . ' incomplete (missing major work — marked INC)' : '')
            . ($markedInc > 0 ? ', ' . $markedInc . ' stamped INC' : '') . '. Review, then Submit.'
        );
        redirect('/teacher/grades/' . (int) $sheet['classroom_id'] . '/' . urlencode($this->periodCode((int) $sheet['period_id'])));
    }

    /* ================= Submit / resubmit ================= */
    public function submit(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('teacher');
        [$sheet, $wasResubmit] = $this->ownedSheetOrFail((int) ($params['id'] ?? 0), true);
        // Blocking = rows with no grade that are NOT engine-incomplete.
        // INC rows (missing major work, remarks 'INC') are submittable — INC
        // flows to grade slips until summer completion. Minor missing work
        // already counts as 0 in the computed grade.
        // LOCKED: every stored grade must exactly match the engine computation
        // (fake/typed grades are rejected even by direct POST).
        $blocking = 0;
        $incMismatch = 0;
        $tampered = 0;
        try {
            $calc = class_exists(\App\Core\GradeEngine::class)
                ? \App\Core\GradeEngine::breakdown((int) $sheet['classroom_id'], (int) $sheet['period_id'])
                : ['weights' => [], 'students' => []];
            $srows = Database::all("SELECT student_id, grade, remarks, source FROM grade_sheet_rows WHERE sheet_id = ?", [(int) $sheet['id']]);
            foreach ($srows as $sr) {
                $info = $calc['students'][(int) $sr['student_id']] ?? null;
                $isInc = trim((string) ($sr['remarks'] ?? '')) === 'INC';
                if ($sr['grade'] === null) {
                    if ($info !== null && !empty($info['inc'])) continue;
                    if ($isInc) $incMismatch++;
                    $blocking++;
                    continue;
                }
                // Stored grade present: must equal the engine's computed point.
                $expected = is_array($info) ? ($info['point'] ?? null) : null;
                if ($expected === null) {
                    // Engine has no computable result for this student (not INC
                    // either) — a stored grade here cannot be genuine.
                    $tampered++;
                } elseif (abs((float) $sr['grade'] - (float) $expected) > 0.01) {
                    $tampered++;
                }
            }
        } catch (Throwable $e) {
            $blocking = (int) Database::value("SELECT COUNT(*) FROM grade_sheet_rows WHERE sheet_id = ? AND grade IS NULL", [(int) $sheet['id']]);
        }
        if ($blocking > 0) {
            $msg = 'Cannot submit — ' . $blocking . ' student(s) still missing grades and are not INC. Click Apply computed grades first (minor missing work counts as 0, major missing work marks INC).';
            if ($incMismatch > 0) $msg .= ' ' . $incMismatch . ' row(s) marked INC have all major work graded — click Apply computed grades to refresh them.';
            set_flash('error', $msg);
            redirect('/teacher/grades/' . (int) $sheet['classroom_id'] . '/' . urlencode($this->periodCode((int) $sheet['period_id'])));
        }
        if ($tampered > 0) {
            set_flash('error', 'Cannot submit — ' . $tampered . ' grade(s) do not match the engine computation. Click Apply computed grades to restore genuine values, then submit.');
            redirect('/teacher/grades/' . (int) $sheet['classroom_id'] . '/' . urlencode($this->periodCode((int) $sheet['period_id'])));
        }
        Database::run("UPDATE grade_sheets SET status = 'submitted', submitted_by = ?, submitted_at = NOW() WHERE id = ?", [(int) Auth::id(), (int) $sheet['id']]);
        self::log((int) $sheet['id'], $sheet['status'], 'submitted', $wasResubmit ? 'resubmitted' : 'submitted', $wasResubmit ? 'Corrections resubmitted to Registrar' : 'Submitted to Registrar');
        try {
            \App\Controllers\NotificationController::pushToRegistrars(
                'Grades submitted: ' . $this->classLabel((int) $sheet['classroom_id']) . ' — ' . $this->periodCode((int) $sheet['period_id']),
                'Teacher submitted term grades — review in Registrar Grade Sheets.',
                'info', '/registrar/sheets'
            );
        } catch (Throwable $e) {
        }
        set_flash('success', $wasResubmit ? 'Corrections resubmitted to the Registrar.' : 'Grades submitted to the Registrar.');
        redirect('/teacher/grades');
    }

    /* ================= internals ================= */

    /** @return array{0:array,1:bool} sheet + whether this is a resubmission */
    private function ownedSheetOrFail(int $sheetId, bool $mustBeEditable): array
    {
        $tid = $this->myTeacherId();
        $sheet = Database::one(
            "SELECT gs.* FROM grade_sheets gs JOIN subject_classrooms sc ON sc.id = gs.classroom_id
              WHERE gs.id = ? AND sc.teacher_id = ? LIMIT 1",
            [$sheetId, $tid]
        );
        if ($sheet === null) {
            set_flash('error', 'Grade sheet not found or not yours.');
            redirect('/teacher/grades');
        }
        $wasResubmit = $sheet['status'] === 'returned';
        if ($mustBeEditable && !in_array($sheet['status'], ['draft', 'returned'], true)) {
            set_flash('error', 'This sheet is ' . str_replace('_', ' ', $sheet['status']) . ' and locked. Only Draft or Returned sheets can be edited.');
            redirect('/teacher/grades');
        }
        return [$sheet, $wasResubmit];
    }

    private function periodCode(int $periodId): string
    {
        $p = Database::one("SELECT code FROM grade_periods WHERE id = ? LIMIT 1", [$periodId]);
        return $p ? (string) $p['code'] : 'Prelim';
    }

    private function classLabel(int $classroomId): string
    {
        $c = Database::one("SELECT subject_code FROM subject_classrooms WHERE id = ? LIMIT 1", [$classroomId]);
        return $c ? (string) $c['subject_code'] : ('#' . $classroomId);
    }
}
