<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Centralized academic-period system (single source of truth).
 *
 * Education bands come from the EXISTING grade_levels.category — no duplicate
 * level definitions:
 *   basic   <- kindergarten, elementary, junior_high (Quarter / Term-Trimestre per config)
 *   shs     <- senior_high (1st / 2nd Semester)
 *   college <- college (existing 1st / 2nd / Summer workflow, untouched)
 *
 * Period LISTS come from the `academic_periods` table (Admin-configurable);
 * hardcoded defaults below are a fail-open fallback only.
 *
 * Storage convention (history-preserving):
 *   - SHS/College keep using the legacy `semester` columns ('1st','2nd','Summer').
 *   - Basic-ed uses the new `period_code` columns ('Q1'..'Q4' / 'T1'..'T3' / custom).
 *   - effectivePeriod($row) = period_code ?? semester — one resolver for all readers.
 * Legacy rows with no period info are NEVER reinterpreted (raw value shown as-is).
 */
final class AcademicPeriod
{
    public const BAND_BASIC = 'basic';
    public const BAND_SHS = 'shs';
    public const BAND_COLLEGE = 'college';

    /** Fail-open defaults used only when the academic_periods table is missing. */
    private const DEFAULTS = [
        'basic' => [
            ['code' => 'Q1', 'name' => 'Quarter 1'],
            ['code' => 'Q2', 'name' => 'Quarter 2'],
            ['code' => 'Q3', 'name' => 'Quarter 3'],
            ['code' => 'Q4', 'name' => 'Quarter 4'],
        ],
        'shs' => [
            ['code' => '1st', 'name' => '1st Semester'],
            ['code' => '2nd', 'name' => '2nd Semester'],
        ],
        'college' => [
            ['code' => '1st', 'name' => '1st Semester'],
            ['code' => '2nd', 'name' => '2nd Semester'],
            ['code' => 'Summer', 'name' => 'Summer'],
        ],
    ];

    private static ?bool $hasTable = null;

    public static function resetCache(): void
    {
        self::$hasTable = null;
    }

    /* ================= classification ================= */

    public static function bandForCategory(?string $category): string
    {
        return match ($category) {
            'kindergarten', 'elementary', 'junior_high' => self::BAND_BASIC,
            'senior_high' => self::BAND_SHS,
            default => self::BAND_COLLEGE,
        };
    }

    public static function bandForLevel(int $levelId): string
    {
        try {
            $r = Database::one('SELECT category FROM grade_levels WHERE id = ? LIMIT 1', [$levelId]);
            if ($r) return self::bandForCategory($r['category'] ?? null);
        } catch (Throwable $e) {
        }
        return self::BAND_COLLEGE;
    }

    public static function bandLabel(string $band): string
    {
        return match ($band) {
            self::BAND_BASIC => 'Basic Education',
            self::BAND_SHS => 'Senior High School',
            default => 'College',
        };
    }

    /* ================= configuration ================= */

    public static function hasTable(): bool
    {
        if (self::$hasTable !== null) return self::$hasTable;
        try {
            self::$hasTable = Database::value("SHOW TABLES LIKE 'academic_periods'") ? true : false;
        } catch (Throwable $e) {
            self::$hasTable = false;
        }
        return self::$hasTable;
    }

    /** 'quarter' | 'trimester' — controls the basic-ed calendar. Admin-settable. */
    public static function basicStructure(): string
    {
        try {
            $v = Database::value("SELECT setting_value FROM system_settings WHERE setting_key = 'basic_structure' LIMIT 1");
            if ($v === 'trimester') return 'trimester';
        } catch (Throwable $e) {
        }
        return 'quarter';
    }

    /**
     * Active periods for a band, ordered. Each: ['code'=>..., 'name'=>...].
     * Basic-ed set follows the configured structure (quarters vs trimesters);
     * custom Admin-added rows (any code) are always included when active.
     */
    public static function periodsFor(string $band): array
    {
        $defaults = self::DEFAULTS[$band] ?? self::DEFAULTS[self::BAND_COLLEGE];
        if (!self::hasTable()) {
            if ($band === self::BAND_BASIC && self::basicStructure() === 'trimester') {
                return [
                    ['code' => 'T1', 'name' => 'Term 1'],
                    ['code' => 'T2', 'name' => 'Term 2'],
                    ['code' => 'T3', 'name' => 'Term 3'],
                ];
            }
            return $defaults;
        }
        try {
            $rows = Database::all(
                "SELECT code, name FROM academic_periods WHERE band = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC",
                [$band]
            );
            if ($rows === []) return $defaults;
            // Basic-ed: only the configured structure's seeded rows + custom rows.
            if ($band === self::BAND_BASIC) {
                $struct = self::basicStructure();
                $want = $struct === 'trimester' ? 'T' : 'Q';
                $filtered = [];
                foreach ($rows as $r) {
                    $code = (string) ($r['code'] ?? '');
                    if ($code === '') continue;
                    if (str_starts_with($code, 'Q') || str_starts_with($code, 'T')) {
                        if (!str_starts_with($code, $want)) continue;
                    }
                    $filtered[] = ['code' => $code, 'name' => (string) ($r['name'] ?? $code)];
                }
                if ($filtered !== []) return $filtered;
            }
            return array_map(
                static fn($r) => ['code' => (string) $r['code'], 'name' => (string) ($r['name'] ?? $r['code'])],
                array_filter($rows, static fn($r) => (string) ($r['code'] ?? '') !== '')
            ) ?: $defaults;
        } catch (Throwable $e) {
            return $defaults;
        }
    }

    /** All known codes for a band (active + inactive) — for Admin screens. */
    public static function allPeriods(string $band): array
    {
        if (!self::hasTable()) return self::periodsFor($band);
        try {
            return Database::all(
                "SELECT * FROM academic_periods WHERE band = ? ORDER BY sort_order ASC, id ASC",
                [$band]
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function periodCodes(string $band): array
    {
        return array_map(static fn($p) => $p['code'], self::periodsFor($band));
    }

    public static function isValid(string $band, string $code): bool
    {
        $code = trim($code);
        if ($code === '') return false;
        return in_array($code, self::periodCodes($band), true);
    }

    public static function firstPeriod(string $band): string
    {
        $codes = self::periodCodes($band);
        return $codes[0] ?? '1st';
    }

    public static function nextPeriodCode(string $band, string $code): ?string
    {
        $codes = self::periodCodes($band);
        $i = array_search(trim($code), $codes, true);
        if ($i === false || !isset($codes[$i + 1])) return null;
        return $codes[$i + 1];
    }

    /** Periods up to and including $code (for cumulative checks). */
    public static function uptoPeriod(string $band, string $code): array
    {
        $out = [];
        foreach (self::periodCodes($band) as $c) {
            $out[] = $c;
            if ($c === trim($code)) break;
        }
        return $out === [] ? [self::firstPeriod($band)] : $out;
    }

    /* ================= labels (never hardcode Semester/Quarter in callers) ================= */

    /** One period's display name: "Quarter 1", "Term 2", "1st Semester". Raw code when unknown (no reinterpretation). */
    public static function periodName(string $band, string $code): string
    {
        $code = trim($code);
        if ($code === '') return '—';
        foreach (self::periodsFor($band) as $p) {
            if ($p['code'] === $code) return $p['name'];
        }
        // Legacy/unconfigured value: prettify semesters, otherwise show raw.
        if (in_array($code, ['1st', '2nd'], true)) return $code . ' Semester';
        return $code;
    }

    /** The noun for a band: Quarter | Term | Semester. */
    public static function noun(string $band, ?string $code = null): string
    {
        if ($band === self::BAND_BASIC) {
            if ($code !== null) {
                if (str_starts_with(trim($code), 'T')) return 'Term';
                if (str_starts_with(trim($code), 'Q')) return 'Quarter';
            }
            return self::basicStructure() === 'trimester' ? 'Term' : 'Quarter';
        }
        if ($band === self::BAND_SHS || $band === self::BAND_COLLEGE) return 'Semester';
        return 'Period';
    }

    public static function label(string $band, string $code): string
    {
        return self::periodName($band, $code);
    }

    /** One resolver for every reader: new period_code wins, else legacy semester. */
    public static function effectivePeriod(array $row): string
    {
        $p = trim((string) ($row['period_code'] ?? ''));
        if ($p !== '') return $p;
        return trim((string) ($row['semester'] ?? ''));
    }

    /** Full "Grade 8 · Quarter 1 (2026-2027)" style context string. */
    public static function contextLabel(string $band, string $levelName, string $period, string $ayLabel): string
    {
        $bits = [];
        if ($levelName !== '') $bits[] = $levelName;
        if ($period !== '') $bits[] = self::periodName($band, $period);
        if ($ayLabel !== '') $bits[] = $ayLabel;
        return implode(' · ', $bits);
    }
}
