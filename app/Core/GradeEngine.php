<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Weighted grade calculation engine (backend-authoritative).
 *
 * Grading policy (school rule):
 *   Category score  = SUM(graded scores, missing MINOR work = 0)
 *                     / SUM(max scores of all in-scope tasks)
 *   Period score    = SUM(categoryPct × weight) / SUM(all configured
 *                     weights — every category counts, none renormalized away)
 *   Point grade     = grade_conversions lookup on the period score
 *
 * Missing vs zero vs incomplete:
 *   - Missing MINOR work (activities, quizzes, assignments) → ZERO.
 *     It stays in the denominator and deducts from the weighted grade.
 *   - Any missing MAJOR work (exams, projects — see is_major on
 *     assessment_categories) → INCOMPLETE. The student gets no numeric
 *     grade for the period; INC flows to grade slips until the student
 *     completes it (e.g. summer + payment) and the teacher grades it via
 *     the registrar reopen -> resubmit -> finalize pipeline.
 *   - Graded score of 0 → ZERO (counts, same as before).
 *
 * Scope: tasks tagged to the period always count; untagged (legacy) tasks
 * count in every period and untagged categories default to Activities —
 * both facts are surfaced in the UI so nothing is silent. Zero-weight
 * categories are included (shown, and their missing minor work deducts
 * within their 0% share); they are never silently dropped.
 */
final class GradeEngine
{
    /**
     * Item-based categories use Total Items (whole numbers 1–100).
     * Points-based categories (projects, performance, etc.) use a
     * configured maximum score (decimals allowed, still 1–100).
     */
    public const ITEM_BASED = ['exams', 'quizzes', 'activities', 'assignments', 'tests'];

    public static function isItemBased(?string $code): bool
    {
        if ($code === null || $code === '') return true; // legacy untagged → Activities
        return in_array(strtolower(trim($code)), self::ITEM_BASED, true);
    }

    /** Percentage = (raw / max) × 100. Null when max invalid. Full precision — round only for display. */
    public static function pct(?float $raw, ?float $max): ?float
    {
        if ($raw === null || $max === null || $max <= 0) return null;
        return ($raw / $max) * 100;
    }

    public static function fmtPct(?float $pct): string
    {
        if ($pct === null) return '—';
        return number_format(round($pct, 2), 2) . '%';
    }

    /** "45 / 60" raw-score label — never call this a final grade. */
    public static function fmtRaw(?float $raw, ?float $max): string
    {
        if ($raw === null || $max === null) return '—';
        $r = (float) $raw == (int) $raw ? (string) (int) $raw : rtrim(rtrim(number_format($raw, 2, '.', ''), '0'), '.');
        $m = (float) $max == (int) $max ? (string) (int) $max : rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.');
        return $r . ' / ' . $m;
    }

    /**
     * Validate Total Items / Maximum Score (1–100).
     * Item-based → positive whole numbers only.
     * @return array{ok:bool,error:string,value:float|null}
     */
    public static function validateMax(string $raw, ?string $catCode): array
    {
        if (!is_numeric($raw)) return ['ok' => false, 'error' => 'Total items must be a number between 1 and 100.', 'value' => null];
        $v = (float) $raw;
        if ($v < 1 || $v > 100) return ['ok' => false, 'error' => 'Total items / maximum score must be between 1 and 100.', 'value' => null];
        if (self::isItemBased($catCode) && abs($v - round($v)) > 0.0001) {
            return ['ok' => false, 'error' => 'Total items must be a whole number (e.g. 60) for this category.', 'value' => null];
        }
        return ['ok' => true, 'error' => '', 'value' => round($v, 2)];
    }

    /**
     * Validate a student's raw score against the task maximum.
     * @return array{ok:bool,error:string,value:float|null}
     */
    public static function validateRaw(string $raw, float $max): array
    {
        $maxLabel = rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.');
        if (!is_numeric($raw)) return ['ok' => false, 'error' => 'Score must be a number from 0 to ' . $maxLabel . '.', 'value' => null];
        $v = round((float) $raw, 2);
        if ($v < 0) return ['ok' => false, 'error' => 'Score cannot be negative.', 'value' => null];
        if ($v > $max + 0.0001) {
            return ['ok' => false, 'error' => 'Score cannot be higher than the total number of items (' . $maxLabel . ').', 'value' => null];
        }
        return ['ok' => true, 'error' => '', 'value' => $v];
    }

    /** Does classroom_tasks.passing_score exist (patch applied)? Fail-open. */
    public static function passingColExists(): bool
    {
        try {
            return (bool) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'classroom_tasks' AND COLUMN_NAME = 'passing_score'"
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Validate optional teacher-set passing raw score (0 < passing <= max).
     * Empty string = no pass/fail judgment (NULL).
     * @return array{ok:bool,error:string,value:float|null}
     */
    public static function validatePassing(string $raw, float $max): array
    {
        $raw = trim($raw);
        if ($raw === '') return ['ok' => true, 'error' => '', 'value' => null];
        if (!is_numeric($raw)) return ['ok' => false, 'error' => 'Passing score must be a number.', 'value' => null];
        $v = round((float) $raw, 2);
        $maxLabel = rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.');
        if ($v <= 0 || $v > $max + 0.0001) {
            return ['ok' => false, 'error' => 'Passing score must be between 1 and the total items (' . $maxLabel . ').', 'value' => null];
        }
        return ['ok' => true, 'error' => '', 'value' => $v];
    }

    /**
     * Auto pass/fail from the teacher's threshold.
     * Returns null when no threshold set (no judgment).
     * @return bool|null true=PASSED, false=NOT PASSED, null=no threshold
     */
    public static function passFail(?float $score, $passing): ?bool
    {
        if ($score === null || $passing === null || $passing === '') return null;
        return (float) $score >= (float) $passing;
    }

    /** @return array<int,array{weight:float,code:string,name:string}> keyed by category id */
    public static function weightsFor(int $classroomId): array
    {
        $rows = Database::all(
            "SELECT gw.category_id, gw.weight_pct, ac.code, ac.name
               FROM grade_weights gw JOIN assessment_categories ac ON ac.id = gw.category_id
              WHERE gw.classroom_id = ? AND ac.is_active = 1 ORDER BY ac.sort_order ASC",
            [$classroomId]
        );
        if ($rows === []) {
            $rows = Database::all(
                "SELECT gw.category_id, gw.weight_pct, ac.code, ac.name
                   FROM grade_weights gw JOIN assessment_categories ac ON ac.id = gw.category_id
                  WHERE gw.classroom_id IS NULL AND ac.is_active = 1 ORDER BY ac.sort_order ASC",
                []
            );
        }
        $out = [];
        foreach ($rows as $r) {
            // Zero-weight categories are INCLUDED (shown at 0% and their
            // missing minor work still deducts within that share) — they are
            // never silently dropped from grading.
            $out[(int) $r['category_id']] = [
                'weight' => max(0.0, (float) $r['weight_pct']),
                'code' => (string) $r['code'],
                'name' => (string) $r['name'],
            ];
        }
        return $out;
    }

    /**
     * Major categories: missing work here forces INC for the period.
     * Reads assessment_categories.is_major when the incomplete patch is
     * applied, else falls back to exams + projects (safe before/after import).
     * @return array<int,bool> keyed by category id
     */
    public static function majorsFor(): array
    {
        $fallback = static function (): array {
            $map = [];
            try {
                foreach (self::categories() as $c) {
                    $map[(int) $c['id']] = in_array((string) ($c['code'] ?? ''), ['exams', 'projects'], true);
                }
            } catch (\Throwable $e) {
            }
            return $map;
        };
        try {
            $has = (bool) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assessment_categories' AND COLUMN_NAME = 'is_major'"
            );
            if (!$has) return $fallback();
            $out = [];
            foreach (Database::all("SELECT id, is_major FROM assessment_categories WHERE is_active = 1") as $c) {
                $out[(int) $c['id']] = ((int) ($c['is_major'] ?? 0)) === 1;
            }
            return $out !== [] ? $out : $fallback();
        } catch (\Throwable $e) {
            return $fallback();
        }
    }

    /** @return array<int,array> ordered conversion bands */
    public static function conversions(): array
    {
        return Database::all("SELECT * FROM grade_conversions ORDER BY sort_order ASC");
    }

    public static function pointFor(float $pct, ?array $bands = null): ?float
    {
        $bands = $bands ?? self::conversions();
        foreach ($bands as $b) {
            if ($pct >= (float) $b['min_pct'] && $pct <= (float) $b['max_pct']) return round((float) $b['point'], 2);
        }
        return null;
    }

    public static function categories(): array
    {
        return Database::all("SELECT * FROM assessment_categories WHERE is_active = 1 ORDER BY sort_order ASC");
    }

    /**
     * Full per-student breakdown for one subject × period.
     * Fail-open: if the weighted-engine migration is not installed yet,
     * returns an empty skeleton so sheet pages still render manual entry.
     *
     * @return array{weights:array,categories:array,students:array<string,array>,untagged:int}
     */
    public static function breakdown(int $classroomId, int $periodId): array
    {
        $empty = ['weights' => [], 'categories' => [], 'students' => [], 'untagged' => 0];
        try {
            return self::breakdownInner($classroomId, $periodId);
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    /** @return array{weights:array,categories:array,students:array<string,array>,untagged:int} */
    private static function breakdownInner(int $classroomId, int $periodId): array
    {
        $weights = self::weightsFor($classroomId);
        $majors = self::majorsFor();
        $bands = self::conversions();
        $actsId = (int) Database::value("SELECT id FROM assessment_categories WHERE code = 'activities' LIMIT 1");

        $tasks = Database::all(
            "SELECT id, category_id, max_score, title FROM classroom_tasks
              WHERE classroom_id = ? AND (period_id = ? OR period_id IS NULL)",
            [$classroomId, $periodId]
        );
        $untagged = 0;
        $taskCat = [];
        $taskMax = [];
        $taskTitle = [];
        $taskCounts = [];
        foreach ($tasks as $t) {
            $tid = (int) $t['id'];
            $cid = $t['category_id'] !== null ? (int) $t['category_id'] : 0;
            if ($cid === 0) {
                $cid = $actsId;
                $untagged++;
            }
            if (!isset($weights[$cid])) continue; // category not in this subject's weight map
            $taskCat[$tid] = $cid;
            $taskMax[$tid] = max(0.01, (float) ($t['max_score'] ?? 100));
            $taskTitle[$tid] = (string) ($t['title'] ?? ('Task #' . $tid));
            $taskCounts[$cid] = ($taskCounts[$cid] ?? 0) + 1;
        }

        $members = Database::all(
            "SELECT s.id, CONCAT(u.first_name,' ',u.last_name) AS name, s.student_no
               FROM classroom_members cm
               JOIN students s ON s.id = cm.student_id
               JOIN users u ON u.id = s.user_id
              WHERE cm.classroom_id = ? ORDER BY u.last_name ASC",
            [$classroomId]
        );
        $subs = Database::all(
            "SELECT student_id, task_id, score FROM classroom_task_submissions
              WHERE task_id IN (SELECT id FROM classroom_tasks WHERE classroom_id = ?)
                AND status = 'graded' AND score IS NOT NULL",
            [$classroomId]
        );
        $byStudentTask = [];
        foreach ($subs as $s) $byStudentTask[(int) $s['student_id']][(int) $s['task_id']] = (float) $s['score'];

        $students = [];
        foreach ($members as $m) {
            $sid = (int) $m['id'];
            $cats = [];
            $missing = 0;
            $inc = false;
            $incMissing = [];
            foreach ($taskCat as $tid => $cid) {
                if (!isset($cats[$cid])) $cats[$cid] = ['earned' => 0.0, 'possible' => 0.0];
                if (array_key_exists($tid, $byStudentTask[$sid] ?? [])) {
                    $cats[$cid]['earned'] += $byStudentTask[$sid][$tid];
                    $cats[$cid]['possible'] += $taskMax[$tid];
                } elseif (!empty($majors[$cid])) {
                    // Missing MAJOR work (exams, projects) → INCOMPLETE.
                    $inc = true;
                    $incMissing[] = ($weights[$cid]['name'] ?? 'Major') . ': ' . ($taskTitle[$tid] ?? ('Task #' . $tid));
                    $missing++;
                } else {
                    // Missing MINOR work (activities, quizzes, assignments)
                    // counts as ZERO — it stays in the denominator and deducts.
                    $cats[$cid]['possible'] += $taskMax[$tid];
                    $missing++;
                }
            }
            $wsum = 0.0;
            $wtot = 0.0;
            $catOut = [];
            foreach ($weights as $cid => $w) {
                $e = $cats[$cid]['earned'] ?? 0.0;
                $p = $cats[$cid]['possible'] ?? 0.0;
                $pct = $p > 0 ? ($e / $p) * 100 : null;
                $catOut[$cid] = ['earned' => round($e, 2), 'possible' => round($p, 2), 'pct' => $pct === null ? null : round($pct, 2)];
                if ($pct !== null) {
                    $wsum += $pct * $w['weight'];
                    $wtot += $w['weight'];
                }
            }
            // INC students get no numeric grade — completion comes later.
            $score = $inc ? null : ($wtot > 0 ? round($wsum / $wtot, 2) : null);
            $students[$sid] = [
                'name' => (string) ($m['name'] ?? ''),
                'student_no' => (string) ($m['student_no'] ?? ''),
                'cats' => $catOut,
                'score' => $score,
                'point' => $score === null ? null : self::pointFor($score, $bands),
                'missing' => $missing,
                'inc' => $inc,
                'inc_missing' => $incMissing,
            ];
        }

        // Category summary: assessments count + average % across scored students.
        $catSummary = [];
        foreach ($weights as $cid => $w) {
            $pcts = [];
            foreach ($students as $st) {
                $p = $st['cats'][$cid]['pct'] ?? null;
                if ($p !== null) $pcts[] = (float) $p;
            }
            $catSummary[$cid] = [
                'assessments' => (int) ($taskCounts[$cid] ?? 0),
                'avg_pct' => $pcts === [] ? null : round(array_sum($pcts) / count($pcts), 2),
            ];
        }

        return ['weights' => $weights, 'categories' => self::categories(), 'students' => $students, 'untagged' => $untagged, 'taskCounts' => $taskCounts, 'catSummary' => $catSummary];
    }

    /**
     * Push computed point grades into an editable sheet.
     * INC students are skipped (their remarks are stamped 'INC' instead —
     * see TeacherGradesController::applyComputed). Resolved students whose
     * stale auto-INC remark lingers get it cleared when a grade is applied.
     * @return array{applied:int,skipped_missing:int,skipped_manual:int,skipped_incomplete:int}
     */
    public static function pushToSheet(int $sheetId, bool $overwriteManual = false): array
    {
        $sheet = Database::one("SELECT * FROM grade_sheets WHERE id = ? LIMIT 1", [$sheetId]);
        if ($sheet === null) return ['applied' => 0, 'skipped_missing' => 0, 'skipped_manual' => 0, 'skipped_incomplete' => 0];
        $calc = self::breakdown((int) $sheet['classroom_id'], (int) $sheet['period_id']);
        $rows = Database::all("SELECT id, student_id, grade, source, remarks FROM grade_sheet_rows WHERE sheet_id = ?", [$sheetId]);
        $res = ['applied' => 0, 'skipped_missing' => 0, 'skipped_manual' => 0, 'skipped_incomplete' => 0];
        foreach ($rows as $r) {
            $info = $calc['students'][(int) $r['student_id']] ?? null;
            if ($info !== null && !empty($info['inc'])) {
                $res['skipped_incomplete']++;
                continue;
            }
            $point = $info['point'] ?? null;
            if ($point === null) {
                $res['skipped_missing']++;
                continue;
            }
            if (!$overwriteManual && $r['grade'] !== null && ($r['source'] ?? 'manual') === 'manual') {
                $res['skipped_manual']++;
                continue;
            }
            Database::run(
                "UPDATE grade_sheet_rows SET grade = ?, computed_grade = ?, source = 'auto', remarks = IF(remarks = 'INC', NULL, remarks) WHERE id = ?",
                [$point, $point, (int) $r['id']]
            );
            $res['applied']++;
        }
        return $res;
    }
}
