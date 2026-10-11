<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Content;
use App\Core\Database;
use Throwable;

/**
 * Announcement read model — server-side audience + schedule filtering.
 * Extends the EXISTING announcements table (never a parallel system).
 * Audience values in DB: everyone,students,parents,teachers,staff,class.
 * Admin-only virtual audiences (registrar/accounting) map to staff rows.
 */
final class Announcement
{
    public static function tableReady(): bool
    {
        try {
            return (bool) Database::value("SHOW TABLES LIKE 'announcements'");
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function hasCol(string $col): bool
    {
        try {
            return (bool) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements' AND COLUMN_NAME = ?",
                [$col]
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * ACTIVE = published + started + not expired + not archived + audience eligible.
     * Date/time rules take priority: even if status still says 'published',
     * an expired (or not-yet-started) row is NOT active. Single source of truth.
     */
    public static function isActiveRow(array $row): bool
    {
        if (($row['status'] ?? 'published') !== 'published') return false;
        $now = time();
        if (strtotime((string) ($row['publish_at'] ?? 'now')) > $now) return false;
        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) <= $now) return false;
        return true;
    }

    /** Computed display status: active|scheduled|expired|draft|archived. */
    public static function computedStatus(array $row): string
    {
        $status = (string) ($row['status'] ?? 'published');
        if ($status === 'draft') return 'draft';
        if ($status === 'archived') {
            if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) <= time()) return 'expired';
            return 'archived';
        }
        if (strtotime((string) ($row['publish_at'] ?? 'now')) > time()) return 'scheduled';
        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) <= time()) return 'expired';
        return 'active';
    }

    /** Map a viewer role to stored audience values (shared by all queries). */
    private static function dbAudiences(string $role): array
    {
        $audiences = Content::audiencesForRole($role);
        if ($audiences === []) return [];
        $dbAud = $audiences;
        if (in_array('registrar', $dbAud, true) && !in_array('staff', $dbAud, true)) $dbAud[] = 'staff';
        if (in_array('accounting', $dbAud, true) && !in_array('staff', $dbAud, true)) $dbAud[] = 'staff';
        return array_values(array_intersect($dbAud, ['everyone','students','parents','teachers','staff','class']));
    }

    /** Active = published status + within publish/expire window. */
    public static function activeFor(string $role, int $userId, int $limit = 10): array
    {
        if (!self::tableReady()) return [];
        $dbAud = self::dbAudiences($role);
        if ($dbAud === []) return [];
        $place = implode(',', array_fill(0, count($dbAud), '?'));
        $hasStatus = self::hasCol('status');
        $hasType = self::hasCol('type');
        $statusSql = $hasStatus ? "AND COALESCE(a.status,'published') = 'published'" : '';
        $typeSel = $hasType ? ', a.type' : ", 'general' AS type";
        try {
            return Database::all(
                "SELECT a.id, a.title, LEFT(a.content,160) AS excerpt, a.audience, a.publish_at, a.expires_at,
                        a.is_pinned, a.created_at{$typeSel},
                        (SELECT COUNT(*) FROM announcement_reads r WHERE r.announcement_id = a.id AND r.user_id = ?) AS is_read
                   FROM announcements a
                  WHERE a.audience IN ({$place})
                    AND a.publish_at <= NOW()
                    AND (a.expires_at IS NULL OR a.expires_at > NOW())
                    {$statusSql}
                  ORDER BY a.is_pinned DESC, a.publish_at DESC
                  LIMIT " . max(1, min(50, $limit)),
                array_merge([$userId], $dbAud)
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function unreadCount(string $role, int $userId): int
    {
        if (!self::tableReady() || $userId <= 0) return 0;
        $dbAud = self::dbAudiences($role);
        if ($dbAud === []) return 0;
        $place = implode(',', array_fill(0, count($dbAud), '?'));
        $hasStatus = self::hasCol('status');
        // announcement_reads may not exist yet (pre-migration) — fail open
        try {
            $hasReads = (bool) Database::value("SHOW TABLES LIKE 'announcement_reads'");
        } catch (Throwable $e) {
            return 0;
        }
        $notRead = $hasReads ? "AND NOT EXISTS (SELECT 1 FROM announcement_reads r WHERE r.announcement_id = a.id AND r.user_id = ?)" : '';
        $bind = $dbAud;
        if ($hasReads) $bind[] = $userId;
        try {
            return (int) Database::value(
                "SELECT COUNT(*) FROM announcements a
                  WHERE a.audience IN ({$place})
                    AND a.publish_at <= NOW()
                    AND (a.expires_at IS NULL OR a.expires_at > NOW())"
                    . ($hasStatus ? " AND COALESCE(a.status,'published') = 'published'" : '')
                    . " {$notRead}",
                $bind
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Single announcement visible to this role (IDOR-safe). */
    public static function findFor(string $role, int $id): ?array
    {
        if (!self::tableReady() || $id <= 0) return null;
        $audiences = Content::audiencesForRole($role);
        if ($audiences === [] && $role !== 'admin') return null;
        try {
            $row = Database::one("SELECT a.*, CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS author_name FROM announcements a LEFT JOIN users u ON u.id = a.posted_by WHERE a.id = ? LIMIT 1", [$id]);
            if ($row === null) return null;
            if ($role === 'admin') return $row;
            $dbAud = self::dbAudiences($role);
            if (!in_array((string) ($row['audience'] ?? ''), $dbAud, true)) return null;
            // schedule gate for non-admins
            if (strtotime((string) ($row['publish_at'] ?? 'now')) > time()) return null;
            if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) <= time()) return null;
            if (self::hasCol('status') && ($row['status'] ?? 'published') !== 'published') return null;
            return $row;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function markRead(int $annId, int $userId): void
    {
        try {
            $has = (bool) Database::value("SHOW TABLES LIKE 'announcement_reads'");
            if (!$has || $annId <= 0 || $userId <= 0) return;
            Database::run(
                "INSERT IGNORE INTO announcement_reads (announcement_id, user_id) VALUES (?, ?)",
                [$annId, $userId]
            );
        } catch (Throwable $e) {
        }
    }

    public static function counts(): array
    {
        $out = ['active' => 0, 'scheduled' => 0, 'expired' => 0, 'draft' => 0, 'expiring_soon' => 0, 'history' => 0];
        if (!self::tableReady()) return $out;
        try {
            $hasStatus = self::hasCol('status');
            $st = $hasStatus ? "COALESCE(status,'published')" : "'published'";
            $out['active'] = (int) Database::value(
                "SELECT COUNT(*) FROM announcements WHERE publish_at <= NOW() AND (expires_at IS NULL OR expires_at > NOW()) AND {$st} = 'published'"
            );
            // Scheduled = not yet started (drafts/archived excluded)
            $out['scheduled'] = (int) Database::value(
                "SELECT COUNT(*) FROM announcements WHERE publish_at > NOW() AND {$st} IN ('scheduled','published')"
            );
            // Expired = past expiry (drafts excluded — never published)
            $out['expired'] = (int) Database::value(
                "SELECT COUNT(*) FROM announcements WHERE expires_at IS NOT NULL AND expires_at <= NOW() AND {$st} != 'draft'"
            );
            if ($hasStatus) $out['draft'] = (int) Database::value("SELECT COUNT(*) FROM announcements WHERE status = 'draft'");
            // Expiring soon = still active but expires within 3 days
            $out['expiring_soon'] = (int) Database::value(
                "SELECT COUNT(*) FROM announcements WHERE publish_at <= NOW() AND expires_at IS NOT NULL AND expires_at > NOW() AND expires_at <= DATE_ADD(NOW(), INTERVAL 3 DAY) AND {$st} = 'published'"
            );
            // History = everything no longer on dashboards (expired or archived)
            $out['history'] = (int) Database::value(
                "SELECT COUNT(*) FROM announcements WHERE (expires_at IS NOT NULL AND expires_at <= NOW() AND {$st} != 'draft') OR {$st} = 'archived'"
            );
        } catch (Throwable $e) {
        }
        return $out;
    }

    /** Active announcements expiring within $days (admin "expiring soon" widget). */
    public static function expiringSoon(int $days = 3, int $limit = 10): array
    {
        if (!self::tableReady()) return [];
        $days = max(1, min(30, $days));
        try {
            $hasStatus = self::hasCol('status');
            $hasType = self::hasCol('type');
            $statusSql = $hasStatus ? "AND COALESCE(status,'published') = 'published'" : '';
            $typeSel = $hasType ? ', type' : ", 'general' AS type";
            return Database::all(
                "SELECT id, title, audience, publish_at, expires_at, is_pinned, created_at{$typeSel}
                   FROM announcements
                  WHERE publish_at <= NOW() AND expires_at IS NOT NULL
                    AND expires_at > NOW() AND expires_at <= DATE_ADD(NOW(), INTERVAL {$days} DAY)
                    {$statusSql}
                  ORDER BY expires_at ASC LIMIT " . max(1, min(50, $limit))
            );
        } catch (Throwable $e) {
            return [];
        }
    }
}
