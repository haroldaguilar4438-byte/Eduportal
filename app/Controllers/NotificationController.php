<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use Throwable;

/**
 * Site-wide in-app notifications (visible on every page via the bell).
 * Table `notifications` already exists; all helpers fail open so a missing
 * table never breaks page rendering.
 */
final class NotificationController extends Controller
{
    public static function unreadCountFor(int $userId): int
    {
        if ($userId <= 0) return 0;
        try {
            return (int) Database::value(
                "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0",
                [$userId]
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function recentFor(int $userId, int $limit = 8): array
    {
        if ($userId <= 0) return [];
        try {
            return Database::all(
                "SELECT id, title, message, type, link_url, is_read, created_at
                   FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT " . max(1, min(20, $limit)),
                [$userId]
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Batched fan-out: one multi-row INSERT per chunk instead of N round
     * trips (announcement/task blasts). Same rows as push(), never throws.
     * @param int[] $userIds
     */
    public static function pushMany(array $userIds, string $title, string $message = '', string $type = 'info', string $link = '/dashboard'): int
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        $ids = array_values(array_filter($ids, static fn($i) => $i > 0));
        if ($ids === [] || trim($title) === '') return 0;
        $title = mb_substr($title, 0, 150);
        $message = $message !== '' ? $message : null;
        $type = $type !== '' ? mb_substr($type, 0, 30) : 'info';
        $link = $link !== '' ? mb_substr($link, 0, 255) : null;
        $sent = 0;
        try {
            foreach (array_chunk($ids, 200) as $chunk) {
                $place = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?)'));
                $bind = [];
                foreach ($chunk as $i) array_push($bind, $i, $title, $message, $type, $link);
                Database::run(
                    "INSERT INTO notifications (user_id, title, message, type, link_url) VALUES {$place}",
                    $bind
                );
                $sent += count($chunk);
            }
        } catch (Throwable $e) {
        }
        return $sent;
    }

    /** Fire-and-forget single push (never throws). */
    public static function push(int $userId, string $title, string $message = '', string $type = 'info', string $link = '/dashboard'): void
    {
        if ($userId <= 0 || trim($title) === '') return;
        try {
            Database::insert('notifications', [
                'user_id' => $userId, 'title' => mb_substr($title, 0, 150),
                'message' => $message !== '' ? $message : null,
                'type' => $type !== '' ? mb_substr($type, 0, 30) : 'info',
                'link_url' => $link !== '' ? mb_substr($link, 0, 255) : null,
            ]);
        } catch (Throwable $e) {
        }
    }

    /** Push to every active user with a role (capped to avoid fan-out storms). */
    public static function pushToRole(string $role, string $title, string $message = '', string $type = 'info', string $link = '/dashboard', int $cap = 500): void
    {
        if (trim($title) === '') return;
        try {
            $rows = Database::all(
                "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = ? AND u.status = 'active' ORDER BY u.id DESC LIMIT " . max(1, min(2000, $cap)),
                [$role]
            );
            foreach ($rows as $r) self::push((int) $r['id'], $title, $message, $type, $link);
        } catch (Throwable $e) {
        }
    }

    public static function pushToAdmins(string $title, string $message = '', string $type = 'info', string $link = '/dashboard'): void
    {
        self::pushToRole('admin', $title, $message, $type, $link);
    }

    public static function pushToStudents(string $title, string $message = '', string $type = 'info', string $link = '/dashboard', int $cap = 500): void
    {
        self::pushToRole('student', $title, $message, $type, $link, $cap);
    }

    public static function pushToTeachers(string $title, string $message = '', string $type = 'info', string $link = '/dashboard', int $cap = 200): void
    {
        self::pushToRole('teacher', $title, $message, $type, $link, $cap);
    }

    public static function pushToRegistrars(string $title, string $message = '', string $type = 'info', string $link = '/registrar/permits', int $cap = 200): void
    {
        self::pushToRole('registrar', $title, $message, $type, $link, $cap);
    }

    public static function pushToAccounting(string $title, string $message = '', string $type = 'info', string $link = '/accounting/enrollments', int $cap = 200): void
    {
        self::pushToRole('accounting', $title, $message, $type, $link, $cap);
    }

    /* ------------------------------- pages ------------------------------- */

    public function index(array $params = []): void
    {
        Auth::requireRole('admin', 'teacher', 'student', 'parent', 'registrar', 'accounting');
        $uid = (int) Auth::id();
        $filter = trim((string) ($_GET['filter'] ?? 'all'));
        if (!in_array($filter, ['all', 'unread'], true)) $filter = 'all';
        $rows = [];
        try {
            $rows = Database::all(
                "SELECT id, title, message, type, link_url, is_read, created_at FROM notifications
                  WHERE user_id = ?" . ($filter === 'unread' ? " AND is_read = 0" : "") . " ORDER BY created_at DESC LIMIT 100",
                [$uid]
            );
        } catch (Throwable $e) {
        }
        $this->view('notifications/index', [
            'title' => 'Notifications', 'rows' => $rows, 'filter' => $filter,
            'unread' => self::unreadCountFor($uid),
        ]);
    }

    /**
     * JSON feed for live updates (no layout). Incremental: ?since_id= returns
     * only newer items plus the current max id, so clients deduplicate.
     * Strictly per-user (the caller's own rows) — the event model for EduLive.
     */
    public function feed(array $params = []): void
    {
        if (!Auth::check()) json_response(['unread' => 0, 'items' => [], 'max_id' => 0, 'server_time' => time()]);
        $uid = (int) Auth::id();
        $sinceId = max(0, (int) ($_GET['since_id'] ?? 0));
        json_response(self::feedFor($uid, $sinceId));
    }

    /** Pure feed builder (unit-testable; same source as page loads). */
    public static function feedFor(int $userId, int $sinceId = 0, int $limit = 8): array
    {
        if ($userId <= 0) return ['unread' => 0, 'items' => [], 'max_id' => $sinceId, 'server_time' => time()];
        try {
            $maxId = (int) (Database::value(
                "SELECT COALESCE(MAX(id),0) FROM notifications WHERE user_id = ?",
                [$userId]
            ) ?? 0);
        } catch (Throwable $e) {
            return ['unread' => 0, 'items' => [], 'max_id' => $sinceId, 'server_time' => time()];
        }
        $items = [];
        try {
            $rows = Database::all(
                "SELECT id, title, message, type, link_url, is_read, created_at FROM notifications
                  WHERE user_id = ?" . ($sinceId > 0 ? " AND id > ?" : "") . " ORDER BY id DESC LIMIT " . max(1, min(20, $limit)),
                $sinceId > 0 ? [$userId, $sinceId] : [$userId]
            );
            foreach ($rows as $n) {
                $items[] = [
                    'id' => (int) $n['id'], 'title' => $n['title'], 'type' => $n['type'],
                    'message' => isset($n['message']) ? mb_substr((string) $n['message'], 0, 140) : '',
                    'is_read' => (int) $n['is_read'], 'created_at' => $n['created_at'],
                    'link' => $n['link_url'] ?? '/notifications',
                ];
            }
        } catch (Throwable $e) {
        }
        return [
            'unread' => self::unreadCountFor($userId),
            'items' => $items,
            'max_id' => $maxId,
            'server_time' => time(),
        ];
    }

    public function markRead(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin', 'teacher', 'student', 'parent', 'registrar', 'accounting');
        $id = (int) ($params['id'] ?? 0);
        try {
            Database::run(
                "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?",
                [$id, (int) Auth::id()]
            );
        } catch (Throwable $e) {
        }
        $to = trim((string) ($_POST['redirect'] ?? '/notifications'));
        if ($to === '' || $to[0] !== '/') $to = '/notifications';
        redirect($to);
    }

    public function markAllRead(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin', 'teacher', 'student', 'parent', 'registrar', 'accounting');
        try {
            Database::run(
                "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0",
                [(int) Auth::id()]
            );
        } catch (Throwable $e) {
        }
        $to = trim((string) ($_POST['redirect'] ?? '/notifications'));
        if ($to === '' || $to[0] !== '/') $to = '/notifications';
        redirect($to);
    }
}
