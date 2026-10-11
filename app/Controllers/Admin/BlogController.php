<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Content;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use App\Models\Blog;
use Throwable;

/**
 * Admin Blog / News management.
 * Statuses: draft, scheduled, published, archived.
 * Draft/scheduled never appear publicly (enforced by Models\Blog).
 */
final class BlogController extends Controller
{
    private function ensureTable(): bool
    {
        if (Blog::tableReady()) return true;
        set_flash('error', 'Blog tables are not installed — import database/patch_blog_announcements.sql first.');
        redirect('/dashboard');
        return false;
    }

    public function index(array $params = []): void
    {
        Auth::requireRole('admin');
        $this->ensureTable();
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $status = trim((string) ($_GET['status'] ?? ''));
        $cat = trim((string) ($_GET['category'] ?? ''));
        if (!in_array($status, ['draft','scheduled','published','archived',''], true)) $status = '';
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $per = 15;
        $off = ($page - 1) * $per;

        $where = '1=1';
        $bind = [];
        if ($q !== '') {
            $where .= ' AND (p.title LIKE ? OR p.slug LIKE ? OR p.tags LIKE ?)';
            $like = '%' . $q . '%';
            array_push($bind, $like, $like, $like);
        }
        if ($status !== '') {
            $where .= ' AND p.status = ?';
            $bind[] = $status;
        }
        if ($cat !== '') {
            $where .= ' AND c.slug = ?';
            $bind[] = $cat;
        }
        // CSV export (admin tables, public-safe fields only)
        if ((($_GET['export'] ?? '') === 'csv')) {
            $this->exportCsv($where, $bind);
        }
        try {
            $total = (int) Database::value(
                "SELECT COUNT(*) FROM blog_posts p LEFT JOIN blog_categories c ON c.id = p.category_id WHERE {$where}", $bind
            );
            $rows = Database::all(
                "SELECT p.*, c.name AS category_name,
                        CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS author_name
                   FROM blog_posts p LEFT JOIN blog_categories c ON c.id = p.category_id
                   LEFT JOIN users u ON u.id = p.author_id
                  WHERE {$where} ORDER BY p.updated_at DESC LIMIT {$per} OFFSET {$off}", $bind
            );
        } catch (Throwable $e) {
            $total = 0;
            $rows = [];
        }
        if ((($_GET['partial'] ?? '') === 'list')) {
            $this->view('admin/blog/_table', ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $per))], null);
            return;
        }
        $this->view('admin/blog/index', [
            'title' => 'Blog / News',
            'rows' => $rows, 'total' => $total, 'page' => $page,
            'pages' => max(1, (int) ceil($total / $per)),
            'q' => $q, 'status' => $status, 'category' => $cat,
            'cats' => Blog::categories(), 'counts' => Blog::counts(),
        ]);
    }

    public function create(array $params = []): void
    {
        Auth::requireRole('admin');
        $this->ensureTable();
        $this->view('admin/blog/form', [
            'title' => 'New article',
            'post' => null, 'cats' => Blog::categories(), 'errors' => [],
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
        $post = Database::one("SELECT * FROM blog_posts WHERE id = ? LIMIT 1", [$id]);
        if ($post === null) {
            set_flash('error', 'Article not found.');
            redirect('/admin/blog');
        }
        $this->view('admin/blog/form', [
            'title' => 'Edit article', 'post' => $post,
            'cats' => Blog::categories(), 'errors' => [],
        ]);
    }

    public function update(array $params = []): void
    {
        Auth::requireRole('admin');
        Csrf::guard();
        $this->ensureTable();
        $id = (int) ($params['id'] ?? 0);
        $post = Database::one("SELECT * FROM blog_posts WHERE id = ? LIMIT 1", [$id]);
        if ($post === null) {
            set_flash('error', 'Article not found.');
            redirect('/admin/blog');
        }
        $this->save($post);
    }

    /** Preview uses the REAL public layout without publishing. */
    public function preview(array $params = []): void
    {
        Auth::requireRole('admin');
        $this->ensureTable();
        $id = (int) ($params['id'] ?? 0);
        $post = Database::one(
            "SELECT p.*, c.name AS category_name,
                    CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS author_name
               FROM blog_posts p LEFT JOIN blog_categories c ON c.id = p.category_id
               LEFT JOIN users u ON u.id = p.author_id WHERE p.id = ? LIMIT 1", [$id]
        );
        if ($post === null) {
            set_flash('error', 'Article not found.');
            redirect('/admin/blog');
        }
        // Only warn when the article is NOT publicly visible.
        // Public = status published AND (no future publish date).
        $isPublic = ($post['status'] ?? '') === 'published'
            && (empty($post['published_at']) || strtotime((string) $post['published_at']) <= time());
        $related = [];
        if ($isPublic) {
            try {
                $related = Blog::related((int) $post['id'], isset($post['category_id']) ? (int) $post['category_id'] : null, 3);
            } catch (Throwable $e) {
                $related = [];
            }
        }
        $this->view('blog/show', [
            'title' => 'Preview: ' . (string) ($post['title'] ?? ''),
            'post' => $post, 'related' => $related, 'preview' => !$isPublic,
            'user' => Auth::user(),
            'school' => self::school(),
            'campuses' => self::campuses(),
        ], null);
    }

    /**
     * One-click archive: unpublishes a post (removes it from /news and the
     * homepage) while preserving its record and history. An archived post
     * becomes eligible for hard-deletion via destroy().
     */
    public function archive(array $params = []): void
    {
        Auth::requireRole('admin');
        Csrf::guard();
        $this->ensureTable();
        $id = (int) ($params['id'] ?? 0);
        $post = Database::one("SELECT * FROM blog_posts WHERE id = ? LIMIT 1", [$id]);
        if ($post === null) {
            set_flash('error', 'Article not found.');
            redirect('/admin/blog');
        }
        if (in_array((string) ($post['status'] ?? ''), ['draft', 'archived'], true)) {
            set_flash('info', 'Article is already out of public view.');
            redirect('/admin/blog');
        }
        try {
            Database::update('blog_posts', ['status' => 'archived'], ['id' => $id]);
            Content::audit((int) Auth::id(), 'BLOG_ARCHIVED', 'blog_posts', $id, ['slug' => $post['slug'] ?? '']);
            set_flash('success', 'Article archived and removed from public view. You may now delete it permanently if needed.');
        } catch (Throwable $e) {
            storage_log('blog archive failed: ' . $e->getMessage());
            set_flash('error', 'Could not archive article.');
        }
        redirect('/admin/blog');
    }

    public function destroy(array $params = []): void
    {
        Auth::requireRole('admin');
        Csrf::guard();
        $this->ensureTable();
        $id = (int) ($params['id'] ?? 0);
        $post = Database::one("SELECT * FROM blog_posts WHERE id = ? LIMIT 1", [$id]);
        if ($post === null) {
            set_flash('error', 'Article not found.');
            redirect('/admin/blog');
        }
        // Only drafts/archived may be hard-deleted; published must be archived first
        if (!in_array((string) ($post['status'] ?? ''), ['draft','archived'], true)) {
            set_flash('error', 'Published or scheduled posts must be archived first — deletion is blocked to preserve history.');
            redirect('/admin/blog');
        }
        try {
            if (!empty($post['featured_image'])) {
                $f = ROOT_PATH . '/public_html/' . ltrim((string) $post['featured_image'], '/');
                if (is_file($f) && str_contains(realpath($f) ?: '', realpath(UPLOAD_DIR) ?: 'uploads')) @unlink($f);
            }
            Database::run("DELETE FROM blog_posts WHERE id = ?", [$id]);
            Content::audit((int) Auth::id(), 'BLOG_DELETED', 'blog_posts', $id, ['slug' => $post['slug'] ?? '']);
            set_flash('success', 'Article deleted.');
        } catch (Throwable $e) {
            storage_log('blog delete failed: ' . $e->getMessage());
            set_flash('error', 'Could not delete article.');
        }
        redirect('/admin/blog');
    }

    public function contentDashboard(array $params = []): void
    {
        Auth::requireRole('admin');
        $this->ensureTable();
        $blogCounts = Blog::counts();
        $annCounts = \App\Models\Announcement::counts();
        try {
            $recentPosts = Database::all(
                "SELECT id, title, slug, status, published_at, updated_at FROM blog_posts ORDER BY updated_at DESC LIMIT 5"
            );
        } catch (Throwable $e) {
            $recentPosts = [];
        }
        try {
            $recentAnn = Database::all(
                "SELECT id, title, audience, publish_at, expires_at FROM announcements ORDER BY publish_at DESC LIMIT 5"
            );
        } catch (Throwable $e) {
            $recentAnn = [];
        }
        $this->view('admin/content/dashboard', [
            'title' => 'Content Management',
            'blogCounts' => $blogCounts, 'annCounts' => $annCounts,
            'recentPosts' => $recentPosts, 'recentAnn' => $recentAnn,
            'cats' => Blog::categories(),
        ]);
    }

    /* ------------------------------ internals ------------------------------ */

    /** Shared create/update with validation, slug safety, XSS cleaning, image checks. */
    private function save(?array $existing): void
    {
        $id = $existing !== null ? (int) $existing['id'] : 0;
        $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 200);
        $slugIn = mb_substr(trim((string) ($_POST['slug'] ?? '')), 0, 220);
        $summary = mb_substr(trim((string) ($_POST['summary'] ?? '')), 0, 500);
        $contentRaw = (string) ($_POST['content'] ?? '');
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $tags = Content::normalizeTags((string) ($_POST['tags'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? 'draft'));
        if (!in_array($status, ['draft','scheduled','published','archived'], true)) $status = 'draft';
        $pubRaw = trim((string) ($_POST['published_at'] ?? ''));
        $seoTitle = mb_substr(trim((string) ($_POST['seo_title'] ?? '')), 0, 200);
        $seoDesc = mb_substr(trim((string) ($_POST['seo_description'] ?? '')), 0, 300);

        $errors = [];
        if ($title === '') $errors[] = 'Title is required.';
        if (mb_strlen($contentRaw) < 20) $errors[] = 'Content is too short (minimum 20 characters).';
        if (mb_strlen($contentRaw) > 200000) $errors[] = 'Content is too long.';
        if ($summary === '') $summary = Content::excerpt($contentRaw, 160);

        $publishedAt = null;
        if ($status === 'published') {
            $publishedAt = date('Y-m-d H:i:s');
            if ($pubRaw !== '') {
                $ts = strtotime($pubRaw);
                if ($ts === false) $errors[] = 'Invalid publication date.';
                else $publishedAt = date('Y-m-d H:i:s', $ts);
            }
        } elseif ($status === 'scheduled') {
            if ($pubRaw === '') {
                $errors[] = 'Scheduled posts need a publication date/time.';
            } else {
                $ts = strtotime($pubRaw);
                if ($ts === false) $errors[] = 'Invalid publication date.';
                elseif ($ts <= time()) $errors[] = 'Scheduled date must be in the future.';
                else $publishedAt = date('Y-m-d H:i:s', $ts);
            }
        }

        if ($categoryId > 0) {
            $catOk = Database::one("SELECT id FROM blog_categories WHERE id = ? LIMIT 1", [$categoryId]);
            if ($catOk === null) $errors[] = 'Invalid category.';
        } else {
            $categoryId = null;
        }

        // Image (optional): strict MIME + dimensions + real content checks
        [$imgOk, $imgErr, $imgPath] = Content::storeImage($_FILES['featured_image'] ?? [], 'blog', 'blog', 2 * 1024 * 1024);
        if (!$imgOk) $errors[] = $imgErr;
        $removeImg = isset($_POST['remove_image']) && $_POST['remove_image'] === '1';

        if ($errors !== []) {
            $draft = [
                'id' => $id, 'title' => $title, 'slug' => $slugIn, 'summary' => $summary,
                'content' => $contentRaw, 'category_id' => $categoryId, 'tags' => $tags,
                'status' => $status, 'published_at' => $pubRaw, 'seo_title' => $seoTitle,
                'seo_description' => $seoDesc,
                'featured_image' => $existing['featured_image'] ?? null,
            ];
            $this->view('admin/blog/form', [
                'title' => $existing ? 'Edit article' : 'New article',
                'post' => $draft, 'cats' => Blog::categories(), 'errors' => $errors,
            ]);
            return;
        }

        $slugBase = $slugIn !== '' ? Content::slugify($slugIn) : Content::slugify($title);
        $slug = Content::uniqueSlug('blog_posts', $slugBase, $id > 0 ? $id : null);
        $clean = Content::cleanHtml($contentRaw);

        try {
            Database::transaction(static function () use ($id, $title, $slug, $summary, $clean, $imgPath, $removeImg, $existing, $categoryId, $tags, $status, $publishedAt, $seoTitle, $seoDesc) {
                $finalImg = $existing['featured_image'] ?? null;
                if ($imgPath !== null) {
                    if (!empty($finalImg)) {
                        $old = ROOT_PATH . '/public_html/' . ltrim((string) $finalImg, '/');
                        if (is_file($old)) @unlink($old);
                    }
                    $finalImg = $imgPath;
                } elseif ($removeImg) {
                    if (!empty($finalImg)) {
                        $old = ROOT_PATH . '/public_html/' . ltrim((string) $finalImg, '/');
                        if (is_file($old)) @unlink($old);
                    }
                    $finalImg = null;
                }
                if ($id > 0) {
                    Database::update('blog_posts', [
                        'title' => $title, 'slug' => $slug, 'summary' => $summary ?: null,
                        'content' => $clean, 'featured_image' => $finalImg,
                        'category_id' => $categoryId, 'tags' => $tags ?: null,
                        'status' => $status, 'published_at' => $publishedAt,
                        'seo_title' => $seoTitle ?: null, 'seo_description' => $seoDesc ?: null,
                    ], ['id' => $id]);
                } else {
                    $id = Database::insert('blog_posts', [
                        'title' => $title, 'slug' => $slug, 'summary' => $summary ?: null,
                        'content' => $clean, 'featured_image' => $finalImg,
                        'author_id' => (int) Auth::id(),
                        'category_id' => $categoryId, 'tags' => $tags ?: null,
                        'status' => $status, 'published_at' => $publishedAt,
                        'seo_title' => $seoTitle ?: null, 'seo_description' => $seoDesc ?: null,
                    ]);
                }
                return $id;
            });
            $savedId = $existing !== null ? $id : (int) Database::value("SELECT id FROM blog_posts WHERE slug = ? LIMIT 1", [$slug]);
            $action = $existing === null ? 'BLOG_CREATED' : ($status === 'published' ? 'BLOG_PUBLISHED' : ($status === 'archived' ? 'BLOG_ARCHIVED' : 'BLOG_UPDATED'));
            Content::audit((int) Auth::id(), $action, 'blog_posts', $savedId, ['slug' => $slug, 'status' => $status]);
            set_flash('success', $status === 'draft' ? 'Draft saved.' : ($status === 'scheduled' ? 'Article scheduled for ' . $publishedAt . '.' : ($status === 'archived' ? 'Article archived.' : 'Article published.')));
        } catch (Throwable $e) {
            storage_log('blog save failed: ' . $e->getMessage());
            $this->view('admin/blog/form', [
                'title' => $existing ? 'Edit article' : 'New article',
                'post' => array_merge($existing ?? [], ['title' => $title, 'slug' => $slugIn, 'summary' => $summary, 'content' => $contentRaw]),
                'cats' => Blog::categories(), 'errors' => ['Could not save article. Please try again.'],
            ]);
            return;
        }
        redirect('/admin/blog');
    }

    private function exportCsv(string $where, array $bind): void
    {
        Auth::requireRole('admin');
        try {
            $rows = Database::all(
                "SELECT p.title, COALESCE(c.name,'') AS category, p.status,
                        CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS author,
                        p.published_at, p.updated_at
                   FROM blog_posts p LEFT JOIN blog_categories c ON c.id = p.category_id
                   LEFT JOIN users u ON u.id = p.author_id WHERE {$where} ORDER BY p.updated_at DESC LIMIT 2000", $bind
            );
        } catch (Throwable $e) {
            $rows = [];
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="blog-posts-' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Title', 'Category', 'Status', 'Author', 'Published', 'Updated']);
        foreach ($rows as $r) fputcsv($out, [$r['title'], $r['category'], $r['status'], trim((string) $r['author']), $r['published_at'], $r['updated_at']]);
        fclose($out);
        exit;
    }

    /** Same single-sourced school info as public BlogController (fail-open). */
    private static function school(): array
    {
        $school = [
            'name' => 'Datamex College of Saint Adeline',
            'address' => 'Dasmarinas, Cavite, Philippines',
            'contact' => 'datamex82@gmail.com',
            'support' => 'support@datamex.edu.ph',
        ];
        try {
            foreach (['school_name' => 'name', 'school_address' => 'address', 'school_contact' => 'contact', 'support_email' => 'support'] as $k => $to) {
                $v = Database::value("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1", [$k]);
                if ($v !== null && trim((string) $v) !== '') $school[$to] = trim((string) $v);
            }
        } catch (Throwable $e) {
        }
        return $school;
    }

    private static function campuses(): array
    {
        try {
            return Database::all("SELECT code, name FROM campuses WHERE is_active = 1 ORDER BY name LIMIT 20");
        } catch (Throwable $e) {
            return [];
        }
    }
}
