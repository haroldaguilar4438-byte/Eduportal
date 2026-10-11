<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * College subject-joining eligibility (enrollment-aware, backend-authoritative).
 *
 * Source of truth: student's CURRENT APPROVED enrollment
 * (EnrollmentCycle::currentPeriod + latest approved enrollment_applications row),
 * NOT students.* profile fields alone (those are fallback only).
 *
 * Applies to College students (grade_levels.category = 'college', codes C1-C4
 * = 1st-4th Year). Other levels reuse the same AY/semester/level matching so
 * they never see college offerings, but the "college workflow" banner is
 * college-only.
 *
 * Offering academic targeting: subject_classrooms.subject_id,
 * subject_classrooms.grade_level_id, subject_classrooms.program_id
 * (see database/patch_college_subject_eligibility.sql). When those are NULL
 * we fall back to subjects.code = subject_classrooms.subject_code lookup.
 * Section names ("BSIT-1A") are NEVER authoritative — display only.
 */
final class CollegeEligibility
{
    /** College year codes in seed data (C1=1st Year ... C4=4th Year). */
    public const COLLEGE_CODES = ['C1', 'C2', 'C3', 'C4'];

    private static ?bool $hasOfferingAcadCols = null;

    /** Short label: "College - First Year" / C1 -> "1st Year". */
    public static function shortYear(string $code, string $name): string
    {
        $map = ['C1' => '1st Year', 'C2' => '2nd Year', 'C3' => '3rd Year', 'C4' => '4th Year'];
        $code = strtoupper(trim($code));
        if (isset($map[$code])) return $map[$code];
        $n = strtolower($name);
        if (str_contains($n, 'first')) return '1st Year';
        if (str_contains($n, 'second')) return '2nd Year';
        if (str_contains($n, 'third')) return '3rd Year';
        if (str_contains($n, 'fourth')) return '4th Year';
        return $name !== '' ? $name : '—';
    }

    public static function hasOfferingAcadCols(): bool
    {
        if (self::$hasOfferingAcadCols !== null) return self::$hasOfferingAcadCols;
        try {
            $n = (int) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = 'subject_classrooms' AND COLUMN_NAME IN ('subject_id','grade_level_id','program_id')"
            );
            self::$hasOfferingAcadCols = $n >= 3;
        } catch (Throwable $e) {
            self::$hasOfferingAcadCols = false;
        }
        return self::$hasOfferingAcadCols;
    }

    public static function resetColCache(): void
    {
        self::$hasOfferingAcadCols = null;
    }

    /**
     * Current academic context for a student, from ACTIVE enrollment.
     * Band-aware (basic/shs/college via AcademicPeriod); college keys keep
     * their exact legacy meaning. Added keys: band, period, period_label, noun.
     * @return array{student_id:int,level_id:int,level_code:string,level_name:string,short_year:string,category:string,band:string,period:string,period_label:string,noun:string,semester:string,ay_id:int,ay_label:string,program_id:int|null,program_code:string,program_name:string,is_college:bool,has_active_enrollment:bool,enrolled_status:string,source:string}|null
     */
    public static function currentContext(int $studentId): ?array
    {
        try {
            $stu = Database::one('SELECT * FROM students WHERE id = ? LIMIT 1', [$studentId]);
            if ($stu === null) return null;
            $enrolledStatus = (string) ($stu['status'] ?? '');

            $period = EnrollmentCycle::currentPeriod($studentId);
            $hasEaPeriod = self::hasEaPeriodCol();
            $periodSel = $hasEaPeriod ? 'ea.period_code,' : '';
            $approved = Database::one(
                "SELECT {$periodSel} program_id, grade_level_applied_id, semester, academic_year_id
                   FROM enrollment_applications ea
                  WHERE student_id = ? AND status = 'approved'
                  ORDER BY created_at DESC, id DESC LIMIT 1",
                [$studentId]
            );

            $levelId = $period ? (int) $period['level_id'] : (int) ($stu['grade_level_id'] ?? 0);
            if ($approved && !empty($approved['grade_level_applied_id'])) {
                // Enrollment row is authoritative for level when period came from profile fallback.
                if (!$period || ($period['source'] ?? '') !== 'enrollment') {
                    $levelId = (int) $approved['grade_level_applied_id'];
                }
            }
            $band = AcademicPeriod::bandForLevel($levelId);
            // Effective period: engine result wins; else the approved row's own
            // carriers (period_code for basic, semester for SHS/College).
            $effPeriod = $period ? (string) ($period['period'] ?? '') : '';
            if ($effPeriod === '' && $approved) {
                $effPeriod = AcademicPeriod::effectivePeriod($approved);
            }
            if ($effPeriod === '' && $band === AcademicPeriod::BAND_BASIC) {
                $effPeriod = AcademicPeriod::firstPeriod($band);
            }
            // Legacy 'semester' key: SHS/College keep exact meaning; basic stays
            // '' so no caller can display semesters for quarter/term students.
            $semester = $band === AcademicPeriod::BAND_BASIC ? '' : $effPeriod;
            if ($semester === '' && $band !== AcademicPeriod::BAND_BASIC && $approved && !empty($approved['semester'])) {
                $semester = (string) $approved['semester'];
                if ($effPeriod === '') $effPeriod = $semester;
            }
            $ayId = $period ? (int) $period['ay_id'] : 0;
            if ($ayId <= 0 && $approved && !empty($approved['academic_year_id'])) {
                $ayId = (int) $approved['academic_year_id'];
            }

            $lvl = $levelId > 0 ? Database::one(
                'SELECT id, code, name, category FROM grade_levels WHERE id = ? LIMIT 1',
                [$levelId]
            ) : null;
            if ($lvl) $band = AcademicPeriod::bandForCategory($lvl['category'] ?? null);

            $programId = null;
            if ($approved && !empty($approved['program_id'])) $programId = (int) $approved['program_id'];
            elseif (!empty($stu['program_id'])) $programId = (int) $stu['program_id'];
            $prog = $programId ? Database::one(
                'SELECT id, code, name FROM programs WHERE id = ? LIMIT 1',
                [$programId]
            ) : null;

            $ayLabel = '';
            if ($ayId > 0) {
                $ay = Database::one('SELECT school_year FROM academic_years WHERE id = ? LIMIT 1', [$ayId]);
                $ayLabel = $ay ? (string) $ay['school_year'] : '';
            }

            $category = (string) ($lvl['category'] ?? '');
            $code = strtoupper(trim((string) ($lvl['code'] ?? '')));
            $isCollege = $category === 'college'
                && ($code === '' || in_array($code, self::COLLEGE_CODES, true));
            // Any other college-category row (future C5+) still counts as college.
            if ($category === 'college' && !$isCollege) $isCollege = true;

            $hasActive = $enrolledStatus === 'enrolled' && $approved !== null;

            return [
                'student_id' => $studentId,
                'level_id' => $levelId,
                'level_code' => (string) ($lvl['code'] ?? ''),
                'level_name' => (string) ($lvl['name'] ?? ($period['level_name'] ?? '')),
                'short_year' => self::shortYear((string) ($lvl['code'] ?? ''), (string) ($lvl['name'] ?? '')),
                'category' => $category,
                'band' => $band,
                'period' => $effPeriod,
                'period_label' => AcademicPeriod::label($band, $effPeriod),
                'noun' => AcademicPeriod::noun($band, $effPeriod !== '' ? $effPeriod : null),
                'semester' => $semester,
                'ay_id' => $ayId,
                'ay_label' => $ayLabel,
                'program_id' => $prog ? (int) $prog['id'] : ($programId ?: null),
                'program_code' => (string) ($prog['code'] ?? ''),
                'program_name' => (string) ($prog['name'] ?? ''),
                'is_college' => $isCollege,
                'has_active_enrollment' => $hasActive,
                'enrolled_status' => $enrolledStatus,
                'source' => $period['source'] ?? 'profile',
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    private static ?bool $hasEaPeriodCol = null;
    private static ?bool $hasScPeriodCol = null;

    private static function hasEaPeriodCol(): bool
    {
        if (self::$hasEaPeriodCol === null) {
            try {
                self::$hasEaPeriodCol = (bool) Database::value(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollment_applications' AND COLUMN_NAME = 'period_code'"
                );
            } catch (Throwable $e) {
                self::$hasEaPeriodCol = false;
            }
        }
        return self::$hasEaPeriodCol;
    }

    public static function hasScPeriodCol(): bool
    {
        if (self::$hasScPeriodCol === null) {
            try {
                self::$hasScPeriodCol = (bool) Database::value(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subject_classrooms' AND COLUMN_NAME = 'period_code'"
                );
            } catch (Throwable $e) {
                self::$hasScPeriodCol = false;
            }
        }
        return self::$hasScPeriodCol;
    }

    /**
     * Resolve offering academic metadata (never from section name).
     * @return array{id:int,academic_year_id:int,semester:string,period:string,period_code:string|null,subject_id:int|null,grade_level_id:int|null,program_id:int|null,level_code:string,level_name:string,program_code:string}|null
     */
    public static function offeringMeta(int $classroomId): ?array
    {
        try {
            $has = self::hasOfferingAcadCols();
            $extra = $has ? ', sc.subject_id, sc.grade_level_id, sc.program_id' : '';
            if (self::hasScPeriodCol()) $extra .= ', sc.period_code';
            $row = Database::one(
                "SELECT sc.id, sc.academic_year_id, sc.semester, sc.subject_code{$extra}
                   FROM subject_classrooms sc WHERE sc.id = ? LIMIT 1",
                [$classroomId]
            );
            if (!$row) return null;
            $meta = [
                'id' => (int) $row['id'],
                'academic_year_id' => (int) $row['academic_year_id'],
                'semester' => (string) ($row['semester'] ?? ''),
                'period_code' => isset($row['period_code']) && $row['period_code'] !== null && trim((string) $row['period_code']) !== '' ? trim((string) $row['period_code']) : null,
                'subject_id' => isset($row['subject_id']) && $row['subject_id'] !== null ? (int) $row['subject_id'] : null,
                'grade_level_id' => isset($row['grade_level_id']) && $row['grade_level_id'] !== null ? (int) $row['grade_level_id'] : null,
                'program_id' => isset($row['program_id']) && $row['program_id'] !== null ? (int) $row['program_id'] : null,
                'level_code' => '', 'level_name' => '', 'program_code' => '',
            ];
            $meta['period'] = $meta['period_code'] ?? $meta['semester'];
            if (($meta['grade_level_id'] === null || $meta['subject_id'] === null) && !empty($row['subject_code'])) {
                try {
                    $sub = Database::one(
                        'SELECT id, grade_level_id FROM subjects WHERE code = ? AND is_active = 1 ORDER BY id LIMIT 1',
                        [trim((string) $row['subject_code'])]
                    );
                    if ($sub) {
                        if ($meta['subject_id'] === null) $meta['subject_id'] = (int) $sub['id'];
                        if ($meta['grade_level_id'] === null && !empty($sub['grade_level_id'])) {
                            $meta['grade_level_id'] = (int) $sub['grade_level_id'];
                        }
                    }
                    // Canonical basic-ed period fallback (guarded for older installs).
                    if ($meta['period_code'] === null) {
                        try {
                            $sp = Database::one(
                                'SELECT period_code FROM subjects WHERE code = ? AND is_active = 1 AND period_code IS NOT NULL ORDER BY id LIMIT 1',
                                [trim((string) $row['subject_code'])]
                            );
                            if ($sp && trim((string) $sp['period_code']) !== '') {
                                $meta['period_code'] = trim((string) $sp['period_code']);
                                $meta['period'] = $meta['period_code'];
                            }
                        } catch (Throwable $e) {
                        }
                    }
                } catch (Throwable $e) {
                }
            }
            if ($meta['grade_level_id']) {
                $lv = Database::one('SELECT code, name FROM grade_levels WHERE id = ? LIMIT 1', [$meta['grade_level_id']]);
                if ($lv) {
                    $meta['level_code'] = (string) $lv['code'];
                    $meta['level_name'] = (string) $lv['name'];
                }
            }
            if ($meta['program_id']) {
                $pg = Database::one('SELECT code FROM programs WHERE id = ? LIMIT 1', [$meta['program_id']]);
                if ($pg) $meta['program_code'] = (string) $pg['code'];
            }
            return $meta;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Backend-authoritative join validation. Never trusts browser input.
     * @return array{ok:bool,code:string,error:string,context:array|null,offering:array|null}
     */
    public static function checkJoin(int $studentId, int $classroomId): array
    {
        $fail = static fn(string $code, string $error, $ctx = null, $off = null)
            => ['ok' => false, 'code' => $code, 'error' => $error, 'context' => $ctx, 'offering' => $off];

        $stu = null;
        try {
            $stu = Database::one('SELECT * FROM students WHERE id = ? LIMIT 1', [$studentId]);
        } catch (Throwable $e) {
        }
        if ($stu === null) {
            return $fail('NO_STUDENT', 'Student record not found. Complete enrollment first.');
        }

        $ctx = self::currentContext($studentId);
        if ($ctx === null) {
            return $fail('NO_STUDENT', 'Student record not found. Complete enrollment first.');
        }
        if (!$ctx['has_active_enrollment']) {
            $bandName = AcademicPeriod::bandLabel((string) ($ctx['band'] ?? AcademicPeriod::BAND_COLLEGE));
            return $fail(
                'NO_ENROLLMENT',
                'You must have an active/approved ' . $bandName . ' enrollment before joining subjects.',
                $ctx
            );
        }
        $band = (string) ($ctx['band'] ?? AcademicPeriod::BAND_COLLEGE);
        if ($band !== AcademicPeriod::BAND_BASIC && $band !== AcademicPeriod::BAND_SHS && $band !== AcademicPeriod::BAND_COLLEGE) {
            $band = !empty($ctx['is_college']) ? AcademicPeriod::BAND_COLLEGE : AcademicPeriod::BAND_SHS;
        }

        $off = self::offeringMeta($classroomId);
        if ($off === null) {
            return $fail('NOT_FOUND', 'This class is no longer available.', $ctx);
        }
        try {
            $full = Database::one('SELECT * FROM subject_classrooms WHERE id = ? LIMIT 1', [$classroomId]);
        } catch (Throwable $e) {
            $full = null;
        }
        if ($full === null) {
            return $fail('NOT_FOUND', 'This class is no longer available.', $ctx, $off);
        }
        if ((int) ($full['is_active'] ?? 1) !== 1) {
            return $fail('CLOSED', 'This subject is not open for joining.', $ctx, $off);
        }
        if (array_key_exists('status', $full) && ($full['status'] ?? 'open') !== 'open') {
            $st = (string) ($full['status'] ?? 'closed');
            return $fail('CLOSED', 'This subject is ' . $st . ' and not open for joining.', $ctx, $off);
        }
        // Draft subjects (no teacher assigned yet) are never joinable.
        if (empty($full['teacher_id'])) {
            return $fail(
                'NO_TEACHER',
                'This subject has no assigned teacher yet — please try again after the admin assigns one.',
                $ctx,
                $off
            );
        }

        // Academic-year must match current enrollment.
        if ((int) $off['academic_year_id'] !== (int) $ctx['ay_id']) {
            $want = $ctx['ay_label'] !== '' ? $ctx['ay_label'] : 'your enrolled school year';
            return $fail(
                'WRONG_AY',
                'This subject belongs to a different school year. Your current enrollment is ' . $want . '.',
                $ctx,
                $off
            );
        }
        // Academic period must match current enrollment (band-correct:
        // quarters/terms for Basic-ed, semesters for SHS/College).
        $offPeriod = trim((string) ($off['period'] ?? ''));
        $myPeriod = trim((string) ($ctx['period'] ?? ''));
        if ($band === AcademicPeriod::BAND_BASIC) {
            if ($offPeriod === '') {
                return $fail(
                    'NO_PERIOD',
                    'This subject has no ' . $ctx['noun'] . ' assigned yet — ask the admin to set its academic period before joining.',
                    $ctx,
                    $off
                );
            }
            if ($myPeriod === '' || $offPeriod !== $myPeriod) {
                $mine = $myPeriod !== '' ? AcademicPeriod::label($band, $myPeriod) : 'your enrolled ' . $ctx['noun'];
                $theirs = AcademicPeriod::label($band, $offPeriod);
                return $fail(
                    'WRONG_PERIOD',
                    'This subject is for ' . $theirs . ', while your current enrollment is ' . $mine . '.',
                    $ctx,
                    $off
                );
            }
        } else {
            // SHS/College semester logic preserved exactly.
            $offSem = trim((string) $off['semester']);
            if ($offSem === '' || $offSem !== trim((string) $ctx['semester'])) {
                $mine = $ctx['semester'] !== '' ? $ctx['semester'] . ' Semester' : 'your enrolled semester';
                $theirs = $offSem !== '' ? $offSem . ' Semester' : 'an unassigned semester';
                return $fail(
                    'WRONG_SEMESTER',
                    'This subject is for ' . $theirs . ', while your current enrollment is ' . $mine . '.',
                    $ctx,
                    $off
                );
            }
        }
        // Year level must match current enrollment.
        if (empty($off['grade_level_id'])) {
            return $fail(
                'NO_LEVEL',
                'This subject has no year level assigned yet — ask the admin to set its year level before joining.',
                $ctx,
                $off
            );
        }
        if ((int) $off['grade_level_id'] !== (int) $ctx['level_id']) {
            if ($band === AcademicPeriod::BAND_COLLEGE) {
                $mine = self::shortYear($ctx['level_code'], $ctx['level_name']);
                $theirs = self::shortYear($off['level_code'], $off['level_name']);
            } else {
                $mine = $ctx['level_name'] !== '' ? $ctx['level_name'] : 'your enrolled level';
                $theirs = $off['level_name'] !== '' ? $off['level_name'] : 'another level';
            }
            return $fail(
                'WRONG_LEVEL',
                'This subject is assigned to ' . $theirs . ', while your current enrollment is ' . $mine . '.',
                $ctx,
                $off
            );
        }
        // Program restriction (only when the offering targets a program).
        if (!empty($off['program_id'])) {
            if (empty($ctx['program_id']) || (int) $ctx['program_id'] !== (int) $off['program_id']) {
                $mine = $ctx['program_code'] !== '' ? $ctx['program_code'] : 'your program';
                return $fail(
                    'WRONG_PROGRAM',
                    'This subject is intended for ' . ($off['program_code'] !== '' ? $off['program_code'] : 'another program') . ', not ' . $mine . '.',
                    $ctx,
                    $off
                );
            }
        }

        // Duplicate membership.
        try {
            $already = Database::one(
                'SELECT id FROM classroom_members WHERE classroom_id = ? AND student_id = ? LIMIT 1',
                [$classroomId, $studentId]
            );
            if ($already) {
                return $fail('DUPLICATE', 'You already joined this subject.', $ctx, $off);
            }
        } catch (Throwable $e) {
        }

        // Capacity.
        try {
            $max = (int) ($full['max_students'] ?? 0);
            $count = (int) Database::value(
                'SELECT COUNT(*) FROM classroom_members WHERE classroom_id = ?',
                [$classroomId]
            );
            if ($max > 0 && $count >= $max) {
                return $fail('FULL', 'Class is already full (' . $max . ' students).', $ctx, $off);
            }
        } catch (Throwable $e) {
        }

        // Schedule conflict (preserves existing parsing behavior; unparseable = no block).
        try {
            $sched = (string) ($full['schedule'] ?? '');
            $targetSlots = self::parseScheduleNote($sched);
            if ($targetSlots !== []) {
                $mine = Database::all(
                    'SELECT sc.schedule FROM classroom_members cm
                       JOIN subject_classrooms sc ON sc.id = cm.classroom_id
                      WHERE cm.student_id = ? AND cm.classroom_id <> ?',
                    [$studentId, $classroomId]
                );
                foreach ($mine as $m) {
                    $ms = self::parseScheduleNote((string) ($m['schedule'] ?? ''));
                    if ($ms !== [] && self::slotsConflict($ms, $targetSlots)) {
                        $conflictWith = Database::one(
                            'SELECT subject_code FROM subject_classrooms sc
                               JOIN classroom_members cm ON cm.classroom_id = sc.id
                              WHERE cm.student_id = ? AND sc.schedule = ? LIMIT 1',
                            [$studentId, (string) ($m['schedule'] ?? '')]
                        );
                        $label = $conflictWith ? (string) $conflictWith['subject_code'] : 'another joined subject';
                        return $fail(
                            'CONFLICT',
                            'Schedule conflict — this subject overlaps with ' . $label . ' (' . $sched . ').',
                            $ctx,
                            $off
                        );
                    }
                }
            }
        } catch (Throwable $e) {
        }

        return ['ok' => true, 'code' => 'ELIGIBLE', 'error' => '', 'context' => $ctx, 'offering' => $off];
    }

    /* ---- Schedule parsing (mirrors ClassRegistrationController behavior) ---- */

    private const DAY_MAP = [
        'MON' => 'MON', 'MONDAY' => 'MON', 'M' => 'MON',
        'TUE' => 'TUE', 'TUESDAY' => 'TUE', 'T' => 'TUE',
        'WED' => 'WED', 'WEDNESDAY' => 'WED', 'W' => 'WED',
        'THU' => 'THU', 'THUR' => 'THU', 'THURSDAY' => 'THU', 'TH' => 'THU', 'R' => 'THU',
        'FRI' => 'FRI', 'FRIDAY' => 'FRI', 'F' => 'FRI',
        'SAT' => 'SAT', 'SATURDAY' => 'SAT', 'S' => 'SAT',
        'SUN' => 'SUN', 'SUNDAY' => 'SUN',
    ];

    public static function parseScheduleNote(string $note): array
    {
        $note = strtoupper(trim($note));
        if ($note === '') return [];
        $slots = [];
        $chunks = preg_split('/[\r\n;]+/', $note) ?: [$note];
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') continue;
            if (!preg_match('/^([A-Z\/\-\s,]+?)\s+(\d.*)$/', $chunk, $m)) {
                if (!preg_match('/\b(MON(?:DAY)?|TUE(?:SDAY)?|WED(?:NESDAY)?|THU(?:RSDAY)?|FRI(?:DAY)?|SAT(?:URDAY)?|SUN(?:DAY)?|MWF|TTH|MW|TF)\b/i', $chunk, $dm, PREG_OFFSET_CAPTURE)) continue;
                $dayPart = $dm[0][0];
                $rest = trim(substr($chunk, $dm[0][1] + strlen($dayPart)));
            } else {
                $dayPart = trim($m[1]);
                $rest = trim($m[2]);
            }
            $days = self::expandDays($dayPart);
            if ($days === []) continue;
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
        $combos = ['MWF' => ['MON', 'WED', 'FRI'], 'TTH' => ['TUE', 'THU'], 'TTHS' => ['TUE', 'THU'], 'MW' => ['MON', 'WED'], 'TF' => ['TUE', 'FRI'], 'MTH' => ['MON', 'THU']];
        if (isset($combos[$part])) return $combos[$part];
        $tokens = preg_split('/[\/,\s\-]+/', $part, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) $tokens = [$part];
        foreach ($tokens as $tok) {
            $tok = trim($tok);
            if ($tok === '') continue;
            if (preg_match('/^[MTWRFS]+$/', $tok) && strlen($tok) > 1 && !isset($combos[$tok])) {
                foreach (str_split($tok) as $ch) {
                    $map = ['M' => 'MON', 'T' => 'TUE', 'W' => 'WED', 'R' => 'THU', 'F' => 'FRI', 'S' => 'SAT'];
                    if (isset($map[$ch]) && !in_array($map[$ch], $out, true)) $out[] = $map[$ch];
                }
                continue;
            }
            if (isset(self::DAY_MAP[$tok]) && !in_array(self::DAY_MAP[$tok], $out, true)) $out[] = self::DAY_MAP[$tok];
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

    public static function slotsConflict(array $a, array $b): bool
    {
        foreach ($a as $s1) {
            foreach ($b as $s2) {
                if (($s1['day'] ?? '') !== ($s2['day'] ?? '')) continue;
                $s1s = $s1['start'] ?? '';
                $s1e = $s1['end'] ?? '';
                $s2s = $s2['start'] ?? '';
                $s2e = $s2['end'] ?? '';
                if ($s1s < $s2e && $s2s < $s1e) return true;
            }
        }
        return false;
    }

    /** Eligibility label for list/detail display. */
    public static function displayState(int $studentId, int $classroomId, ?array $row = null): array
    {
        try {
            $r = self::checkJoin($studentId, $classroomId);
            if ($r['ok']) return ['state' => 'eligible', 'label' => 'Join Subject', 'reason' => ''];
            $map = [
                'DUPLICATE' => ['joined', 'Joined'],
                'FULL' => ['full', 'Class Full'],
                'CLOSED' => ['closed', 'Closed'],
                'NO_TEACHER' => ['no_teacher', 'Not Available'],
                'WRONG_PERIOD' => ['wrong_period', 'Not Eligible'],
                'NO_PERIOD' => ['no_period', 'Not Available'],
                'WRONG_SEMESTER' => ['wrong_sem', 'Not Available'],
                'WRONG_LEVEL' => ['wrong_level', 'Not Eligible'],
                'WRONG_PROGRAM' => ['wrong_program', 'Not Eligible'],
                'WRONG_AY' => ['wrong_ay', 'Not Available'],
                'NO_ENROLLMENT' => ['no_enroll', 'Enrollment Required'],
                'NOT_COLLEGE' => ['not_college', 'Not Available'],
                'CONFLICT' => ['conflict', 'Schedule Conflict'],
                'NO_LEVEL' => ['no_level', 'Not Available'],
            ];
            $m = $map[$r['code']] ?? ['blocked', 'Not Eligible'];
            return ['state' => $m[0], 'label' => $m[1], 'reason' => $r['error'], 'code' => $r['code']];
        } catch (Throwable $e) {
            return ['state' => 'blocked', 'label' => 'Not Eligible', 'reason' => ''];
        }
    }
}
