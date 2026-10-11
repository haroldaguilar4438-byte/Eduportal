<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Content;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use App\Models\Announcement;
use Throwable;

/**
 * Announcements for logged-in users.
 * Server-side audience + schedule filtering (never JS-only hiding).
 * Read state stored in announcement_reads (notifications stay separate).
 */
final class AnnouncementController extends Controller
{
    public function index(array $params = []): void
    {
        Auth::requireRole('admin', 'teacher', 'student', 'parent', 'registrar', 'accounting');
        $role = (string) (Auth::role() ?? '');
        $uid = (int) Auth::id();
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $type = mb_substr(trim((string) ($_GET['type'] ?? '')), 0, 40);
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $per = 12;
        $off = ($page - 1) * $per;

        $rows = Announcement::activeFor($role, $uid, 200);
        // History (expired but visible) on demand
        $showHistory = (($_GET['history'] ?? '') === '1');
        if ($showHistory) {
            $rows = array_merge($rows, $this->historyFor($role, $uid));
        }
        if ($q !== '') {
            $rows = array_values(array_filter($rows, static fn($r) =>
                stripos((string) ($r['title'] ?? ''), $q) !== false
                || stripos((string) ($r['excerpt'] ?? ''), $q) !== false));
        }
        if ($type !== '' && isset(Content::announcementTypes()[$type])) {
            $rows = array_values(array_filter($rows, static fn($r) => (string) ($r['type'] ?? 'general') === $type));
        }
        $total = count($rows);
        $rows = array_slice($rows, $off, $per);

        // AJAX fragment for filter without full reload
        if ((($_GET['partial'] ?? '') === 'list')) {
            $this->view('announcements/_list', [
                'rows' => $rows, 'total' => $total, 'page' => $page,
                'pages' => max(1, (int) ceil($total / $per)), 'q' => $q, 'type' => $type,
            ], null);
            return;
        }
        $this->view('announcements/index', [
            'title' => 'Announcements',
            'rows' => $rows, 'total' => $total, 'page' => $page,
            'pages' => max(1, (int) ceil($total / $per)),
            'q' => $q, 'type' => $type, 'types' => Content::announcementTypes(),
            'unread' => Announcement::unreadCount($role, $uid),
            'history' => $showHistory,
        ]);
    }

    public function show(array $params = []): void
    {
        Auth::requireRole('admin', 'teacher', 'student', 'parent', 'registrar', 'accounting');
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(404);
            set_flash('error', 'Announcement not found.');
            redirect('/announcements');
        }
        $row = Announcement::findFor((string) (Auth::role() ?? ''), $id);
        if ($row === null) {
            http_response_code(403);
            set_flash('error', 'You are not allowed to view this announcement.');
            redirect('/announcements');
        }
        Announcement::markRead($id, (int) Auth::id());
        $this->view('announcements/show', [
            'title' => (string) ($row['title'] ?? 'Announcement'),
            'row' => $row, 'types' => Content::announcementTypes(),
        ]);
    }

    /** Mark single announcement read (fetch, CSRF-guarded). */
    public function markRead(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin', 'teacher', 'student', 'parent', 'registrar', 'accounting');
        $id = (int) ($params['id'] ?? 0);
        $row = Announcement::findFor((string) (Auth::role() ?? ''), $id);
        if ($row === null) {
            $this->json(['ok' => false, 'error' => 'Not allowed.'], 403);
            return;
        }
        Announcement::markRead($id, (int) Auth::id());
        $this->json(['ok' => true]);
    }

    /** JSON for EduLive refresh pills (counts only, no cross-user leak). */
    public function feed(array $params = []): void
    {
        if (!Auth::check()) {
            $this->json(['unread' => 0, 'items' => []]);
        }
        $role = (string) (Auth::role() ?? '');
        $uid = (int) Auth::id();
        $rows = Announcement::activeFor($role, $uid, 5);
        $this->json([
            'unread' => Announcement::unreadCount($role, $uid),
            'items' => array_map(static fn($r) => [
                'id' => (int) $r['id'], 'title' => $r['title'],
                'is_read' => (int) ($r['is_read'] ?? 0),
                'url' => url('/announcements/' . (int) $r['id']),
            ], $rows),
        ]);
    }

    /** Expired but preserved records (never auto-deleted). */
    private function historyFor(string $role, int $uid): array
    {
        $audiences = Content::audiencesForRole($role);
        $dbAud = $audiences;
        if (in_array('registrar', $dbAud, true) && !in_array('staff', $dbAud, true)) $dbAud[] = 'staff';
        if (in_array('accounting', $dbAud, true) && !in_array('staff', $dbAud, true)) $dbAud[] = 'staff';
        $dbAud = array_values(array_intersect($dbAud, ['everyone','students','parents','teachers','staff','class']));
        if ($dbAud === []) return [];
        $place = implode(',', array_fill(0, count($dbAud), '?'));
        try {
            $hasStatus = (bool) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements' AND COLUMN_NAME = 'status'"
            );
            $hasType = (bool) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements' AND COLUMN_NAME = 'type'"
            );
            $typeSel = $hasType ? ', a.type' : ", 'general' AS type";
            $statusSql = $hasStatus ? "AND COALESCE(a.status,'published') IN ('published','archived')" : '';
            return Database::all(
                "SELECT a.id, a.title, LEFT(a.content,160) AS excerpt, a.audience, a.publish_at, a.expires_at,
                        a.is_pinned, a.created_at{$typeSel}, 1 AS is_read, 1 AS is_history
                   FROM announcements a
                  WHERE a.audience IN ({$place}) AND a.expires_at IS NOT NULL AND a.expires_at <= NOW()
                    AND a.publish_at <= NOW() {$statusSql}
                  ORDER BY a.expires_at DESC LIMIT 50",
                $dbAud
            );
        } catch (Throwable $e) {
            return [];
        }
    }
}
