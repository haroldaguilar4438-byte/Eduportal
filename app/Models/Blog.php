<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Content;
use App\Core\Database;
use Throwable;

/**
 * Blog read model — single source for public visibility rules.
 * Draft never public. Scheduled only after published_at. Archived excluded
 * unless explicitly requested. All queries paginated + indexed.
 */
final class Blog
{
    public static function tableReady(): bool
    {
        try {
            return (bool) Database::value("SHOW TABLES LIKE 'blog_posts'");
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function counts(): array
    {
        $out = ['published' => 0, 'draft' => 0, 'scheduled' => 0, 'archived' => 0];
        if (!self::tableReady()) return $out;
        try {
            $rows = Database::all("SELECT status, COUNT(*) c FROM blog_posts GROUP BY status");
            foreach ($rows as $r) {
                $s = (string) ($r['status'] ?? '');
                if (isset($out[$s])) $out[$s] = (int) $r['c'];
            }
            // scheduled that already matured counts as published for display,
            // but keep raw counts for admin accuracy
        } catch (Throwable $e) {
        }
        return $out;
    }

    /** Public list — only published + due. */
    public static function publicList(string $q = '', string $category = '', int $page = 1, int $perPage = 9): array
    {
        $page = max(1, $page);
        $perPage = min(24, max(1, $perPage));
        $off = ($page - 1) * $perPage;
        if (!self::tableReady()) return ['rows' => [], 'total' => 0, 'pages' => 1];
        $where = "p.status = 'published' AND (p.published_at IS NULL OR p.published_at <= NOW())";
        $bind = [];
        if ($q !== '') {
            $where .= " AND (p.title LIKE ? OR p.summary LIKE ? OR p.tags LIKE ?)";
            $like = '%' . $q . '%';
            array_push($bind, $like, $like, $like);
        }
        if ($category !== '') {
            $where .= " AND c.slug = ?";
            $bind[] = $category;
        }
        try {
            $total = (int) Database::value(
                "SELECT COUNT(*) FROM blog_posts p LEFT JOIN blog_categories c ON c.id = p.category_id WHERE {$where}",
                $bind
            );
            $rows = Database::all(
                "SELECT p.id, p.title, p.slug, p.summary, p.featured_image, p.tags, p.published_at, p.view_count,
                        c.name AS category_name, c.slug AS category_slug,
                        CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS author_name
                   FROM blog_posts p
                   LEFT JOIN blog_categories c ON c.id = p.category_id
                   LEFT JOIN users u ON u.id = p.author_id
                  WHERE {$where}
                  ORDER BY p.published_at DESC, p.id DESC
                  LIMIT {$perPage} OFFSET {$off}",
                $bind
            );
            return ['rows' => $rows, 'total' => $total, 'pages' => max(1, (int) ceil($total / $perPage))];
        } catch (Throwable $e) {
            return ['rows' => [], 'total' => 0, 'pages' => 1];
        }
    }

    public static function featured(): ?array
    {
        if (!self::tableReady()) return null;
        try {
            return Database::one(
                "SELECT p.*, c.name AS category_name, c.slug AS category_slug,
                        CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS author_name
                   FROM blog_posts p
                   LEFT JOIN blog_categories c ON c.id = p.category_id
                   LEFT JOIN users u ON u.id = p.author_id
                  WHERE p.status = 'published' AND (p.published_at IS NULL OR p.published_at <= NOW())
                  ORDER BY p.published_at DESC, p.id DESC LIMIT 1"
            );
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function findPublic(string $slug): ?array
    {
        if (!self::tableReady()) return null;
        try {
            return Database::one(
                "SELECT p.*, c.name AS category_name, c.slug AS category_slug,
                        CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS author_name
                   FROM blog_posts p
                   LEFT JOIN blog_categories c ON c.id = p.category_id
                   LEFT JOIN users u ON u.id = p.author_id
                  WHERE p.slug = ? AND p.status = 'published'
                    AND (p.published_at IS NULL OR p.published_at <= NOW()) LIMIT 1",
                [$slug]
            );
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function related(int $id, ?int $catId, int $limit = 3): array
    {
        if (!self::tableReady()) return [];
        try {
            if ($catId) {
                return Database::all(
                    "SELECT id, title, slug, summary, featured_image, published_at FROM blog_posts
                      WHERE id <> ? AND status = 'published' AND (published_at IS NULL OR published_at <= NOW())
                        AND category_id = ? ORDER BY published_at DESC LIMIT " . max(1, min(6, $limit)),
                    [$id, $catId]
                );
            }
            return Database::all(
                "SELECT id, title, slug, summary, featured_image, published_at FROM blog_posts
                  WHERE id <> ? AND status = 'published' AND (published_at IS NULL OR published_at <= NOW())
                  ORDER BY published_at DESC LIMIT " . max(1, min(6, $limit)),
                [$id]
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function bumpViews(int $id): void
    {
        try {
            Database::run("UPDATE blog_posts SET view_count = view_count + 1 WHERE id = ?", [$id]);
        } catch (Throwable $e) {
        }
    }

    public static function categories(): array
    {
        try {
            $has = Database::value("SHOW TABLES LIKE 'blog_categories'");
            if (!$has) return [];
            return Database::all("SELECT id, name, slug FROM blog_categories WHERE is_active = 1 ORDER BY name");
        } catch (Throwable $e) {
            return [];
        }
    }
}
