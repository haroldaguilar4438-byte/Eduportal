<?php
declare(strict_types=1);

namespace App\Controllers\Student;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use PDOException;
use Throwable;

/**
 * Class Offerings / Subject Registration (student self-service).
 *
 * Reuses existing tables: class_offerings, subjects, sections, grade_levels,
 * academic_years, teachers, users, programs, students, notifications,
 * audit_logs. New table: student_class_registrations (+ optional
 * class_offering_schedules for structured conflict detection).
 *
 * Capacity = max_students - COUNT(registrations WHERE status='registered').
 * Displayed availability is informational; backend re-validates in a
 * transaction with SELECT ... FOR UPDATE + UNIQUE(student,offering).
 */
final class ClassRegistrationController extends Controller
{
    private const PER_PAGE_OPTIONS = [10, 25, 50, 100];
    private const DAY_MAP = [
        'MON' => 'MON', 'MONDAY' => 'MON', 'M' => 'MON',
        'TUE' => 'TUE', 'TUESDAY' => 'TUE', 'T' => 'TUE',
        'WED' => 'WED', 'WEDNESDAY' => 'WED', 'W' => 'WED',
        'THU' => 'THU', 'THUR' => 'THU', 'THURSDAY' => 'THU', 'TH' => 'THU', 'R' => 'THU',
        'FRI' => 'FRI', 'FRIDAY' => 'FRI', 'F' => 'FRI',
        'SAT' => 'SAT', 'SATURDAY' => 'SAT', 'S' => 'SAT',
        'SUN' => 'SUN', 'SUNDAY' => 'SUN',
    ];

    /* ============================ helpers ============================ */

    /** Ownership check: is this student linked to the current parent? */
    private function ownsStudent(int $studentId): bool
    {
        try {
            if (!Auth::is('parent') || $studentId <= 0) return false;
            $g = Database::one('SELECT id FROM guardians WHERE user_id = ? LIMIT 1', [(int) Auth::id()]);
            if (!$g) return false;
            return (bool) Database::one(
                'SELECT 1 FROM guardian_students WHERE guardian_id = ? AND student_id = ? LIMIT 1',
                [(int) $g['id'], $studentId]
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    private function myStudent(): ?array
    {
        $s = Database::one('SELECT * FROM students WHERE user_id = ? LIMIT 1', [(int) Auth::id()]);
        if ($s) return $s;
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
                return Database::one(
                    'SELECT s.* FROM guardian_students gs JOIN students s ON s.id = gs.student_id WHERE gs.guardian_id = ? ORDER BY s.id LIMIT 1',
                    [(int) $g['id']]
                );
            }
        }
        return null;
    }

    /**
     * Parent-facing child name: linked users-row name wins, else the
     * students-row identity (parent-filed children without accounts).
     * Falls back to "Student #id" only when no name exists anywhere.
     */
    private static function childDisplayName(array $studentRow, int $id): string
    {
        try {
            $merged = $studentRow;
            if (!empty($studentRow['user_id'])) {
                $u = Database::one(
                    'SELECT first_name, middle_name, last_name, suffix FROM users WHERE id = ? LIMIT 1',
                    [(int) $studentRow['user_id']]
                );
                if ($u) {
                    foreach (['first_name', 'middle_name', 'last_name', 'suffix'] as $k) {
                        if (trim((string) ($u[$k] ?? '')) !== '') $merged[$k] = $u[$k];
                    }
                }
            }
            if (class_exists(\App\Core\GuardianLinks::class)) {
                $name = \App\Core\GuardianLinks::displayName($merged);
                if (!str_starts_with($name, 'Student #')) return $name;
            } else {
                $full = trim(trim((string) ($merged['first_name'] ?? '')) . ' ' . trim((string) ($merged['last_name'] ?? '')));
                if ($full !== '') return $full;
            }
        } catch (Throwable $e) {
        }
        return 'Student #' . $id;
    }

    private function hasSchedTable(): bool
    {
        try {
            return (bool) Database::value("SHOW TABLES LIKE 'class_offering_schedules'");
        } catch (Throwable $e) {
            return false;
        }
    }

    private function hasRegTable(): bool
    {
        try {
            return (bool) Database::value("SHOW TABLES LIKE 'student_class_registrations'");
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Structured slots for one offering: from table if present, else parse note. */
    private function offeringSlots(int $offeringId, ?string $scheduleNote): array
    {
        if ($this->hasSchedTable()) {
            try {
                $rows = Database::all(
                    'SELECT day_of_week AS day, TIME_FORMAT(start_time, "%H:%i") AS start, TIME_FORMAT(end_time, "%H:%i") AS end
                       FROM class_offering_schedules WHERE class_offering_id = ? ORDER BY FIELD(day_of_week,"MON","TUE","WED","THU","FRI","SAT","SUN"), start_time',
                    [$offeringId]
                );
                if ($rows !== []) {
                    return array_map(static fn($r) => ['day' => $r['day'], 'start' => $r['start'], 'end' => $r['end']], $rows);
                }
            } catch (Throwable $e) {
            }
        }
        return self::parseScheduleNote((string) ($scheduleNote ?? ''));
    }

    /**
     * Best-effort parser for legacy schedule_note text.
     * Handles: "MWF 08:00-09:30", "MON 09:00 AM - 11:00 AM", "TUE/THU 1:00PM-2:30PM",
     * "MWF 9:00 AM - 10:00 AM", multi-line notes. Returns [] when unparseable
     * (caller treats as no-conflict rather than blocking registration).
     */
    public static function parseScheduleNote(string $note): array
    {
        $note = strtoupper(trim($note));
        if ($note === '') return [];
        $slots = [];
        // Split on newlines/semicolons first (multi-meeting notes)
        $chunks = preg_split('/[\r\n;]+/', $note) ?: [$note];
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') continue;
            // Find day tokens at start: e.g. MWF, MON, MON/WED, TTH, TUESDAY
            if (!preg_match('/^([A-Z\/\-\s,]+?)\s+(\d.*)$/', $chunk, $m)) {
                // Try pattern like "MON 09:00-10:30" inside longer text
                if (!preg_match('/\b(MON(?:DAY)?|TUE(?:SDAY)?|WED(?:NESDAY)?|THU(?:RSDAY)?|FRI(?:DAY)?|SAT(?:URDAY)?|SUN(?:DAY)?|MWF|TTH|MW|TF)\b/i', $chunk, $dm, PREG_OFFSET_CAPTURE)) continue;
                $dayPart = $dm[0][0];
                $rest = trim(substr($chunk, $dm[0][1] + strlen($dayPart)));
            } else {
                $dayPart = trim($m[1]);
                $rest = trim($m[2]);
            }
            $days = self::expandDays($dayPart);
            if ($days === []) continue;
            // Find time range: "09:00 AM - 11:00 AM" | "09:00-10:30" | "1:00PM-2:30PM"
            if (!preg_match('/(\d{1,2})(?::(\d{2}))?\s*(AM|PM)?\s*[-–~TO]+\s*(\d{1,2})(?::(\d{2}))?\s*(AM|PM)?/i', $rest, $t)) continue;
            $s = self::to24((int) $t[1], (int) ($t[2] !== '' ? $t[2] : 0), strtoupper($t[3] ?? ''), strtoupper($t[6] ?? ''));
            $e = self::to24((int) $t[4], (int) ($t[5] !== '' ? $t[5] : 0), strtoupper($t[6] ?? ''), strtoupper($t[3] ?? ''));
            if ($s === null || $e === null || $e <= $s) continue;
            foreach ($days as $d) $slots[] = ['day' => $d, 'start' => $s, 'end' => $e];
        }
        return $slots;
    }

    private static function expandDays(string $part): array
    {
        $part = strtoupper(trim($part));
        $out = [];
        // Direct combos
        $combos = ['MWF' => ['MON', 'WED', 'FRI'], 'TTH' => ['TUE', 'THU'], 'TTHS' => ['TUE', 'THU'], 'MW' => ['MON', 'WED'], 'TF' => ['TUE', 'FRI'], 'MTH' => ['MON', 'THU']];
        if (isset($combos[$part])) return $combos[$part];
        // Split on /, comma, space, hyphen
        $tokens = preg_split('/[\/,\s\-]+/', $part, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) $tokens = [$part];
        foreach ($tokens as $tok) {
            $tok = trim($tok);
            if ($tok === '') continue;
            // Handle compact like "MWF" without separators
            if (preg_match('/^[MTWRFS]+$/', $tok) && strlen($tok) > 1 && !isset($combos[$tok])) {
                foreach (str_split($tok) as $ch) {
                    $map = ['M' => 'MON', 'T' => 'TUE', 'W' => 'WED', 'R' => 'THU', 'F' => 'FRI', 'S' => 'SAT'];
                    if (isset($map[$ch]) && !in_array($map[$ch], $out, true)) $out[] = $map[$ch];
                }
                continue;
            }
            $key = $tok;
            if (isset(self::DAY_MAP[$key]) && !in_array(self::DAY_MAP[$key], $out, true)) $out[] = self::DAY_MAP[$key];
        }
        return $out;
    }

    private static function to24(int $h, int $min, string $mer1, string $mer2): ?string
    {
        $mer = $mer1 !== '' ? $mer1 : $mer2;
        if ($mer === 'PM' && $h < 12) $h += 12;
        if ($mer === 'AM' && $h === 12) $h = 0;
        if ($h < 0 || $h > 23 || $min < 0 || $min > 59) return null;
        return sprintf('%02d:%02d', $h, $min);
    }

    /** True when two slot sets overlap on same day with intersecting time. */
    public static function slotsConflict(array $a, array $b): bool
    {
        foreach ($a as $s1) {
            foreach ($b as $s2) {
                if (($s1['day'] ?? '') !== ($s2['day'] ?? '')) continue;
                $s1s = $s1['start'] ?? '';
                $s1e = $s1['end'] ?? '';
                $s2s = $s2['start'] ?? '';
                $s2e = $s2['end'] ?? '';
                // [start, end) overlap: s1s < s2e && s2s < s1e (touching edges = OK)
                if ($s1s < $s2e && $s2s < $s1e) return true;
            }
        }
        return false;
    }

    private function notify(int $userId, string $title, string $message, string $type, string $link): void
    {
        try {
            Database::insert('notifications', [
                'user_id' => $userId, 'title' => $title, 'message' => $message,
                'type' => $type, 'link_url' => $link,
            ]);
        } catch (Throwable $e) {
            storage_log('class-reg notify failed: ' . $e->getMessage());
        }
    }

    private function audit(string $action, string $entity, int $entityId, ?array $new = null, ?array $old = null): void
    {
        try {
            Database::insert('audit_logs', [
                'actor_user_id' => (int) Auth::id(), 'action' => $action,
                'entity_type' => $entity, 'entity_id' => $entityId,
                'old_values_json' => $old !== null ? json_encode($old) : null,
                'new_values_json' => $new !== null ? json_encode($new) : null,
                'ip_address' => client_ip(),
                'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (Throwable $e) {
            storage_log('class-reg audit failed: ' . $e->getMessage());
        }
    }

    /* ============================ BROWSE ============================ */

    public function index(array $params = []): void
    {
        Auth::requireRole('student', 'parent', 'admin', 'teacher');
        // Official source: admin-managed subject_classrooms (active/open only for students).
        $hasStatus = (bool) Database::value("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subject_classrooms' AND COLUMN_NAME = 'status'");
        $hasSem = (bool) Database::value("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subject_classrooms' AND COLUMN_NAME = 'semester'");
        $hasSched = (bool) Database::value("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subject_classrooms' AND COLUMN_NAME = 'schedule'");
        $hasRoom = (bool) Database::value("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subject_classrooms' AND COLUMN_NAME = 'room'");
        $hasUnits = (bool) Database::value("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subject_classrooms' AND COLUMN_NAME = 'units'");
        $hasLvl = (bool) Database::value("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subject_classrooms' AND COLUMN_NAME = 'grade_level_id'");
        $hasProg = (bool) Database::value("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subject_classrooms' AND COLUMN_NAME = 'program_id'");

        $isStudent = Auth::is('student');
        $isParent = Auth::is('parent');
        $student = ($isStudent || $isParent) ? $this->myStudent() : null;
        $myStudentId = $student ? (int) $student['id'] : 0;

        // Parent acting-child context: which owned linked child the parent is
        // managing right now (?child_id= ownership-checked in myStudent()).
        // Names resolve users-row first, then the students-row identity
        // (parent-filed children often have no users account), so the picker
        // shows real names — never "Student #N" when a name is on file.
        $linkedChildren = [];
        $actingChild = null;
        if ($isParent) {
            try {
                if (class_exists(\App\Core\GuardianLinks::class)) {
                    $linkedChildren = \App\Core\GuardianLinks::linkedChildren((int) Auth::id());
                } else {
                    $g = Database::one('SELECT id FROM guardians WHERE user_id = ? LIMIT 1', [(int) Auth::id()]);
                    if ($g) {
                        $linkedChildren = Database::all(
                            'SELECT s.id, s.student_no, s.status, u.first_name, u.last_name, gl.name AS level_name
                               FROM guardian_students gs JOIN students s ON s.id = gs.student_id
                               LEFT JOIN users u ON u.id = s.user_id
                               LEFT JOIN grade_levels gl ON gl.id = s.grade_level_id
                              WHERE gs.guardian_id = ? ORDER BY s.id LIMIT 20',
                            [(int) $g['id']]
                        );
                    }
                }
            } catch (Throwable $e) {
            }
            if ($student) {
                $actingChild = ['id' => $myStudentId, 'name' => self::childDisplayName($student, $myStudentId)];
            }
        }

        // Enrollment-aware academic context (source of truth for students).
        $ctx = null;
        if ($myStudentId > 0 && class_exists(\App\Core\CollegeEligibility::class)) {
            $ctx = \App\Core\CollegeEligibility::currentContext($myStudentId);
        }

        $q = static function (string $k, string $def = ''): string {
            return trim((string) ($_GET[$k] ?? $def));
        };
        $search = $q('search');
        $avail = $q('availability', 'available');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = (int) ($_GET['per_page'] ?? 25);
        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true)) $perPage = 25;
        if (!in_array($avail, ['', 'available', 'full'], true)) $avail = 'available';

        $ayId = (int) ($_GET['ay_id'] ?? 0);
        $semester = $q('semester');
        if (!in_array($semester, ['', '1st', '2nd', 'Summer'], true)) $semester = '';
        // Browse mode: students may VIEW all offerings read-only (other
        // programs/levels included). Joining stays enrollment-locked — the
        // invite-code backend re-validates every join, so browsing can never
        // grant access. Staff are unaffected.
        $viewMode = $q('view', 'eligible');
        if (!in_array($viewMode, ['eligible', 'all'], true)) $viewMode = 'eligible';
        $browseAll = ($isStudent || $isParent) && $viewMode === 'all';

        // Browse-mode period filter covers every calendar: semesters plus the
        // configured quarters/terms (which live in period_code, not semester).
        $browsePeriodOpts = ['1st', '2nd', 'Summer'];
        try {
            if (class_exists(\App\Core\AcademicPeriod::class)) {
                foreach (\App\Core\AcademicPeriod::periodCodes(\App\Core\AcademicPeriod::BAND_BASIC) as $c) {
                    if (!in_array($c, $browsePeriodOpts, true)) $browsePeriodOpts[] = $c;
                }
            }
        } catch (Throwable $e) {
        }
        $periodF = $q('period');
        if (!in_array($periodF, array_merge([''], $browsePeriodOpts), true)) $periodF = '';

        $locked = false; // students: academic period comes from enrollment, never the URL
        $blockReason = '';
        $ctxBand = ($ctx !== null) ? (string) ($ctx['band'] ?? \App\Core\AcademicPeriod::BAND_COLLEGE) : \App\Core\AcademicPeriod::BAND_COLLEGE;
        $ctxPeriod = ($ctx !== null) ? (string) ($ctx['period'] ?? '') : '';
        if (($isStudent || $isParent) && $ctx !== null) {
            $locked = true;
            if (!$ctx['has_active_enrollment']) {
                $blockReason = 'You must have an active/approved enrollment before joining subjects.';
            } elseif ($ctx['ay_id'] <= 0 || $ctxPeriod === '' || $ctx['level_id'] <= 0) {
                $blockReason = 'Your current enrollment period could not be determined — please contact the Registrar.';
            } elseif (!$browseAll) {
                $ayId = (int) $ctx['ay_id'];
                $semester = $ctxBand === \App\Core\AcademicPeriod::BAND_BASIC ? '' : (string) $ctx['semester'];
            }
        }

        $where = ['sc.is_active = 1'];
        $bind = [];
        if ($hasStatus && !Auth::is('admin')) $where[] = "sc.status = 'open'";
        // Draft subjects without a teacher are never shown to students/parents,
        // even though the joins below are lenient (admin/staff still see them).
        if ($isStudent || $isParent) $where[] = 'sc.teacher_id IS NOT NULL';
        $hasScPeriod = \App\Core\CollegeEligibility::hasScPeriodCol();
        if ($browseAll) {
            // The Academic Period filter is the only period control in browse
            // mode. A stale/bookmarked semester= param must never silently
            // hide quarter/term subjects (their semester column is NULL).
            $semester = '';
        }
        if (($isStudent || $isParent) && $locked && $blockReason === '' && !$browseAll) {
            // Enrollment-locked eligibility: AY + band period + year level + program.
            // Basic-ed matches period_code (quarters/terms); SHS/College match
            // semester (legacy). Untarged offerings stay hidden.
            $where[] = 'sc.academic_year_id = ?';
            $bind[] = $ayId;
            if ($ctxBand === \App\Core\AcademicPeriod::BAND_BASIC) {
                if ($hasScPeriod) {
                    $where[] = 'sc.period_code = ?';
                    $bind[] = $ctxPeriod;
                } else {
                    $where[] = '1 = 0';
                }
            } elseif ($hasSem) {
                $where[] = 'sc.semester = ?';
                $bind[] = $semester;
            }
            if ($hasLvl && (int) $ctx['level_id'] > 0) {
                // Offerings without a year level are hidden from students (admin must set it);
                // section names are never used for eligibility.
                $where[] = 'sc.grade_level_id = ?';
                $bind[] = (int) $ctx['level_id'];
            }
            if ($hasProg && !empty($ctx['program_id'])) {
                $where[] = '(sc.program_id IS NULL OR sc.program_id = ?)';
                $bind[] = (int) $ctx['program_id'];
            }
        } else {
            if ($ayId > 0) {
                $where[] = 'sc.academic_year_id = ?';
                $bind[] = $ayId;
            } else {
                $cur = Database::one('SELECT id FROM academic_years WHERE is_current = 1 LIMIT 1');
                if ($cur) {
                    $where[] = 'sc.academic_year_id = ?';
                    $bind[] = (int) $cur['id'];
                    $ayId = (int) $cur['id'];
                }
            }
            if ($browseAll && $periodF !== '' && $hasScPeriod) {
                // Effective-period match so quarters/terms are browsable too.
                $where[] = 'COALESCE(sc.period_code, sc.semester) = ?';
                $bind[] = $periodF;
            } elseif ($semester !== '' && $hasSem) {
                $where[] = 'sc.semester = ?';
                $bind[] = $semester;
            }
        }
        if ($search !== '') {
            $where[] = '(sc.subject_code LIKE ? OR sc.subject_title LIKE ? OR sc.section_name LIKE ? OR tu.first_name LIKE ? OR tu.last_name LIKE ? OR sc.invite_code LIKE ?)';
            $like = '%' . $search . '%';
            $bind = array_merge($bind, [$like, $like, $like, $like, $like, $like]);
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $slotCond = $avail === 'full' ? 'available_slots <= 0' : 'available_slots > 0';
        $lvlJoin = $hasLvl ? 'LEFT JOIN grade_levels gl ON gl.id = sc.grade_level_id' : '';
        $progJoin = $hasProg ? 'LEFT JOIN programs p ON p.id = sc.program_id' : '';

        $schedSel = $hasSched ? 'sc.schedule AS schedule_note,' : 'NULL AS schedule_note,';
        $roomSel = $hasRoom ? 'sc.room,' : 'NULL AS room,';
        $semSel = $hasSem ? 'sc.semester,' : 'NULL AS semester,';
        $periodSel = $hasScPeriod ? 'sc.period_code,' : 'NULL AS period_code,';
        $statusSel = $hasStatus ? 'sc.status AS co_status,' : "'open' AS co_status,";
        $unitsSel = $hasUnits ? 'COALESCE(sc.units, 3.0) AS units,' : '3.0 AS units,';
        $lvlSel = $hasLvl ? 'gl.name AS level_name, gl.code AS level_code,' : "' ' AS level_name, '' AS level_code,";
        $progSel = $hasProg ? 'p.code AS program_code, p.name AS program_name,' : 'NULL AS program_code, NULL AS program_name,';

        $rows = [];
        $total = 0;
        $totalPages = 0;
        if ($blockReason === '') {
            $countSql = "SELECT COUNT(*) FROM (SELECT sc.id, (sc.max_students - COUNT(cm.id)) AS available_slots
                       FROM subject_classrooms sc JOIN academic_years ay ON ay.id = sc.academic_year_id
                       LEFT JOIN teachers t ON t.id = sc.teacher_id LEFT JOIN users tu ON tu.id = t.user_id
                       {$lvlJoin} {$progJoin}
                       LEFT JOIN classroom_members cm ON cm.classroom_id = sc.id
                       {$whereSql} GROUP BY sc.id) x WHERE {$slotCond}";
            try {
                $total = (int) Database::value($countSql, $bind);
            } catch (Throwable $e) {
                storage_log('offerings count failed: ' . $e->getMessage());
                $total = 0;
            }
            $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;
            if ($totalPages > 0 && $page > $totalPages) $page = $totalPages;
            $offset = ($page - 1) * $perPage;

            $dataSql = "SELECT sc.id, {$roomSel} {$schedSel} sc.max_students, {$semSel} {$periodSel} {$statusSel}
                           sc.subject_code, sc.subject_title, sc.description AS subject_desc, {$unitsSel}
                           {$lvlSel} {$progSel}
                           sc.section_name, ay.school_year,
                           t.department, '' AS position,
                           TRIM(CONCAT(COALESCE(tu.first_name,''),' ',COALESCE(tu.last_name,''))) AS instructor,
                           COUNT(cm.id) AS enrolled_count,
                           (sc.max_students - COUNT(cm.id)) AS available_slots,
                           MAX(CASE WHEN mine.id IS NOT NULL THEN 'registered' ELSE NULL END) AS my_status
                      FROM subject_classrooms sc
                      JOIN academic_years ay ON ay.id = sc.academic_year_id
                      LEFT JOIN teachers t ON t.id = sc.teacher_id
                      LEFT JOIN users tu ON tu.id = t.user_id
                      {$lvlJoin} {$progJoin}
                      LEFT JOIN classroom_members cm ON cm.classroom_id = sc.id
                       LEFT JOIN classroom_members mine ON mine.classroom_id = sc.id AND mine.student_id = ?
                       {$whereSql} GROUP BY sc.id HAVING (sc.max_students - COUNT(cm.id)) " . ($avail === 'full' ? '<= 0' : '> 0') . "
                       ORDER BY sc.subject_code ASC LIMIT {$perPage} OFFSET {$offset}";
            try {
                $rows = Database::all($dataSql, array_merge([$myStudentId], $bind));
            } catch (Throwable $e) {
                storage_log('offerings list failed: ' . $e->getMessage());
                $rows = [];
            }
            foreach ($rows as &$r) {
                $r['available_slots'] = max(0, (int) $r['max_students'] - (int) $r['enrolled_count']);
                // Per-row join state for eligible/enrolled/full/blocked display.
                if (($isStudent || $isParent) && $myStudentId > 0) {
                    try {
                        $st = \App\Core\CollegeEligibility::displayState($myStudentId, (int) $r['id']);
                        $r['elig_state'] = $st['state'] ?? '';
                        $r['elig_label'] = $st['label'] ?? '';
                        $r['elig_reason'] = $st['reason'] ?? '';
                    } catch (Throwable $e) {
                    }
                }
            }
            unset($r);
        }

        $academicYears = Database::all('SELECT id, school_year, is_current FROM academic_years ORDER BY school_year DESC LIMIT 20');
        $this->view('student/class_offerings/index', [
            'title' => 'Class Offerings',
            'rows' => $rows, 'total' => $total, 'page' => $page,
            'perPage' => $perPage, 'perPageOptions' => self::PER_PAGE_OPTIONS,
            'totalPages' => $totalPages,
            'filters' => ['ay_id' => $ayId, 'semester' => $semester, 'program_id' => 0, 'department' => '', 'grade_level_id' => 0, 'subject_id' => 0, 'search' => $search, 'availability' => $avail, 'view' => $viewMode, 'period' => $periodF, 'child_id' => $actingChild ? (int) $actingChild['id'] : ''],
            'browsePeriods' => $browsePeriodOpts,
            'academicYears' => $academicYears, 'programs' => [],
            'gradeLevels' => [], 'subjects' => [],
            'semesters' => ['1st', '2nd', 'Summer'], 'departments' => [],
            'needsMigration' => false, 'isStudent' => $isStudent,
            'acadCtx' => $ctx, 'locked' => $locked, 'blockReason' => $blockReason,
            'browseAll' => $browseAll,
            'isParent' => $isParent, 'linkedChildren' => $linkedChildren, 'actingChild' => $actingChild,
        ]);
    }

    public function show(array $params = []): void
    {
        Auth::requireRole('student', 'parent', 'admin', 'teacher');
        $id = (int) ($params['id'] ?? 0);
        $student = (Auth::is('student') || Auth::is('parent')) ? $this->myStudent() : null;
        $myId = $student ? (int) $student['id'] : 0;
        $hasLvl = (bool) Database::value("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subject_classrooms' AND COLUMN_NAME = 'grade_level_id'");
        $hasProg = (bool) Database::value("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subject_classrooms' AND COLUMN_NAME = 'program_id'");
        $lvlJoin = $hasLvl ? 'LEFT JOIN grade_levels gl ON gl.id = sc.grade_level_id' : '';
        $progJoin = $hasProg ? 'LEFT JOIN programs p ON p.id = sc.program_id' : '';
        $lvlSel = $hasLvl ? 'gl.name AS level_name, gl.code AS level_code,' : "' ' AS level_name, '' AS level_code,";
        $progSel = $hasProg ? 'p.code AS program_code, p.name AS program_name,' : 'NULL AS program_code, NULL AS program_name,';
        $hasScPeriod = \App\Core\CollegeEligibility::hasScPeriodCol();
        $perSel = $hasScPeriod ? 'sc.period_code,' : 'NULL AS period_code,';
        $row = Database::one(
            "SELECT sc.id, sc.subject_code, sc.subject_title, sc.description AS subject_desc, COALESCE(sc.units, 3.0) AS units,
                    sc.section_name, {$lvlSel} {$progSel} ay.school_year, ay.id AS academic_year_id, sc.semester, {$perSel}
                    sc.schedule AS schedule_note, sc.room, sc.max_students, sc.status AS status, sc.status AS co_status,
                    t.department, TRIM(CONCAT(COALESCE(tu.first_name,''),' ',COALESCE(tu.last_name,''))) AS instructor,
                    (SELECT COUNT(*) FROM classroom_members cm WHERE cm.classroom_id = sc.id) AS enrolled_count,
                    (SELECT 'registered' FROM classroom_members WHERE classroom_id = sc.id AND student_id = ? LIMIT 1) AS my_status
               FROM subject_classrooms sc
               JOIN academic_years ay ON ay.id = sc.academic_year_id
               LEFT JOIN teachers t ON t.id = sc.teacher_id
               LEFT JOIN users tu ON tu.id = t.user_id
               {$lvlJoin} {$progJoin}
              WHERE sc.id = ? AND sc.is_active = 1 LIMIT 1",
            [$myId, $id]
        );
        if (!$row) {
            http_response_code(404);
            exit('Class offering not found or not available.');
        }
        // Students may preview any offering; joining still requires the invite code (+ enrollment eligibility).
        $row['available_slots'] = max(0, (int) $row['max_students'] - (int) $row['enrolled_count']);
        $slots = $this->offeringSlots((int) $row['id'], $row['schedule_note'] ?? null);
        $ctx = null;
        $elig = null;
        if ($myId > 0 && class_exists(\App\Core\CollegeEligibility::class)) {
            $ctx = \App\Core\CollegeEligibility::currentContext($myId);
            $elig = \App\Core\CollegeEligibility::displayState($myId, (int) $row['id']);
        }
        $actingChildShow = null;
        if (Auth::is('parent') && $student) {
            $actingChildShow = ['id' => $myId, 'name' => self::childDisplayName($student, $myId)];
        }
        $this->view('student/class_offerings/show', [
            'title' => $row['subject_code'] . ' — Details',
            'offering' => $row, 'slots' => $slots,
            'isStudent' => Auth::is('student'),
            'acadCtx' => $ctx, 'elig' => $elig,
            'isParent' => Auth::is('parent'), 'actingChild' => $actingChildShow,
        ]);
    }

    /* ============================ REGISTER ============================ */

    public function register(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('student');
        $offeringId = (int) ($params['id'] ?? ($_POST['class_offering_id'] ?? 0));
        if ($offeringId <= 0) {
            $this->fail('Invalid class selected.');
        }
        // Official subjects join via student invite code only.
        $official = Database::one("SELECT id, subject_code FROM subject_classrooms WHERE id = ? LIMIT 1", [$offeringId]);
        if ($official) {
            set_flash('info', 'This is an official subject (' . $official['subject_code'] . '). Enter its student invite code on your Dashboard → Validate & preview → Confirm join.');
            redirect('/dashboard');
        }
        $student = $this->myStudent();
        if ($student === null) {
            $this->fail('Student record not found. Complete enrollment first.', '/my-enrollments');
        }
        if (($student['status'] ?? '') !== 'enrolled') {
            $this->fail('You must be fully enrolled before registering for classes. Check My Applications.', '/my-enrollments');
        }
        $studentId = (int) $student['id'];
        $userId = (int) Auth::id();
        $wantsJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest' || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

        try {
            $result = Database::transaction(static function () use ($offeringId, $studentId, $userId) {
                $pdo = Database::pdo();
                // 1. Lock offering row
                $st = $pdo->prepare('SELECT * FROM class_offerings WHERE id = ? FOR UPDATE');
                $st->execute([$offeringId]);
                $co = $st->fetch(\PDO::FETCH_ASSOC);
                if (!$co) throw new \RuntimeException('NOT_FOUND');
                if (($co['status'] ?? '') !== 'open') throw new \RuntimeException('NOT_OPEN:' . ($co['status'] ?? ''));
                // 2. Duplicate check
                $st = $pdo->prepare("SELECT id, status FROM student_class_registrations WHERE student_id = ? AND class_offering_id = ? LIMIT 1");
                $st->execute([$studentId, $offeringId]);
                $dup = $st->fetch(\PDO::FETCH_ASSOC);
                if ($dup && in_array($dup['status'], ['registered', 'completed'], true)) throw new \RuntimeException('DUPLICATE');
                // 3. Capacity check (active only)
                $st = $pdo->prepare("SELECT COUNT(*) FROM student_class_registrations WHERE class_offering_id = ? AND status = 'registered'");
                $st->execute([$offeringId]);
                $enrolled = (int) $st->fetchColumn();
                if ($enrolled >= (int) $co['max_students']) throw new \RuntimeException('FULL');
                return ['offering' => $co, 'was_dropped' => ($dup && in_array($dup['status'], ['dropped', 'cancelled'], true)) ? (int) $dup['id'] : 0];
            });
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if ($msg === 'NOT_FOUND') $this->fail('This class is no longer available.');
            if (str_starts_with($msg, 'NOT_OPEN')) $this->fail('This class is no longer available for registration.');
            if ($msg === 'DUPLICATE') $this->fail('You are already registered for this class.');
            if ($msg === 'FULL') $this->fail('Class is already full.');
            if ($e instanceof PDOException && ($e->errorInfo[1] ?? 0) === 1062) $this->fail('You are already registered for this class.');
            storage_log('class register tx failed: ' . $e->getMessage());
            $this->fail('The registration could not be completed. Please try again.');
        }

        // 4. Schedule conflict check (outside lock, before final insert; re-checked data is fresh)
        try {
            $target = Database::one('SELECT schedule_note FROM class_offerings WHERE id = ? LIMIT 1', [$offeringId]);
            $targetSlots = $this->offeringSlots($offeringId, $target['schedule_note'] ?? null);
            if ($targetSlots !== []) {
                $mine = Database::all(
                    "SELECT co.id, co.schedule_note FROM student_class_registrations scr
                      JOIN class_offerings co ON co.id = scr.class_offering_id
                     WHERE scr.student_id = ? AND scr.status = 'registered' AND scr.class_offering_id <> ?",
                    [$studentId, $offeringId]
                );
                foreach ($mine as $m) {
                    $ms = $this->offeringSlots((int) $m['id'], $m['schedule_note'] ?? null);
                    if ($ms !== [] && self::slotsConflict($ms, $targetSlots)) {
                        $this->fail('You have a schedule conflict with another registered class.');
                    }
                }
            }
        } catch (Throwable $e) {
            if ($e instanceof \RuntimeException && str_starts_with($e->getMessage(), 'CONFLICT')) {
                $this->fail('You have a schedule conflict with another registered class.');
            }
            // If our own fail() threw, it already redirected; otherwise continue.
            if (!($e instanceof \RuntimeException)) storage_log('conflict check failed: ' . $e->getMessage());
            else throw $e;
        }

        // 5. Final insert in a second short transaction (capacity re-checked)
        try {
            $regId = Database::transaction(static function () use ($offeringId, $studentId) {
                $pdo = Database::pdo();
                $st = $pdo->prepare('SELECT * FROM class_offerings WHERE id = ? FOR UPDATE');
                $st->execute([$offeringId]);
                $co = $st->fetch(\PDO::FETCH_ASSOC);
                if (!$co || ($co['status'] ?? '') !== 'open') throw new \RuntimeException('NOT_OPEN');
                $st = $pdo->prepare("SELECT COUNT(*) FROM student_class_registrations WHERE class_offering_id = ? AND status = 'registered'");
                $st->execute([$offeringId]);
                if ((int) $st->fetchColumn() >= (int) $co['max_students']) throw new \RuntimeException('FULL');
                // Reactivate dropped/cancelled row if present (preserves history id)
                $st = $pdo->prepare('SELECT id, status FROM student_class_registrations WHERE student_id = ? AND class_offering_id = ? LIMIT 1');
                $st->execute([$studentId, $offeringId]);
                $ex = $st->fetch(\PDO::FETCH_ASSOC);
                if ($ex && in_array($ex['status'], ['dropped', 'cancelled'], true)) {
                    $st = $pdo->prepare("UPDATE student_class_registrations SET status='registered', registered_at=NOW(), dropped_at=NULL, updated_at=NOW() WHERE id = ?");
                    $st->execute([(int) $ex['id']]);
                    return (int) $ex['id'];
                }
                $st = $pdo->prepare("INSERT INTO student_class_registrations (student_id, class_offering_id, status) VALUES (?, ?, 'registered')");
                $st->execute([$studentId, $offeringId]);
                return (int) $pdo->lastInsertId();
            });
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if ($msg === 'FULL') $this->fail('Class is already full.');
            if (str_starts_with($msg, 'NOT_OPEN')) $this->fail('This class is no longer available for registration.');
            if ($e instanceof PDOException && ($e->errorInfo[1] ?? 0) === 1062) $this->fail('You are already registered for this class.');
            storage_log('class register insert failed: ' . $e->getMessage());
            $this->fail('The registration could not be completed. Please try again.');
        }

        $info = Database::one(
            'SELECT sub.code, sub.title FROM class_offerings co JOIN subjects sub ON sub.id = co.subject_id WHERE co.id = ? LIMIT 1',
            [$offeringId]
        );
        $label = $info ? ($info['code'] . ' - ' . $info['title']) : ('Class #' . $offeringId);
        $this->notify($userId, 'Class Registration Successful', 'You successfully registered for ' . $label . '.', 'success', url('/student/my-classes'));
        $this->audit('student.class_registered', 'student_class_registrations', $regId, ['student_id' => $studentId, 'class_offering_id' => $offeringId, 'status' => 'registered']);

        if ($wantsJson) {
            $left = Database::one(
                'SELECT max_students, (SELECT COUNT(*) FROM student_class_registrations WHERE class_offering_id = ? AND status = ?) AS enrolled FROM class_offerings WHERE id = ? LIMIT 1',
                [$offeringId, 'registered', $offeringId]
            );
            $avail = $left ? max(0, (int) $left['max_students'] - (int) $left['enrolled']) : 0;
            json_response(['success' => true, 'message' => 'Registration successful.', 'data' => ['registration_id' => $regId, 'class_offering_id' => $offeringId, 'available_slots' => $avail]]);
        }
        set_flash('success', 'Registered for ' . $label . ' successfully.');
        redirect('/student/my-classes');
    }

    /* ============================ MY CLASSES + DROP ============================ */

    public function myClasses(array $params = []): void
    {
        Auth::requireRole('student', 'parent');
        $student = $this->myStudent();
        if ($student === null) {
            set_flash('error', 'Student record not found.');
            redirect('/dashboard');
        }
        $hasUnits = false;
        try {
            $hasUnits = (bool) Database::value("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subject_classrooms' AND COLUMN_NAME = 'units'");
        } catch (Throwable $e) {
        }
        $unitsSel = $hasUnits ? 'COALESCE(sc.units, 3.0) AS units,' : '3.0 AS units,';
        $hasScPeriod = \App\Core\CollegeEligibility::hasScPeriodCol();
        $perSel = $hasScPeriod ? 'sc.period_code,' : 'NULL AS period_code,';
        try {
        $rows = Database::all(
            "SELECT cm.id AS registration_id, 'registered' AS reg_status, cm.joined_at AS registered_at, NULL AS dropped_at,
                    sc.id AS offering_id, sc.room, sc.schedule AS schedule_note, sc.semester, {$perSel} 'open' AS co_status,
                    sc.subject_code, sc.subject_title, {$unitsSel}
                    sc.section_name, ay.school_year, ay.id AS academic_year_id,
                    TRIM(CONCAT(COALESCE(tu.first_name,''),' ',COALESCE(tu.last_name,''))) AS instructor
               FROM classroom_members cm
               JOIN subject_classrooms sc ON sc.id = cm.classroom_id
               JOIN academic_years ay ON ay.id = sc.academic_year_id
               JOIN teachers t ON t.id = sc.teacher_id
               LEFT JOIN users tu ON tu.id = t.user_id
              WHERE cm.student_id = ?
              ORDER BY cm.joined_at DESC",
            [(int) $student['id']]
        );
        } catch (Throwable $e) {
        $rows = Database::all(
            "SELECT cm.id AS registration_id, 'registered' AS reg_status, cm.joined_at AS registered_at, NULL AS dropped_at,
                    sc.id AS offering_id, NULL AS room, NULL AS schedule_note, NULL AS semester, 'open' AS co_status,
                    sc.subject_code, sc.subject_title, 3.0 AS units,
                    sc.section_name, ay.school_year,
                    TRIM(CONCAT(COALESCE(tu.first_name,''),' ',COALESCE(tu.last_name,''))) AS instructor
               FROM classroom_members cm
               JOIN subject_classrooms sc ON sc.id = cm.classroom_id
               JOIN academic_years ay ON ay.id = sc.academic_year_id
               JOIN teachers t ON t.id = sc.teacher_id
               LEFT JOIN users tu ON tu.id = t.user_id
              WHERE cm.student_id = ?
              ORDER BY cm.joined_at DESC",
            [(int) $student['id']]
        );
        }
        $totalUnits = 0.0;
        foreach ($rows as $r) {
            if (($r['reg_status'] ?? '') === 'registered') $totalUnits += (float) ($r['units'] ?? 0);
        }
        // Current vs past semester split (history preserved, never deleted).
        $ctx = null;
        if (class_exists(\App\Core\CollegeEligibility::class)) {
            $ctx = \App\Core\CollegeEligibility::currentContext((int) $student['id']);
        }
        $current = [];
        $past = [];
        foreach ($rows as $r) {
            $isCurrent = true;
            if ($ctx !== null && $ctx['has_active_enrollment']) {
                $sameAy = ((string) ($r['school_year'] ?? '') === (string) $ctx['ay_label']);
                $rowPeriod = trim((string) ($r['period_code'] ?? ''));
                if ($rowPeriod === '') $rowPeriod = trim((string) ($r['semester'] ?? ''));
                $samePeriod = ($rowPeriod === (string) ($ctx['period'] ?? ''));
                $isCurrent = $sameAy && $samePeriod;
            }
            if ($isCurrent) $current[] = $r;
            else $past[] = $r;
        }
        // Pending code-free join requests (cancelable by the requester).
        $pendingRequests = [];
        try {
            $hasJR = Database::value("SHOW TABLES LIKE 'class_join_requests'");
            if ($hasJR) {
                $pendingRequests = Database::all(
                    "SELECT jr.*, sc.subject_code, sc.subject_title, sc.section_name, ay.school_year
                       FROM class_join_requests jr
                       JOIN subject_classrooms sc ON sc.id = jr.classroom_id
                       JOIN academic_years ay ON ay.id = sc.academic_year_id
                      WHERE jr.student_id = ? AND jr.status = 'pending'
                      ORDER BY jr.requested_at DESC LIMIT 50",
                    [(int) $student['id']]
                );
            }
        } catch (Throwable $e) {
            $pendingRequests = [];
        }
        $this->view('student/my_classes', [
            'title' => 'My Classes', 'rows' => $rows,
            'totalUnits' => $totalUnits, 'student' => $student,
            'acadCtx' => $ctx, 'currentRows' => $current, 'pastRows' => $past,
            'isParent' => Auth::is('parent'), 'childId' => Auth::is('parent') ? (int) $student['id'] : 0,
            'pendingRequests' => $pendingRequests,
        ]);
    }

    public function drop(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('student', 'parent');
        $regId = (int) ($params['id'] ?? 0);
        if ($regId <= 0) $this->fail('Invalid registration.');
        $student = $this->myStudent();
        if ($student === null) $this->fail('Student record not found.');
        $studentId = (int) $student['id'];
        // Parents act only on OWNED linked children (myStudent() already
        // ownership-checks ?child_id=; verify the registration belongs there).
        if (Auth::is('parent')) {
            $ok = $this->ownsStudent($studentId);
            if (!$ok) $this->fail('Registration not found or not yours.', '/student/my-classes');
        }

        $mem = Database::one("SELECT cm.*, sc.subject_code FROM classroom_members cm JOIN subject_classrooms sc ON sc.id = cm.classroom_id WHERE cm.id = ? AND cm.student_id = ? LIMIT 1", [$regId, $studentId]);
        if (!$mem) $this->fail('Registration not found or not yours.', '/student/my-classes');
        Database::run("DELETE FROM classroom_members WHERE id = ?", [$regId]);
        $label = $mem['subject_code'] ?? ('Class #' . $regId);
        $viaParent = Auth::is('parent');
        $this->notify((int) Auth::id(), 'Class Dropped', ($viaParent ? 'Removed from ' : 'You left ') . $label . '. Slot released.', 'warning', url('/student/my-classes'));
        $this->audit('student.class_dropped', 'classroom_members', $regId, ['status' => 'dropped'], ['status' => 'registered']);
        set_flash('success', 'Dropped ' . $label . '. Slot released.');
        redirect('/student/my-classes');
    }

    /* ======================= TEACHER ROSTER (read-only) ======================= */

    public function teacherRoster(array $params = []): void
    {
        Auth::requireRole('teacher', 'admin');
        $offeringId = (int) ($params['id'] ?? 0);
        if (Auth::is('teacher')) {
            $t = Database::one('SELECT id FROM teachers WHERE user_id = ? LIMIT 1', [(int) Auth::id()]);
            $owns = $t ? Database::one('SELECT id FROM subject_classrooms WHERE id = ? AND teacher_id = ? LIMIT 1', [$offeringId, (int) $t['id']]) : null;
            if (!$owns) {
                http_response_code(403);
                exit('Not authorized for this class.');
            }
        }
        $offering = Database::one(
            'SELECT sc.*, sc.subject_code AS code, sc.subject_title AS title, sc.section_name FROM subject_classrooms sc WHERE sc.id = ? LIMIT 1',
            [$offeringId]
        );
        if (!$offering) {
            http_response_code(404);
            exit('Class not found.');
        }
        $students = Database::all(
            "SELECT cm.id AS registration_id, 'registered' AS status, cm.joined_at AS registered_at, s.student_no,
                    CONCAT(u.first_name,' ',u.last_name) AS student_name, u.email
               FROM classroom_members cm
               JOIN students s ON s.id = cm.student_id
               JOIN users u ON u.id = s.user_id
              WHERE cm.classroom_id = ?
              ORDER BY u.last_name, u.first_name",
            [$offeringId]
        );
        $this->view('teacher/class_roster', [
            'title' => 'Class Roster', 'offering' => $offering, 'students' => $students,
        ]);
    }

    /** @return never */
    private function fail(string $message, string $to = '/student/class-offerings'): void
    {
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest' || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            json_response(['success' => false, 'message' => $message], 422);
        }
        // Never expose SQL internals: $message is always a curated string above.
        set_flash('error', $message);
        redirect($to);
    }
}
