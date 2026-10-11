<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Shared content helpers for Blog + Announcements.
 * Reuses: e() for plain text, Uploads for images, Database for uniqueness.
 * No new framework — pure static helpers called by controllers/views.
 */
final class Content
{
    /** Allowed rich-text tags for blog/announcement bodies. */
    private const ALLOWED_TAGS = '<p><br><b><strong><i><em><u><ol><ul><li><h2><h3><h4><blockquote><a><img><hr><table><thead><tbody><tr><th><td><pre><code><span><div>';

    /** @return string URL-safe slug, max 200 chars */
    public static function slugify(string $text): string
    {
        $text = mb_strtolower(trim($text));
        // transliterate accents
        $text = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        if ($text === '') $text = 'post';
        return mb_substr($text, 0, 200);
    }

    /** Ensure slug unique in $table (blog_posts). Appends -2, -3 ... */
    public static function uniqueSlug(string $table, string $base, ?int $ignoreId = null): string
    {
        $slug = $base !== '' ? $base : 'post';
        $try = $slug;
        $i = 2;
        while (true) {
            $sql = "SELECT id FROM `{$table}` WHERE slug = ? LIMIT 1";
            // table name is internal constant only — callers pass literals
            $row = Database::one($sql, [$try]);
            if ($row === null || ($ignoreId !== null && (int)$row['id'] === $ignoreId)) {
                return $try;
            }
            $suffix = '-' . $i++;
            $try = mb_substr($slug, 0, 200 - mb_strlen($suffix)) . $suffix;
        }
    }

    /**
     * Sanitize rich HTML with a strict allowlist.
     * - strips script/style/iframe/object/embed/form + event handlers
     * - allows only safe href (http/https/mailto/relative) and safe img src
     */
    public static function cleanHtml(string $html): string
    {
        if (trim($html) === '') return '';
        // strip disallowed structures first
        $html = (string) preg_replace('#<(script|style|iframe|object|embed|form|input|button|select|textarea|meta|link)[^>]*>.*?</\1>#is', '', $html);
        $html = (string) preg_replace('#<(script|style|iframe|object|embed)[^>]*/?>#i', '', $html);
        $html = strip_tags($html, self::ALLOWED_TAGS);
        // remove event handlers + javascript:/data: URLs + style attr
        $html = (string) preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = (string) preg_replace('/\sstyle\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = (string) preg_replace_callback('/<(a|img)\b[^>]*>/i', static function (array $m): string {
            $tag = $m[0];
            // neutralize javascript:/data:/vbscript: in href/src
            $tag = (string) preg_replace_callback('/\s(href|src)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', static function (array $mm): string {
                $attr = strtolower($mm[1]);
                $val = trim($mm[2], "\"' ");
                if (preg_match('#^\s*(javascript|data|vbscript|file):#i', $val)) {
                    return '';
                }
                if ($attr === 'href' && !preg_match('#^(https?://|mailto:|/|#)#i', $val) && $val !== '') {
                    return '';
                }
                // img src must be relative uploads path or https
                if ($attr === 'src' && !preg_match('#^(https://|/uploads/|uploads/)#i', $val) && $val !== '') {
                    return '';
                }
                return ' ' . $attr . '="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"';
            }, $tag);
            // strip everything except href/src/alt/title/width/height/loading/target/rel/class
            $tag = (string) preg_replace_callback('/<(\/?)(a|img)\b([^>]*)>/i', static function (array $mm): string {
                $close = $mm[1];
                $name = strtolower($mm[2]);
                $attrs = $mm[3];
                if ($close === '/') return "</{$name}>";
                preg_match_all('/\s([a-zA-Z-]+)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\']+)/', $attrs, $am, PREG_SET_ORDER);
                $keep = ['href','src','alt','title','width','height','loading','target','rel','class'];
                $out = '';
                foreach ($am as $a) {
                    $k = strtolower($a[1]);
                    if (!in_array($k, $keep, true)) continue;
                    $v = trim($a[2], "\"' ");
                    if (($k === 'href' || $k === 'src') && preg_match('#^\s*(javascript|data|vbscript|file):#i', $v)) continue;
                    $out .= ' ' . $k . '="' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . '"';
                }
                // force safe link behavior
                if ($name === 'a' && str_contains($out, 'http')) {
                    if (!str_contains($out, 'rel=')) $out .= ' rel="noopener"';
                    if (!str_contains($out, 'target=')) $out .= ' target="_blank"';
                }
                if ($name === 'img' && !str_contains($out, 'loading=')) $out .= ' loading="lazy"';
                if ($name === 'img' && !str_contains($out, 'alt=')) $out .= ' alt=""';
                return "<{$name}{$out}>";
            }, $tag);
            return $tag;
        }, $html);
        return trim($html);
    }

    /** Plain-text excerpt, multibyte safe. */
    public static function excerpt(string $htmlOrText, int $len = 160): string
    {
        $t = trim(strip_tags($htmlOrText));
        $t = (string) preg_replace('/\s+/', ' ', $t);
        if (mb_strlen($t) <= $len) return $t;
        return mb_substr($t, 0, $len - 1) . '…';
    }

    /** Normalize tags: lowercase, dedupe, max 10. */
    public static function normalizeTags(string $raw): string
    {
        $parts = preg_split('/[,;]+/', mb_strtolower($raw)) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim((string) preg_replace('/[^a-z0-9\- ]+/', '', $p));
            $p = trim(preg_replace('/\s+/', ' ', $p) ?? '');
            if ($p !== '' && !in_array($p, $out, true)) $out[] = $p;
            if (count($out) >= 10) break;
        }
        return implode(', ', $out);
    }

    /** Valid announcement types (configurable list, not hard-coded in views). */
    public static function announcementTypes(): array
    {
        return [
            'general' => 'General Announcement',
            'enrollment' => 'Enrollment Notice',
            'payment' => 'Payment Notice',
            'academic' => 'Academic Notice',
            'examination' => 'Examination Notice',
            'campus' => 'Campus Notice',
            'emergency' => 'Emergency / System Notice',
        ];
    }

    /** Audiences matching the existing enum + staff split used by the app. */
    public static function audiences(): array
    {
        return [
            'everyone' => 'All users',
            'students' => 'Students',
            'parents' => 'Parents',
            'teachers' => 'Teachers',
            'staff' => 'Staff (Registrar + Accounting)',
            'registrar' => 'Registrar only',
            'accounting' => 'Accounting only',
            'class' => 'Specific section (class)',
        ];
    }

    /**
     * Map a viewer's role to the announcement audience values they may see.
     * Server-side filtering — never rely on JS hiding.
     */
    public static function audiencesForRole(string $role): array
    {
        return match ($role) {
            'admin' => ['everyone','students','parents','teachers','staff','registrar','accounting','class'],
            'registrar' => ['everyone','staff','registrar'],
            'accounting' => ['everyone','staff','accounting'],
            'teacher' => ['everyone','teachers'],
            'student' => ['everyone','students'],
            'parent' => ['everyone','parents'],
            default => [],
        };
    }

    /**
     * Validate an uploaded image (actual contents, not just extension).
     * Returns [ok, error, storedRelativePath].
     */
    public static function storeImage(array $file, string $subdir, string $prefix, int $maxBytes = 2097152): array
    {
        if (!isset($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [true, '', null];
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return [false, 'Upload failed. Please try again.', null];
        }
        if (($file['size'] ?? 0) > $maxBytes) {
            return [false, 'Image too large (max 2 MB).', null];
        }
        $tmp = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            return [false, 'Invalid upload.', null];
        }
        $info = @getimagesize($tmp);
        if ($info === false) {
            return [false, 'File is not a valid image.', null];
        }
        [$w, $h, $type] = $info;
        if ($w < 50 || $h < 50 || $w > 4000 || $h > 4000) {
            return [false, 'Image dimensions must be between 50px and 4000px.', null];
        }
        $mime = (string) ($info['mime'] ?? '');
        if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
            return [false, 'Only JPG, PNG, or WebP images are allowed.', null];
        }
        $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
        // Double-check extension allowlist via existing Uploads helper
        $safe = Uploads::safeExt('photo.' . $ext, $mime);
        if ($safe === null || !in_array($safe, ['jpg','jpeg','png','webp'], true)) {
            return [false, 'Image type not allowed.', null];
        }
        // Re-encode check: refuse SVG masquerading (getimagesize already fails SVG)
        $dir = rtrim(UPLOAD_DIR, '/\\') . '/' . trim($subdir, '/');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        // Prevent path traversal: generated name only
        $name = Uploads::storedName($prefix, $ext === 'jpeg' ? 'jpg' : $ext);
        $dest = $dir . '/' . $name;
        if (!@move_uploaded_file($tmp, $dest)) {
            return [false, 'Could not save image.', null];
        }
        @chmod($dest, 0644);
        return [true, '', 'uploads/' . trim($subdir, '/') . '/' . $name];
    }

    /** Audit helper — reuses existing audit_logs table, never logs secrets. */
    public static function audit(int $actorId, string $action, string $entityType, int $entityId, array $extra = []): void
    {
        try {
            Database::insert('audit_logs', [
                'actor_user_id' => $actorId,
                'action' => mb_substr($action, 0, 60),
                'entity_type' => mb_substr($entityType, 0, 40),
                'entity_id' => $entityId,
                'new_values_json' => $extra === [] ? null : mb_substr((string) json_encode($extra), 0, 4000),
                'ip_address' => client_ip(),
                'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (\Throwable $e) {
            storage_log('content audit failed: ' . $e->getMessage());
        }
    }
}
