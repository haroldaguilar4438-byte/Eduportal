<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Semester-to-semester enrollment cycle engine (backend-authoritative).
 *
 * Reuses existing concepts only:
 *   current period  <- latest APPROVED enrollment_applications row
 *   next period     <- grade_levels.sort_order + 1st/2nd/Summer rotation
 *   completed legs  <- clearances (teachers) + financial_status (accounting)
 *                      + registrar_status (registrar) for the current period
 *   history         <- one enrollment_applications row per student/AY/semester
 *
 * Students are never duplicated; previous rows are never overwritten.
 */
final class EnrollmentCycle
{
    public const SEMESTERS = ['1st', '2nd', 'Summer'];
    public const OPEN_STATUSES = ['pending', 'accounting_approved', 'awaiting_payment', 'action_required'];

    /** Education band for a level (delegates to the centralized AcademicPeriod). */
    public static function bandForLevel(int $levelId): string
    {
        return AcademicPeriod::bandForLevel($levelId);
    }

    /** @return array<int,array{id:int,code:string,name:string,category:string,sort_order:int}> */
    public static function levels(): array
    {
        try {
            return Database::all("SELECT id, code, name, category, sort_order FROM grade_levels ORDER BY sort_order ASC");
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function levelName(int $levelId): string
    {
        try {
            $r = Database::one("SELECT name FROM grade_levels WHERE id = ? LIMIT 1", [$levelId]);
            return $r ? (string) $r['name'] : '';
        } catch (Throwable $e) {
            return '';
        }
    }

    public static function ayLabel(int $ayId): string
    {
        try {
            $r = Database::one("SELECT school_year FROM academic_years WHERE id = ? LIMIT 1", [$ayId]);
            return $r ? (string) $r['school_year'] : '';
        } catch (Throwable $e) {
            return '';
        }
    }

    public static function currentAyId(): int
    {
        try {
            $r = Database::one("SELECT id FROM academic_years WHERE is_current = 1 LIMIT 1");
            if ($r) return (int) $r['id'];
            $r = Database::one("SELECT id FROM academic_years ORDER BY id DESC LIMIT 1");
            return $r ? (int) $r['id'] : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Next academic period after (level, period, AY) — band-aware.
     * $semester carries the effective period: '1st'/'2nd'/'Summer' for SHS &
     * College (legacy behavior preserved exactly), 'Q1'..'Q4' / 'T1'..'T3' /
     * custom codes for Basic-ed (Quarter/Term rotation, then next level).
     * Extra keys added (band, period, period_label); legacy keys kept.
     * @return array{level_id:int,level_name:string,semester:string,ay_id:int,ay_label:string,graduating:bool,band:string,period:string,period_label:string}
     */
    public static function nextPeriod(int $levelId, string $semester, int $ayId): array
    {
        $band = AcademicPeriod::bandForLevel($levelId);
        $period = trim($semester);
        $empty = static function () use ($band): array {
            return ['level_id' => 0, 'level_name' => '', 'semester' => '', 'ay_id' => 0, 'ay_label' => '', 'graduating' => true, 'band' => $band, 'period' => '', 'period_label' => ''];
        };
        $levels = self::levels();
        $idx = null;
        foreach ($levels as $i => $l) {
            if ((int) $l['id'] === $levelId) {
                $idx = $i;
                break;
            }
        }
        $at = static function (string $sem, string $per, int $lvlId, int $aId) use ($band): array {
            return [
                'level_id' => $lvlId, 'level_name' => self::levelName($lvlId),
                'semester' => $sem, 'ay_id' => $aId, 'ay_label' => self::ayLabel($aId),
                'graduating' => false, 'band' => $band,
                'period' => $per, 'period_label' => AcademicPeriod::label($band, $per),
            ];
        };
        if ($band === AcademicPeriod::BAND_BASIC) {
            // Quarter/Term rotation: Q1->Q2->Q3->Q4 (or T1->T2->T3), then next level.
            if ($period === '') $period = AcademicPeriod::firstPeriod($band);
            $nextCode = AcademicPeriod::nextPeriodCode($band, $period);
            if ($nextCode !== null) {
                return $at('', $nextCode, $levelId, $ayId);
            }
            $next = ($idx !== null && isset($levels[$idx + 1])) ? $levels[$idx + 1] : null;
            if ($next === null) return $empty();
            $nextBand = AcademicPeriod::bandForCategory($next['category'] ?? null);
            $first = AcademicPeriod::firstPeriod($nextBand);
            $curLabel = self::ayLabel($ayId);
            $nextLabel = self::nextAyLabel($curLabel);
            $nextAyId = 0;
            if ($nextLabel !== '') {
                try {
                    $r = Database::one("SELECT id FROM academic_years WHERE school_year = ? LIMIT 1", [$nextLabel]);
                    if ($r) $nextAyId = (int) $r['id'];
                } catch (Throwable $e) {
                }
            }
            return [
                'level_id' => (int) $next['id'], 'level_name' => (string) $next['name'],
                'semester' => $nextBand === AcademicPeriod::BAND_BASIC ? '' : $first,
                'ay_id' => $nextAyId, 'ay_label' => $nextLabel,
                'graduating' => false, 'band' => $nextBand,
                'period' => $first, 'period_label' => AcademicPeriod::label($nextBand, $first),
            ];
        }
        // SHS/College: legacy rotation preserved EXACTLY (1st -> 2nd same
        // level; 2nd/Summer/anything-else -> next level, 1st semester).
        if ($period === '1st') {
            return $at('2nd', '2nd', $levelId, $ayId);
        }
        $next = ($idx !== null && isset($levels[$idx + 1])) ? $levels[$idx + 1] : null;
        if ($next === null) return $empty();
        $nextBand = AcademicPeriod::bandForCategory($next['category'] ?? null);
        $first = AcademicPeriod::firstPeriod($nextBand);
        $curLabel = self::ayLabel($ayId);
        $nextLabel = self::nextAyLabel($curLabel);
        $nextAyId = 0;
        if ($nextLabel !== '') {
            try {
                $r = Database::one("SELECT id FROM academic_years WHERE school_year = ? LIMIT 1", [$nextLabel]);
                if ($r) $nextAyId = (int) $r['id'];
            } catch (Throwable $e) {
            }
        }
        return [
            'level_id' => (int) $next['id'], 'level_name' => (string) $next['name'],
            'semester' => $nextBand === AcademicPeriod::BAND_BASIC ? '' : $first,
            'ay_id' => $nextAyId, 'ay_label' => $nextLabel,
            'graduating' => false, 'band' => $nextBand,
            'period' => $first, 'period_label' => AcademicPeriod::label($nextBand, $first),
        ];
    }

    public static function nextAyLabel(string $schoolYear): string
    {
        if (preg_match('/^(\d{4})-(\d{4})$/', trim($schoolYear), $m)) {
            return ((int) $m[1] + 1) . '-' . ((int) $m[2] + 1);
        }
        return '';
    }

    /** Find-or-create the school-year row (audited); 0 when label unknown. */
    public static function ensureNextAy(string $label): int
    {
        if ($label === '') return 0;
        try {
            $r = Database::one("SELECT id FROM academic_years WHERE school_year = ? LIMIT 1", [$label]);
            if ($r) return (int) $r['id'];
            if (preg_match('/^(\d{4})-(\d{4})$/', $label, $m)) {
                $id = Database::insert('academic_years', [
                    'school_year' => $label,
                    'start_date' => $m[1] . '-06-01', 'end_date' => $m[2] . '-05-31',
                    'is_current' => 0,
                ]);
                try {
                    Database::insert('audit_logs', [
                        'actor_user_id' => Auth::id(), 'action' => 'enrollment_cycle.ay_created',
                        'entity_type' => 'academic_years', 'entity_id' => $id, 'ip_address' => client_ip(),
                    ]);
                } catch (Throwable $e) {
                }
                return $id;
            }
        } catch (Throwable $e) {
        }
        return 0;
    }

    /**
     * Current period for a student: latest APPROVED enrollment, else fallback
     * to profile level + current AY + latest clearance period.
     * SHS/College keep legacy semester semantics EXACTLY; Basic-ed resolves
     * quarters/terms from the enrollment's period_code (never '1st Semester').
     * Extra keys added (band, period, period_label); legacy keys kept, with
     * 'semester' left '' for basic-ed so no caller can display semesters there.
     * @return array{level_id:int,level_name:string,semester:string,ay_id:int,ay_label:string,source:string,band:string,period:string,period_label:string}|null
     */
    public static function currentPeriod(int $studentId): ?array
    {
        try {
            $hasPeriodCol = (bool) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollment_applications' AND COLUMN_NAME = 'period_code'"
            );
            $periodSel = $hasPeriodCol ? 'ea.period_code,' : 'NULL AS period_code,';
            $app = Database::one(
                "SELECT ea.grade_level_applied_id, ea.semester, ea.academic_year_id, {$periodSel}
                        gl.name AS level_name, gl.category AS level_category, ay.school_year
                   FROM enrollment_applications ea
                   JOIN grade_levels gl ON gl.id = ea.grade_level_applied_id
                   JOIN academic_years ay ON ay.id = ea.academic_year_id
                  WHERE ea.student_id = ? AND ea.status = 'approved'
                  ORDER BY ea.created_at DESC, ea.id DESC LIMIT 1",
                [$studentId]
            );
            if ($app) {
                $band = AcademicPeriod::bandForCategory($app['level_category'] ?? null);
                $period = AcademicPeriod::effectivePeriod($app);
                if ($period === '') {
                    // Legacy row without period info.
                    $clr = Database::one(
                        "SELECT semester FROM clearances WHERE student_id = ? AND academic_year_id = ? ORDER BY created_at DESC LIMIT 1",
                        [$studentId, (int) $app['academic_year_id']]
                    );
                    $clrSem = $clr ? trim((string) $clr['semester']) : '';
                    if ($band === AcademicPeriod::BAND_BASIC) {
                        // Never label basic-ed as semester: accept a stored
                        // quarter/term, else the configured first period.
                        $period = AcademicPeriod::isValid($band, $clrSem) ? $clrSem : AcademicPeriod::firstPeriod($band);
                    } else {
                        $period = in_array($clrSem, self::SEMESTERS, true) ? $clrSem : '1st';
                    }
                }
                $semKey = $band === AcademicPeriod::BAND_BASIC ? '' : $period;
                return [
                    'level_id' => (int) $app['grade_level_applied_id'], 'level_name' => (string) $app['level_name'],
                    'semester' => $semKey, 'ay_id' => (int) $app['academic_year_id'], 'ay_label' => (string) $app['school_year'],
                    'source' => 'enrollment', 'band' => $band, 'period' => $period,
                    'period_label' => AcademicPeriod::label($band, $period),
                ];
            }
            $stu = Database::one("SELECT grade_level_id FROM students WHERE id = ? LIMIT 1", [$studentId]);
            if ($stu === null || empty($stu['grade_level_id'])) return null;
            $band = AcademicPeriod::bandForLevel((int) $stu['grade_level_id']);
            $ayId = self::currentAyId();
            if (!$ayId) return null;
            $clr = Database::one(
                "SELECT semester FROM clearances WHERE student_id = ? AND academic_year_id = ? ORDER BY created_at DESC LIMIT 1",
                [$studentId, $ayId]
            );
            $clrSem = $clr ? trim((string) $clr['semester']) : '';
            if ($band === AcademicPeriod::BAND_BASIC) {
                $period = AcademicPeriod::isValid($band, $clrSem) ? $clrSem : AcademicPeriod::firstPeriod($band);
                $semKey = '';
            } else {
                $period = in_array($clrSem, self::SEMESTERS, true) ? $clrSem : '1st';
                $semKey = $period;
            }
            return [
                'level_id' => (int) $stu['grade_level_id'], 'level_name' => self::levelName((int) $stu['grade_level_id']),
                'semester' => $semKey, 'ay_id' => $ayId, 'ay_label' => self::ayLabel($ayId),
                'source' => 'profile', 'band' => $band, 'period' => $period,
                'period_label' => AcademicPeriod::label($band, $period),
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Overall clearance completion: teachers issued + accounting cleared + registrar approved. */
    public static function isComplete(array $clr): bool
    {
        return ($clr['status'] ?? '') === 'issued'
            && ($clr['financial_status'] ?? '') === 'cleared'
            && ($clr['registrar_status'] ?? 'pending') === 'approved';
    }

    /**
     * Full eligibility evaluation for the NEXT period.
     * @return array{state:string,current:?array,next:?array,clearance:?array,items:array<string,array{ok:bool,label:string,detail:string}>,reasons:string[]}
     * state: eligible | not_eligible | no_record | graduating | already_enrolled
     */
    public static function checklist(int $studentId): array
    {
        $out = ['state' => 'no_record', 'current' => null, 'next' => null, 'clearance' => null, 'items' => [], 'reasons' => []];
        try {
            $stu = Database::one(
                "SELECT s.*, u.status AS user_status FROM students s LEFT JOIN users u ON u.id = s.user_id WHERE s.id = ? LIMIT 1",
                [$studentId]
            );
            if ($stu === null) {
                $out['reasons'][] = 'Student record not found.';
                return $out;
            }
            // 1. Account / hold (existing statuses only)
            $userOk = ($stu['user_status'] ?? 'active') === 'active';
            $profileOk = ($stu['status'] ?? '') === 'enrolled';
            $out['items']['account'] = [
                'ok' => $userOk && $profileOk, 'label' => 'Active account, no holds',
                'detail' => !$userOk ? 'Account status: ' . ($stu['user_status'] ?? '?') . ' — contact admin.'
                    : (!$profileOk ? 'Student status: ' . ($stu['status'] ?? '?') . ' — contact registrar.' : 'Account active, currently enrolled.'),
            ];
            if (!$userOk || !$profileOk) {
                $out['state'] = 'not_eligible';
                $out['reasons'][] = $out['items']['account']['detail'];
                return $out;
            }
            // 2. Current + next period
            $cur = self::currentPeriod($studentId);
            if ($cur === null) {
                $out['reasons'][] = 'No enrollment record found — complete a first enrollment.';
                return $out;
            }
            $out['current'] = $cur;
            $curBand = (string) ($cur['band'] ?? AcademicPeriod::BAND_COLLEGE);
            $curPeriod = (string) ($cur['period'] ?? $cur['semester']);
            if ($curPeriod === '') $curPeriod = (string) $cur['semester'];
            $next = self::nextPeriod((int) $cur['level_id'], $curPeriod, (int) $cur['ay_id']);
            $out['next'] = $next;
            if (!empty($next['graduating'])) {
                $out['state'] = 'graduating';
                return $out;
            }
            $nextBand = (string) ($next['band'] ?? $curBand);
            $nextPeriod = (string) ($next['period'] ?? $next['semester']);
            // 3. Duplicate: any open or approved application for the NEXT period.
            // Basic-ed matches period_code; SHS/College match semester (legacy).
            $hasEaPeriod = self::hasEaPeriodCol();
            $dup = null;
            if (!empty($next['ay_id'])) {
                if ($nextBand === AcademicPeriod::BAND_BASIC && $hasEaPeriod) {
                    $dup = Database::one(
                        "SELECT application_no, status FROM enrollment_applications
                          WHERE student_id = ? AND academic_year_id = ? AND COALESCE(period_code,'') = COALESCE(?,'')
                            AND status IN ('pending','accounting_approved','awaiting_payment','action_required','approved')
                          ORDER BY created_at DESC LIMIT 1",
                        [$studentId, (int) $next['ay_id'], $nextPeriod]
                    );
                } else {
                    $dup = Database::one(
                        "SELECT application_no, status FROM enrollment_applications
                          WHERE student_id = ? AND academic_year_id = ? AND semester = ?
                            AND status IN ('pending','accounting_approved','awaiting_payment','action_required','approved')
                          ORDER BY created_at DESC LIMIT 1",
                        [$studentId, (int) $next['ay_id'], (string) $next['semester']]
                    );
                }
            } else {
                $dup = Database::one(
                    "SELECT application_no, status FROM enrollment_applications
                      WHERE student_id = ? AND status IN ('pending','accounting_approved','awaiting_payment','action_required')
                      ORDER BY created_at DESC LIMIT 1",
                    [$studentId]
                );
            }
            if ($dup !== null) {
                $out['state'] = 'already_enrolled';
                $out['reasons'][] = 'Application ' . $dup['application_no'] . ' (' . str_replace('_', ' ', $dup['status']) . ') already covers the next period.';
                return $out;
            }
            // 4. Current-period clearance legs (clearances.semester carries the
            // effective period for every band post-migration).
            $clr = Database::one(
                "SELECT * FROM clearances WHERE student_id = ? AND academic_year_id = ? AND semester = ? LIMIT 1",
                [$studentId, (int) $cur['ay_id'], $curPeriod]
            );
            $out['clearance'] = $clr;
            // Override bypasses academic legs (holds + duplicates above still apply)
            if ($clr && (int) ($clr['eligibility_override'] ?? 0) === 1) {
                $out['items']['override'] = ['ok' => true, 'label' => 'Registrar override', 'detail' => 'Marked eligible by registrar' . (!empty($clr['override_reason']) ? ': ' . $clr['override_reason'] : '.')];
                $out['state'] = 'eligible';
                return $out;
            }
            // 5. Grades: every joined subject in the current AY (up to current period) finalized.
            // Period carriers differ by band: COALESCE(period_code, semester).
            $upto = self::uptoPeriod($curBand, $curPeriod !== '' ? $curPeriod : (string) $cur['semester']);
            $periodExpr = self::hasScPeriodCol()
                ? 'COALESCE(sc.period_code, sc.semester)'
                : 'sc.semester';
            $subjects = Database::all(
                "SELECT DISTINCT sc.id, sc.subject_code FROM classroom_members cm
                   JOIN subject_classrooms sc ON sc.id = cm.classroom_id
                  WHERE cm.student_id = ? AND sc.academic_year_id = ?
                    AND ({$periodExpr} IS NULL OR {$periodExpr} IN (" . implode(',', array_fill(0, count($upto), '?')) . "))",
                array_merge([$studentId, (int) $cur['ay_id']], $upto)
            );
            $missingGrades = [];
            $incGrades = [];
            foreach ($subjects as $subj) {
                $fin = (int) Database::value(
                    "SELECT COUNT(*) FROM grade_sheets gs JOIN grade_sheet_rows r ON r.sheet_id = gs.id
                      WHERE gs.classroom_id = ? AND gs.status = 'finalized' AND r.student_id = ? AND r.grade IS NOT NULL LIMIT 1",
                    [(int) $subj['id'], $studentId]
                );
                // COUNT with LIMIT 1 returns 0/1 — sufficient as existence check
                if ($fin >= 1) continue;
                $inc = (int) Database::value(
                    "SELECT COUNT(*) FROM grade_sheets gs JOIN grade_sheet_rows r ON r.sheet_id = gs.id
                      WHERE gs.classroom_id = ? AND gs.status = 'finalized' AND r.student_id = ? AND r.grade IS NULL AND r.remarks = 'INC' LIMIT 1",
                    [(int) $subj['id'], $studentId]
                );
                if ($inc >= 1) $incGrades[] = (string) $subj['subject_code'];
                else $missingGrades[] = (string) $subj['subject_code'];
            }
            $gradeDetail = count($subjects) . ' subject(s) finalized.';
            if ($missingGrades !== [] || $incGrades !== []) {
                $parts = [];
                if ($missingGrades !== []) $parts[] = 'Missing finalized grades: ' . implode(', ', $missingGrades) . '.';
                if ($incGrades !== []) $parts[] = 'INCOMPLETE (INC) — complete in summer per Registrar: ' . implode(', ', $incGrades) . '.';
                $gradeDetail = implode(' ', $parts);
            }
            $out['items']['grades'] = [
                'ok' => $missingGrades === [] && $incGrades === [], 'label' => 'Grades finalized',
                'detail' => $gradeDetail,
            ];
            // 6. Permits: Finals fully approved for current AY (existing rule)
            $final = Database::one(
                "SELECT status FROM exam_permits WHERE student_id = ? AND academic_year_id = ? AND exam_period = 'Finals' LIMIT 1",
                [$studentId, (int) $cur['ay_id']]
            );
            $permOk = $final !== null && $final['status'] === 'fully_approved';
            $out['items']['permits'] = [
                'ok' => $permOk, 'label' => 'Permits completed',
                'detail' => $permOk ? 'Finals permit fully approved.' : 'Finals exam permit is not fully approved yet.',
            ];
            // 7-9. Clearance legs
            $out['items']['teachers'] = [
                'ok' => $clr !== null && $clr['status'] === 'issued', 'label' => 'Teacher clearance',
                'detail' => $clr === null ? 'No clearance requested for this period yet.' : ($clr['status'] === 'issued' ? 'All professors signed — issued.' : 'Status: ' . $clr['status'] . ' — awaiting professor signatures.'),
            ];
            $out['items']['accounting'] = [
                'ok' => $clr !== null && ($clr['financial_status'] ?? '') === 'cleared', 'label' => 'Accounting clearance',
                'detail' => $clr === null ? 'No clearance yet.' : 'Financial status: ' . str_replace('_', ' ', ($clr['financial_status'] ?? 'pending')) . '.',
            ];
            $out['items']['registrar'] = [
                'ok' => $clr !== null && ($clr['registrar_status'] ?? 'pending') === 'approved', 'label' => 'Registrar clearance',
                'detail' => $clr === null ? 'No clearance yet.' : 'Academic review: ' . ($clr['registrar_status'] ?? 'pending') . '.',
            ];
            if (!empty($clr['teacher_remarks'] ?? '')) {
                $out['items']['teacher_note'] = ['ok' => true, 'label' => 'Teacher note', 'detail' => (string) $clr['teacher_remarks']];
            }
            $bad = [];
            foreach (['grades', 'permits', 'teachers', 'accounting', 'registrar'] as $k) {
                if (!$out['items'][$k]['ok']) $bad[] = $out['items'][$k]['label'] . ' — ' . $out['items'][$k]['detail'];
            }
            if ($bad === []) {
                $out['state'] = 'eligible';
            } else {
                $out['state'] = 'not_eligible';
                $out['reasons'] = $bad;
            }
            return $out;
        } catch (Throwable $e) {
            $out['state'] = 'not_eligible';
            $out['reasons'][] = 'Could not evaluate eligibility right now.';
            return $out;
        }
    }

    /** @return string[] semesters up to and including $semester (legacy college helper) */
    private static function uptoSemester(string $semester): array
    {
        $out = [];
        foreach (self::SEMESTERS as $s) {
            $out[] = $s;
            if ($s === $semester) break;
        }
        return $out === [] ? ['1st'] : $out;
    }

    /** @return string[] configured periods up to and including $period for a band */
    private static function uptoPeriod(string $band, string $period): array
    {
        if ($band === AcademicPeriod::BAND_BASIC) {
            return AcademicPeriod::uptoPeriod($band, $period);
        }
        return self::uptoSemester($period);
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

    private static function hasScPeriodCol(): bool
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
     * After any clearance leg changes, mark completion once and notify.
     * Notifies the student (eligible) and registrars (student ready).
     */
    public static function maybeComplete(int $clearanceId): bool
    {
        try {
            $clr = Database::one("SELECT * FROM clearances WHERE id = ? LIMIT 1", [$clearanceId]);
            if ($clr === null || !self::isComplete($clr)) return false;
            $done = Database::one(
                "SELECT id FROM audit_logs WHERE action = 'clearance.completed' AND entity_type = 'clearances' AND entity_id = ? LIMIT 1",
                [$clearanceId]
            );
            if ($done !== null) return true;
            Database::insert('audit_logs', [
                'actor_user_id' => Auth::id(), 'action' => 'clearance.completed',
                'entity_type' => 'clearances', 'entity_id' => $clearanceId, 'ip_address' => client_ip(),
            ]);
            $stu = Database::one("SELECT user_id, student_no FROM students WHERE id = ? LIMIT 1", [(int) $clr['student_id']]);
            if ($stu && !empty($stu['user_id'])) {
                \App\Controllers\NotificationController::push(
                    (int) $stu['user_id'], 'You are eligible for next enrollment',
                    'Clearance #' . $clearanceId . ' is complete (teachers, accounting, registrar). Open Enrollment to start your next period.',
                    'success', '/enrollment/eligibility'
                );
            }
            \App\Controllers\NotificationController::pushToRegistrars(
                'Student ready for next enrollment' . (!empty($stu['student_no']) ? ' (' . $stu['student_no'] . ')' : ''),
                'Clearance #' . $clearanceId . ' completed all three legs — student may now re-enroll.',
                'success', '/registrar/re-enrollment'
            );
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function periodLabel(array $p): string
    {
        return trim(($p['level_name'] ?? '') . ' – ' . ($p['semester'] ?? '') . ' Semester');
    }
}
