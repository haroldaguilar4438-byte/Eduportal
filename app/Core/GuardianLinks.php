<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Parent ↔ child linking (guardians + guardian_students).
 *
 * Root cause it fixes: filing an enrollment application never linked the
 * filer to the child — approval sets students.user_id to the applicant and
 * stops, so parent portals showed "No children linked" forever with no UI
 * to perform the promised "registrar links" step.
 *
 * Rule: when a PARENT-role account's enrollment application reaches final
 * approval, the filer is linked to the resulting student record as their
 * legal guardian. Student-filed applications are never touched.
 * Idempotent: safe to run repeatedly (unique keys guarded, duplicates
 * swallowed); every new link is audit-logged.
 */
final class GuardianLinks
{
    /**
     * Link the filing parent of an approved application to its student.
     * @return bool true when a link exists afterwards (created or pre-existing)
     */
    public static function ensureForApplication(int $appId, ?int $actorId = null): bool
    {
        try {
            $app = Database::one(
                "SELECT id, user_id, student_id, status FROM enrollment_applications WHERE id = ? LIMIT 1",
                [$appId]
            );
            if ($app === null || empty($app['user_id']) || empty($app['student_id'])) return false;
            if (($app['status'] ?? '') !== 'approved') return false;

            $role = Database::one(
                "SELECT r.name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1",
                [(int) $app['user_id']]
            );
            if ($role === null || ($role['name'] ?? '') !== 'parent') return false;

            return self::ensureLink((int) $app['user_id'], (int) $app['student_id'], $actorId);
        } catch (Throwable $e) {
            storage_log('guardian auto-link failed for app ' . $appId . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Link a parent user to a student record (creates the guardian profile
     * from the user's own names when missing).
     */
    public static function ensureLink(int $parentUserId, int $studentId, ?int $actorId = null): bool
    {
        try {
            if ($parentUserId <= 0 || $studentId <= 0) return false;
            $stu = Database::one("SELECT id FROM students WHERE id = ? LIMIT 1", [$studentId]);
            if ($stu === null) return false;

            $g = Database::one("SELECT * FROM guardians WHERE user_id = ? LIMIT 1", [$parentUserId]);
            if ($g === null) {
                $u = Database::one(
                    "SELECT first_name, middle_name, last_name, email, phone FROM users WHERE id = ? LIMIT 1",
                    [$parentUserId]
                );
                if ($u === null) return false;
                try {
                    $gid = Database::insert('guardians', [
                        'user_id' => $parentUserId,
                        'first_name' => trim((string) ($u['first_name'] ?? '')) !== '' ? $u['first_name'] : 'Guardian',
                        'middle_name' => $u['middle_name'] ?? null,
                        'last_name' => trim((string) ($u['last_name'] ?? '')) !== '' ? $u['last_name'] : '(parent)',
                        'contact_no' => $u['phone'] ?? null,
                        'email' => $u['email'] ?? null,
                        'is_emergency_contact' => 1,
                    ]);
                } catch (Throwable $e) {
                    // Race: another request created it first.
                    $g = Database::one("SELECT * FROM guardians WHERE user_id = ? LIMIT 1", [$parentUserId]);
                    if ($g === null) return false;
                    $gid = (int) $g['id'];
                }
                $g = Database::one("SELECT * FROM guardians WHERE id = ? LIMIT 1", [(int) $gid]);
                if ($g === null) return false;
            }
            $gid = (int) $g['id'];

            $exists = Database::one(
                "SELECT relationship FROM guardian_students WHERE guardian_id = ? AND student_id = ? LIMIT 1",
                [$gid, $studentId]
            );
            if ($exists !== null) return true;

            $hasPrimary = (bool) Database::one(
                "SELECT 1 FROM guardian_students WHERE guardian_id = ? AND is_primary = 1 LIMIT 1",
                [$gid]
            );
            try {
                Database::insert('guardian_students', [
                    'guardian_id' => $gid, 'student_id' => $studentId,
                    'relationship' => 'legal_guardian', 'is_primary' => $hasPrimary ? 0 : 1,
                ]);
            } catch (Throwable $e) {
                // Duplicate key race — the link exists now.
                $exists = Database::one(
                    "SELECT relationship FROM guardian_students WHERE guardian_id = ? AND student_id = ? LIMIT 1",
                    [$gid, $studentId]
                );
                if ($exists === null) {
                    storage_log('guardian link insert failed g=' . $gid . ' s=' . $studentId . ': ' . $e->getMessage());
                    return false;
                }
            }
            try {
                Database::insert('audit_logs', [
                    'actor_user_id' => $actorId, 'action' => 'guardian.linked',
                    'entity_type' => 'students', 'entity_id' => $studentId,
                    'new_values_json' => json_encode(['guardian_id' => $gid, 'via' => 'enrollment_approval']),
                    'ip_address' => client_ip(),
                ]);
            } catch (Throwable $e) {
            }
            return true;
        } catch (Throwable $e) {
            storage_log('guardian ensureLink failed u=' . $parentUserId . ' s=' . $studentId . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Whether students carries its own identity columns (parent-filed
     * children have no users row; NULL user_id). Static-cached.
     */
    private static ?bool $hasNameCols = null;

    public static function hasNameCols(): bool
    {
        if (self::$hasNameCols === null) {
            try {
                self::$hasNameCols = (bool) Database::value(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'first_name'"
                );
            } catch (Throwable $e) {
                self::$hasNameCols = false;
            }
        }
        return self::$hasNameCols;
    }

    /**
     * Name select fragment: linked users row wins when present, else the
     * students-row identity (parent-filed children). Place AFTER s.* so the
     * COALESCE aliases win over either table's raw columns.
     */
    public static function nameSelect(string $sAlias = 's', string $uAlias = 'u'): string
    {
        if (!self::hasNameCols()) {
            return "{$uAlias}.first_name, {$uAlias}.last_name";
        }
        return "COALESCE({$uAlias}.first_name, {$sAlias}.first_name) AS first_name,"
            . " COALESCE({$uAlias}.middle_name, {$sAlias}.middle_name) AS middle_name,"
            . " COALESCE({$uAlias}.last_name, {$sAlias}.last_name) AS last_name,"
            . " COALESCE({$uAlias}.suffix, {$sAlias}.suffix) AS suffix";
    }

    /**
     * Owned linked child for request flows: ?child_id= validated against
     * guardian_students (tampering yields null), else the first linked child.
     * @return array|null student row (with first_name/last_name when known)
     */
    public static function ownedChild(int $parentUserId, int $childId = 0): ?array
    {
        try {
            $g = Database::one("SELECT id FROM guardians WHERE user_id = ? LIMIT 1", [$parentUserId]);
            if ($g === null) return null;
            if ($childId > 0) {
                return Database::one(
                    'SELECT s.*, ' . self::nameSelect() . ' FROM guardian_students gs JOIN students s ON s.id = gs.student_id LEFT JOIN users u ON u.id = s.user_id WHERE gs.guardian_id = ? AND s.id = ? LIMIT 1',
                    [(int) $g['id'], $childId]
                );
            }
            return Database::one(
                'SELECT s.*, ' . self::nameSelect() . ' FROM guardian_students gs JOIN students s ON s.id = gs.student_id LEFT JOIN users u ON u.id = s.user_id WHERE gs.guardian_id = ? ORDER BY s.id LIMIT 1',
                [(int) $g['id']]
            );
        } catch (Throwable $e) {
            return null;
        }
    }

    /** All linked children for pickers (id, number, names, level). */
    public static function linkedChildren(int $parentUserId): array
    {
        try {
            $g = Database::one("SELECT id FROM guardians WHERE user_id = ? LIMIT 1", [$parentUserId]);
            if ($g === null) return [];
            return Database::all(
                'SELECT s.id, s.student_no, s.status, s.grade_level_id, s.date_of_birth, ' . self::nameSelect() . ', gl.name AS level_name
                   FROM guardian_students gs JOIN students s ON s.id = gs.student_id
                   LEFT JOIN users u ON u.id = s.user_id
                   LEFT JOIN grade_levels gl ON gl.id = s.grade_level_id
                  WHERE gs.guardian_id = ? ORDER BY s.id LIMIT 20',
                [(int) $g['id']]
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function displayName(array $student): string
    {
        $parts = [
            trim((string) ($student['first_name'] ?? '')),
            trim((string) ($student['middle_name'] ?? '')),
            trim((string) ($student['last_name'] ?? '')),
            trim((string) ($student['suffix'] ?? '')),
        ];
        $full = trim(implode(' ', array_filter($parts)));
        return $full !== '' ? $full : ('Student #' . ($student['id'] ?? 0));
    }

    /**
     * Child behind an enrollment application (tagged row, else linked
     * student), for naming parent-facing notifications. Null when unknown.
     */
    public static function applicantChildName(array $app): ?string
    {
        try {
            $sid = 0;
            if (!empty($app['applicant_child_id'])) {
                $sid = (int) $app['applicant_child_id'];
            } elseif (!empty($app['student_id'])) {
                $sid = (int) $app['student_id'];
            }
            if ($sid <= 0) return null;
            $row = Database::one("SELECT * FROM students WHERE id = ? LIMIT 1", [$sid]);
            if ($row === null) return null;
            if (!empty($row['user_id'])) {
                $u = Database::one("SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1", [(int) $row['user_id']]);
                if ($u !== null && trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? '')) !== '') {
                    return trim((string) $u['first_name'] . ' ' . (string) $u['last_name']);
                }
            }
            if (self::hasNameCols()) {
                $n = self::displayName($row);
                if (!str_starts_with($n, 'Student #')) return $n;
            }
            return null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Repair historical cases: link every approved parent-filed application
     * whose student still isn't linked to the filer. Returns links created.
     */
    public static function repairForParent(int $parentUserId, ?int $actorId = null): int
    {
        $fixed = 0;
        try {
            $role = Database::one(
                "SELECT r.name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1",
                [$parentUserId]
            );
            if ($role === null || ($role['name'] ?? '') !== 'parent') return 0;
            $apps = Database::all(
                "SELECT id, student_id FROM enrollment_applications
                  WHERE user_id = ? AND status = 'approved' AND student_id IS NOT NULL
                  ORDER BY id ASC LIMIT 50",
                [$parentUserId]
            );
            foreach ($apps as $a) {
                $linked = Database::one(
                    "SELECT 1 FROM guardian_students gs JOIN guardians g ON g.id = gs.guardian_id
                      WHERE g.user_id = ? AND gs.student_id = ? LIMIT 1",
                    [$parentUserId, (int) $a['student_id']]
                );
                if ($linked === null && self::ensureForApplication((int) $a['id'], $actorId)) {
                    $fixed++;
                }
            }
        } catch (Throwable $e) {
            storage_log('guardian repair failed u=' . $parentUserId . ': ' . $e->getMessage());
        }
        return $fixed;
    }
}
