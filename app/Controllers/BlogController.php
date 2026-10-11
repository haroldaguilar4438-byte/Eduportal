<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Models\Blog;
use Throwable;

/**
 * Public Blog / School News (no login required).
 * Visibility: only published + due posts. Slug-based URLs (no raw IDs).
 */
final class BlogController extends Controller
{
    public function index(array $params = []): void
    {
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $cat = mb_substr(trim((string) ($_GET['category'] ?? '')), 0, 100);
        $page = max(1, (int) ($_GET['page'] ?? 1));
        // AJAX fragment for live search/filter (no full reload)
        $partial = (($_GET['partial'] ?? '') === 'list')
            || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest' && isset($_GET['q']));

        $data = Blog::publicList($q, $cat, $page, 9);
        $cats = Blog::categories();
        $featured = ($page === 1 && $q === '' && $cat === '') ? Blog::featured() : null;
        // Avoid featuring the same card twice in the grid
        if ($featured !== null) {
            $data['rows'] = array_values(array_filter(
                $data['rows'],
                static fn($r) => (string) ($r['slug'] ?? '') !== (string) ($featured['slug'] ?? '')
            ));
        }
        if ($partial) {
            $this->view('blog/_list', [
                'rows' => $data['rows'], 'total' => $data['total'],
                'pages' => $data['pages'], 'page' => $page, 'q' => $q, 'category' => $cat,
            ], null);
            return;
        }
        $this->view('blog/index', [
            'title' => 'School News',
            'rows' => $data['rows'], 'total' => $data['total'],
            'pages' => $data['pages'], 'page' => $page,
            'q' => $q, 'category' => $cat, 'cats' => $cats, 'featured' => $featured,
            'user' => Auth::user(),
            'school' => self::school(),
            'campuses' => self::campuses(),
        ], null);
    }

    public function show(array $params = []): void
    {
        $slug = mb_substr(trim((string) ($params['slug'] ?? '')), 0, 220);
        if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
            http_response_code(404);
            $this->view('blog/show', ['title' => 'Article not found', 'post' => null, 'related' => [], 'user' => Auth::user(), 'school' => self::school(), 'campuses' => self::campuses()], null);
            return;
        }
        $post = Blog::findPublic($slug);
        if ($post === null) {
            http_response_code(404);
            $this->view('blog/show', ['title' => 'Article not found', 'post' => null, 'related' => [], 'user' => Auth::user(), 'school' => self::school(), 'campuses' => self::campuses()], null);
            return;
        }
        Blog::bumpViews((int) $post['id']);
        $this->view('blog/show', [
            'title' => (string) ($post['title'] ?? 'School News'),
            'post' => $post,
            'related' => Blog::related((int) $post['id'], isset($post['category_id']) ? (int) $post['category_id'] : null, 3),
            'user' => Auth::user(),
            'school' => self::school(),
            'campuses' => self::campuses(),
        ], null);
    }

    /** JSON feed for homepage widgets / chatbot-safe public summaries. */
    public function feed(array $params = []): void
    {
        $data = Blog::publicList('', '', 1, 5);
        $items = array_map(static fn($r) => [
            'title' => $r['title'], 'slug' => $r['slug'],
            'summary' => $r['summary'], 'category' => $r['category_name'] ?? '',
            'published_at' => $r['published_at'] ?? null,
            'url' => url('/news/' . $r['slug']),
        ], $data['rows']);
        $this->json(['items' => $items]);
    }

    /** Same single-sourced school info as HomeController (fail-open). */
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
