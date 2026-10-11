<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\NotificationController;
use App\Core\Auth;
use App\Core\Content;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use App\Models\Announcement;
use Throwable;

/**
 * Admin Announcement management (extends the EXISTING announcements table).
 * Supports draft/preview/schedule/publish/archive + pinned + attachments.
 * Publishing fans out to the EXISTING notifications table (no duplicate system).
 */
final class AnnouncementController extends Controller
{
    private function ensureTable(): bool
    {
        if (Announcement::tableReady()) return true;
        set_flash('error', 'Announcements table is missing.');
        redirect('/dashboard');
        return false;
    }

    public function index(array $params = []): void
    {
        Auth::requireRole('admin');
        $this->ensureTable();
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $status = trim((string) ($_GET['status'] ?? ''));
        $type = trim((string) ($_GET['type'] ?? ''));
        $aud = trim((string) ($_GET['audience'] ?? ''));
        // Management tabs: active | scheduled | history | all (default all)
        $view = trim((string) ($_GET['view'] ?? 'all'));
        if (!in_array($view, ['active','scheduled','history','all'], true)) $view = 'all';
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $per = 15;
        $off = ($page - 1) * $per;

        $hasStatus = $this->hasCol('status');
        $hasType = $this->hasCol('type');
        $st = $hasStatus ? "COALESCE(a.status,'published')" : "'published'";
        $where = '1=1';
        $bind = [];
        // Tab scoping (date rules take priority over stored status)
        if ($view === 'active') {
            $where .= " AND a.publish_at <= NOW() AND (a.expires_at IS NULL OR a.expires_at > NOW()) AND {$st} = 'published'";
        } elseif ($view === 'scheduled') {
            $where .= " AND a.publish_at > NOW() AND {$st} IN ('scheduled','published')";
        } elseif ($view === 'history') {
            $where .= " AND ((a.expires_at IS NOT NULL AND a.expires_at <= NOW() AND {$st} != 'draft') OR {$st} = 'archived')";
        }
        if ($q !== '') {
            $where .= ' AND (a.title LIKE ? OR a.content LIKE ?)';
            $like = '%' . $q . '%';
            array_push($bind, $like, $like);
        }
        if ($status !== '' && $hasStatus) {
            // Virtual 'expired' filter: past expiry, any non-draft status
            if ($status === 'expired') {
                $where .= " AND a.expires_at IS NOT NULL AND a.expires_at <= NOW() AND {$st} != 'draft'";
            } else {
                $where .= ' AND a.status = ?';
                $bind[] = $status;
            }
        }
        if ($type !== '' && $hasType && isset(Content::announcementTypes()[$type])) {
            $where .= ' AND a.type = ?';
            $bind[] = $type;
        }
        if ($aud !== '' && isset(Content::audiences()[$aud])) {
            // virtual registrar/accounting audiences stored as staff
            if (in_array($aud, ['registrar','accounting'], true)) {
                $where .= ' AND a.audience = ?';
                $bind[] = 'staff';
            } else {
                $where .= ' AND a.audience = ?';
                $bind[] = $aud;
            }
        }
        if ((($_GET['export'] ?? '') === 'csv')) $this->exportCsv($where, $bind, $hasStatus, $hasType);
        try {
            $total = (int) Database::value("SELECT COUNT(*) FROM announcements a WHERE {$where}", $bind);
            $typeSel = $hasType ? 'a.type' : "'general' AS type";
            $statusSel = $hasStatus ? 'a.status' : "'published' AS status";
            $rows = Database::all(
                "SELECT a.id, a.title, LEFT(a.content,120) AS excerpt, a.audience, {$typeSel}, {$statusSel},
                        a.publish_at, a.expires_at, a.is_pinned, a.created_at
                   FROM announcements a WHERE {$where}
                  ORDER BY a.is_pinned DESC, a.publish_at DESC LIMIT {$per} OFFSET {$off}", $bind
            );
        } catch (Throwable $e) {
            $total = 0;
            $rows = [];
        }
        if ((($_GET['partial'] ?? '') === 'list')) {
            $this->view('admin/announcements/_table', ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $per))], null);
            return;
        }
        $this->view('admin/announcements/index', [
            'title' => 'Announcements',
            'rows' => $rows, 'total' => $total, 'page' => $page,
            'pages' => max(1, (int) ceil($total / $per)),
            'q' => $q, 'status' => $status, 'type' => $type, 'audience' => $aud,
            'view' => $view,
            'types' => Content::announcementTypes(), 'audiences' => Content::audiences(),
            'counts' => Announcement::counts(),
            'expiringSoon' => Announcement::expiringSoon(3, 5),
        ]);
    }

    /** Admin details + lifecycle timeline (read-only; expired stay readable). */
    public function show(array $params = []): void
    {
        Auth::requireRole('admin');
        $this->ensureTable();
        $id = (int) ($params['id'] ?? 0);
        $row = Database::one(
            "SELECT a.*, CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS author_name
               FROM announcements a LEFT JOIN users u ON u.id = a.posted_by WHERE a.id = ? LIMIT 1", [$id]
        );
        if ($row === null) {
            set_flash('error', 'Announcement not found.');
            redirect('/admin/announcements');
        }
        // Lifecycle timeline from existing audit_logs (never invented dates)
        $timeline = [];
        try {
            $hasAudit = (bool) Database::value("SHOW TABLES LIKE 'audit_logs'");
            if ($hasAudit) {
                $timeline = Database::all(
                    "SELECT action, created_at, actor_user_id, new_values_json FROM audit_logs
                      WHERE entity_type = 'announcements' AND entity_id = ? ORDER BY created_at ASC LIMIT 100", [$id]
                );
            }
        } catch (Throwable $e) {
            $timeline = [];
        }
        $this->view('admin/announcements/show', [
            'title' => (string) ($row['title'] ?? 'Announcement'),
            'row' => $row, 'timeline' => $timeline,
            'computed' => Announcement::computedStatus($row),
            'types' => Content::announcementTypes(), 'audiences' => Content::audiences(),
        ]);
    }

    public function create(array $params = []): void
    {
        Auth::requireRole('admin');
        $this->ensureTable();
        $this->view('admin/announcements/form', [
            'title' => 'New announcement',
            'row' => null, 'errors' => [],
            'types' => Content::announcementTypes(), 'audiences' => Content::audiences(),
            'campuses' => $this->campuses(), 'levels' => $this->levels(), 'years' => $this->years(),
        ]);
    }

    public function store(array $params = []): void
    {
        Auth::requireRole('admin');
        Csrf::guard();
        $this->ensureTable();
        $this->save(null);
    }

    public function edit(array $params = []): void
    {
        Auth::requireRole('admin');
        $this->ensureTable();
        $id = (int) ($params['id'] ?? 0);
        $row = Database::one("SELECT * FROM announcements WHERE id = ? LIMIT 1", [$id]);
        if ($row === null) {
            set_flash('error', 'Announcement not found.');
            redirect('/admin/announcements');
        }
        $this->view('admin/announcements/form', [
            'title' => 'Edit announcement', 'row' => $row, 'errors' => [],
            'types' => Content::announcementTypes(), 'audiences' => Content::audiences(),
            'campuses' => $this->campuses(), 'levels' => $this->levels(), 'years' => $this->years(),
        ]);
    }

    public function update(array $params = []): void
    {
        Auth::requireRole('admin');
        Csrf::guard();
        $this->ensureTable();
        $id = (int) ($params['id'] ?? 0);
        $row = Database::one("SELECT * FROM announcements WHERE id = ? LIMIT 1", [$id]);
        if ($row === null) {
            set_flash('error', 'Announcement not found.');
            redirect('/admin/announcements');
        }
        $this->save($row);
    }

    public function destroy(array $params = []): void
    {
        Auth::requireRole('admin');
        Csrf::guard();
        $this->ensureTable();
        $id = (int) ($params['id'] ?? 0);
        $row = Database::one("SELECT * FROM announcements WHERE id = ? LIMIT 1", [$id]);
        if ($row === null) {
            set_flash('error', 'Announcement not found.');
            redirect('/admin/announcements');
        }
        // Preserve history: only drafts/expired may be deleted
        $expired = !empty($row['expires_at']) && strtotime((string) $row['expires_at']) <= time();
        $isDraft = (($row['status'] ?? 'published') === 'draft');
        if (!($isDraft || $expired)) {
            set_flash('error', 'Only drafts or expired announcements can be deleted — archive active ones instead to preserve history.');
            redirect('/admin/announcements');
        }
        try {
            Database::run("DELETE FROM announcements WHERE id = ?", [$id]);
            Content::audit((int) Auth::id(), 'ANNOUNCEMENT_ARCHIVED', 'announcements', $id, ['deleted' => true]);
            set_flash('success', 'Announcement deleted.');
        } catch (Throwable $e) {
            set_flash('error', 'Could not delete announcement.');
        }
        redirect('/admin/announcements');
    }

    /* ------------------------------ internals ------------------------------ */

    private function hasCol(string $col): bool
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

    private function campuses(): array
    {
        try {
            return Database::all("SELECT id, code, name FROM campuses WHERE is_active = 1 ORDER BY name LIMIT 50");
        } catch (Throwable $e) {
            return [];
        }
    }

    private function levels(): array
    {
        try {
            return Database::all("SELECT id, code, name FROM grade_levels ORDER BY sort_order LIMIT 100");
        } catch (Throwable $e) {
            return [];
        }
    }

    private function years(): array
    {
        try {
            return Database::all("SELECT id, school_year FROM academic_years ORDER BY school_year DESC LIMIT 10");
        } catch (Throwable $e) {
            return [];
        }
    }

    private function save(?array $existing): void
    {
        $id = $existing !== null ? (int) $existing['id'] : 0;
        $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 150);
        $message = Content::cleanHtml((string) ($_POST['content'] ?? ''));
        $plainLen = mb_strlen(trim(strip_tags($message)));
        $audience = trim((string) ($_POST['audience'] ?? 'everyone'));
        $type = trim((string) ($_POST['type'] ?? 'general'));
        $status = trim((string) ($_POST['status'] ?? 'published'));
        $pinned = isset($_POST['is_pinned']) && $_POST['is_pinned'] === '1' ? 1 : 0;
        $campusId = (int) ($_POST['campus_id'] ?? 0) ?: null;
        $levelId = (int) ($_POST['grade_level_id'] ?? 0) ?: null;
        $yearId = (int) ($_POST['academic_year_id'] ?? 0) ?: null;
        $sectionId = (int) ($_POST['section_id'] ?? 0) ?: null;
        $pubRaw = trim((string) ($_POST['publish_at'] ?? ''));
        $expRaw = trim((string) ($_POST['expires_at'] ?? ''));

        $errors = [];
        if ($title === '') $errors[] = 'Title is required.';
        if ($plainLen < 10) $errors[] = 'Message is too short (minimum 10 characters).';
        if (!isset(Content::audiences()[$audience])) $errors[] = 'Invalid audience.';
        if (!isset(Content::announcementTypes()[$type])) $errors[] = 'Invalid type.';
        if (!in_array($status, ['draft','scheduled','published','archived'], true)) $status = 'draft';
        // Map virtual audiences to stored enum (DB enum lacks registrar/accounting)
        $storeAud = in_array($audience, ['registrar','accounting'], true) ? 'staff' : $audience;
        if (!in_array($storeAud, ['everyone','students','parents','teachers','staff','class'], true)) $errors[] = 'Invalid audience.';
        if ($storeAud === 'class' && !$sectionId) $errors[] = 'Class audience requires a section.';

        $publishAt = date('Y-m-d H:i:s');
        $expiresAt = null;
        if ($status === 'scheduled' || $pubRaw !== '') {
            if ($pubRaw === '' && $status === 'scheduled') $errors[] = 'Scheduled announcements need a publish date/time.';
            elseif ($pubRaw !== '') {
                $ts = strtotime($pubRaw);
                if ($ts === false) $errors[] = 'Invalid publish date.';
                else {
                    $publishAt = date('Y-m-d H:i:s', $ts);
                    if ($status === 'scheduled' && $ts <= time()) $errors[] = 'Scheduled publish must be in the future.';
                }
            }
        }
        if ($expRaw !== '') {
            $ts = strtotime($expRaw);
            if ($ts === false) $errors[] = 'Invalid expiry date.';
            else {
                $expiresAt = date('Y-m-d H:i:s', $ts);
                if (strtotime($publishAt) !== false && $ts <= strtotime($publishAt)) $errors[] = 'Expiry must be after publish date.';
            }
        }
        // Attachment (optional, reuse Uploads allowlist — never executables)
        $attachPath = $existing['attachment_path'] ?? null;
        if (isset($_FILES['attachment']) && is_array($_FILES['attachment']) && (($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
            $f = $_FILES['attachment'];
            if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $errors[] = 'Attachment upload failed.';
            } elseif (($f['size'] ?? 0) > 5 * 1024 * 1024) {
                $errors[] = 'Attachment too large (max 5 MB).';
            } else {
                $mime = (string) (@mime_content_type((string) $f['tmp_name']) ?: '');
                $ext = \App\Core\Uploads::safeExt((string) ($f['name'] ?? ''), $mime);
                if ($ext === null || in_array($ext, ['php','phtml','phar','svg','html','htm','js'], true)) {
                    $errors[] = 'Attachment type not allowed.';
                } elseif (!is_uploaded_file((string) $f['tmp_name'])) {
                    $errors[] = 'Invalid attachment.';
                } else {
                    $dir = rtrim(UPLOAD_DIR, '/\\') . '/announcements';
                    if (!is_dir($dir)) @mkdir($dir, 0775, true);
                    $name = \App\Core\Uploads::storedName('ann', $ext);
                    if (!@move_uploaded_file((string) $f['tmp_name'], $dir . '/' . $name)) {
                        $errors[] = 'Could not save attachment.';
                    } else {
                        @chmod($dir . '/' . $name, 0644);
                        $attachPath = 'uploads/announcements/' . $name;
                    }
                }
            }
        }
        if (isset($_POST['remove_attachment']) && $_POST['remove_attachment'] === '1') $attachPath = null;

        if ($errors !== []) {
            $this->view('admin/announcements/form', [
                'title' => $existing ? 'Edit announcement' : 'New announcement',
                'row' => array_merge($existing ?? [], [
                    'title' => $title, 'content' => (string) ($_POST['content'] ?? ''),
                    'audience' => $audience, 'type' => $type, 'status' => $status,
                    'is_pinned' => $pinned, 'campus_id' => $campusId,
                    'grade_level_id' => $levelId, 'academic_year_id' => $yearId,
                    'publish_at' => $pubRaw, 'expires_at' => $expRaw,
                ]),
                'errors' => $errors,
                'types' => Content::announcementTypes(), 'audiences' => Content::audiences(),
                'campuses' => $this->campuses(), 'levels' => $this->levels(), 'years' => $this->years(),
            ]);
            return;
        }

        $hasStatus = $this->hasCol('status');
        $hasType = $this->hasCol('type');
        $hasCampus = $this->hasCol('campus_id');
        $hasLevel = $this->hasCol('grade_level_id');
        $hasYear = $this->hasCol('academic_year_id');
        $hasAttach = $this->hasCol('attachment_path');

        try {
            if ($id > 0) {
                $data = [
                    'title' => $title, 'content' => $message, 'audience' => $storeAud,
                    'publish_at' => $publishAt, 'expires_at' => $expiresAt, 'is_pinned' => $pinned,
                    'section_id' => $sectionId,
                ];
                if ($hasType) $data['type'] = $type;
                if ($hasStatus) $data['status'] = $status;
                if ($hasCampus) $data['campus_id'] = $campusId;
                if ($hasLevel) $data['grade_level_id'] = $levelId;
                if ($hasYear) $data['academic_year_id'] = $yearId;
                if ($hasAttach) $data['attachment_path'] = $attachPath;
                Database::update('announcements', $data, ['id' => $id]);
                $savedId = $id;
                $action = $status === 'published' ? 'ANNOUNCEMENT_PUBLISHED' : ($status === 'archived' ? 'ANNOUNCEMENT_ARCHIVED' : ($status === 'scheduled' ? 'ANNOUNCEMENT_SCHEDULED' : 'ANNOUNCEMENT_UPDATED'));
            } else {
                $data = [
                    'title' => $title, 'content' => $message, 'audience' => $storeAud,
                    'posted_by' => (int) Auth::id(),
                    'publish_at' => $status === 'draft' ? date('Y-m-d H:i:s') : $publishAt,
                    'expires_at' => $expiresAt, 'is_pinned' => $pinned,
                    'section_id' => $sectionId,
                ];
                if ($hasType) $data['type'] = $type;
                if ($hasStatus) $data['status'] = $status;
                if ($hasCampus) $data['campus_id'] = $campusId;
                if ($hasLevel) $data['grade_level_id'] = $levelId;
                if ($hasYear) $data['academic_year_id'] = $yearId;
                if ($hasAttach) $data['attachment_path'] = $attachPath;
                $savedId = Database::insert('announcements', $data);
                $action = $status === 'draft' ? 'ANNOUNCEMENT_CREATED' : 'ANNOUNCEMENT_PUBLISHED';
            }
            Content::audit((int) Auth::id(), $action, 'announcements', $savedId, [
                'audience' => $storeAud, 'status' => $status, 'type' => $type,
            ]);
            // Change tracking for history timeline (only on update, only what changed)
            if ($id > 0 && is_array($existing)) {
                $changes = [];
                if ((string) ($existing['audience'] ?? '') !== (string) $storeAud) {
                    $changes['audience'] = ['from' => $existing['audience'] ?? null, 'to' => $storeAud];
                }
                if (substr((string) ($existing['publish_at'] ?? ''), 0, 16) !== substr((string) $publishAt, 0, 16)) {
                    $changes['publish_at'] = ['from' => $existing['publish_at'] ?? null, 'to' => $publishAt];
                }
                if ((string) ($existing['expires_at'] ?? '') !== (string) ($expiresAt ?? '')) {
                    $changes['expires_at'] = ['from' => $existing['expires_at'] ?? null, 'to' => $expiresAt];
                }
                if ($changes !== []) {
                    Content::audit((int) Auth::id(), 'ANNOUNCEMENT_UPDATED', 'announcements', $savedId, $changes);
                }
            }
            // Fan-out to EXISTING notifications (audience-respecting, link to detail)
            if ($status === 'published' && strtotime($publishAt) <= time()) {
                $this->fanout($storeAud, $title, $message, $savedId);
            }
            set_flash('success', $status === 'draft' ? 'Draft saved.' : ($status === 'scheduled' ? 'Announcement scheduled.' : ($status === 'archived' ? 'Announcement archived.' : 'Announcement published.')));
        } catch (Throwable $e) {
            storage_log('announcement save failed: ' . $e->getMessage());
            set_flash('error', 'Could not save announcement.');
            redirect($id > 0 ? '/admin/announcements/' . $id . '/edit' : '/admin/announcements/create');
            return;
        }
        redirect('/admin/announcements');
    }

    /** Push to the right users only (no duplicate notification system). */
    private function fanout(string $audience, string $title, string $message, int $annId): void
    {
        $link = '/announcements/' . $annId;
        $short = mb_substr(trim(strip_tags($message)), 0, 140);
        try {
            match ($audience) {
                'everyone' => $this->pushAll($title, $short, $link),
                'students' => NotificationController::pushToStudents('Announcement: ' . $title, $short, 'info', $link),
                'parents' => NotificationController::pushToRole('parent', 'Announcement: ' . $title, $short, 'info', $link),
                'teachers' => NotificationController::pushToTeachers('Announcement: ' . $title, $short, 'info', $link),
                'staff' => $this->pushStaff($title, $short, $link),
                default => NotificationController::pushToAdmins('Announcement: ' . $title, $short, 'info', $link),
            };
        } catch (Throwable $e) {
        }
    }

    private function pushAll(string $title, string $short, string $link): void
    {
        try {
            $rows = Database::all("SELECT u.id FROM users u WHERE u.status = 'active' AND u.is_active = 1 ORDER BY u.id DESC LIMIT 1000");
            NotificationController::pushMany(array_map(static fn($r) => (int) $r['id'], $rows), 'Announcement: ' . $title, $short, 'info', $link);
        } catch (Throwable $e) {
        }
    }

    private function pushStaff(string $title, string $short, string $link): void
    {
        NotificationController::pushToRegistrars('Announcement: ' . $title, $short, 'info', $link);
        NotificationController::pushToAccounting('Announcement: ' . $title, $short, 'info', $link);
        NotificationController::pushToAdmins('Announcement: ' . $title, $short, 'info', $link);
    }

    private function exportCsv(string $where, array $bind, bool $hasStatus, bool $hasType): void
    {
        try {
            $typeSel = $hasType ? 'a.type' : "'general' AS type";
            $statusSel = $hasStatus ? 'a.status' : "'published' AS status";
            $rows = Database::all(
                "SELECT a.title, a.audience, {$typeSel}, {$statusSel}, a.publish_at, a.expires_at, a.created_at
                   FROM announcements a WHERE {$where} ORDER BY a.publish_at DESC LIMIT 2000", $bind
            );
        } catch (Throwable $e) {
            $rows = [];
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="announcements-' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Title', 'Audience', 'Type', 'Status', 'Publish', 'Expiry', 'Created']);
        foreach ($rows as $r) fputcsv($out, [$r['title'], $r['audience'], $r['type'], $r['status'], $r['publish_at'], $r['expires_at'], $r['created_at']]);
        fclose($out);
        exit;
    }
}
