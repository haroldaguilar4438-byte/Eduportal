<?php
declare(strict_types=1);

/**
 * EduPortal - global helper functions (procedural layer).
 * Loaded by core/bootstrap.php after config.
 */

/** HTML-escape output. Always use when echoing user data. Accepts mixed so an
 * unexpected int/array from the DB can never fatal with a TypeError (which
 * previously surfaced as the generic "We'll be right back" screen). */
function e(mixed $value): string
{
    if (is_array($value)) $value = implode(', ', array_map(static fn($v) => is_scalar($v) || $v === null ? (string) $v : '', $value));
    elseif (is_object($value)) $value = method_exists($value, '__toString') ? (string) $value : '';
    elseif ($value === null) $value = '';
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build an absolute URL from an app path. */
function url(string $path = '/'): string
{
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}

/**
 * Build a URL for something under /assets.
 *
 * Appends ?v=<filemtime> so browsers, proxies and the Hostinger edge cache
 * treat every edited asset as a brand-new object. Without this, a 7-day
 * max-age means visitors keep painting with a stale stylesheet for a week
 * after each deploy, and no amount of refreshing fixes it until the edge
 * entry expires. Missing files fall back to the unversioned URL.
 */
function asset(string $path): string
{
    $rel = 'assets/' . ltrim($path, '/');
    $url = url($rel);
    $file = ROOT_PATH . '/public_html/' . $rel;
    $mtime = @filemtime($file);
    if ($mtime !== false) {
        $url .= '?v=' . $mtime;
    }
    return $url;
}

/** Redirect and stop execution. */
function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

/* ------------------------------- flash messages ---------------------------- */

function set_flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $flashes = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return array_values($flashes);
}

function flash_field(): string
{
    // Convenience: render bootstrap-styled alerts for pending flashes.
    $html = '';
    foreach (get_flashes() as $f) {
        $cls = match ($f['type']) {
            'success' => 'success',
            'error', 'danger' => 'danger',
            'warning' => 'warning',
            default => 'info',
        };
        $html .= '<div class="alert alert-' . $cls . '">' . e($f['message']) . '</div>';
    }
    return $html;
}

/* ---------------------------------- CSRF ----------------------------------- */

function csrf_field(): string
{
    return \App\Core\Csrf::field();
}

/* ------------------------------ misc utilities ----------------------------- */

/** Client IP as reported by the web server (do NOT trust X-Forwarded-For on shared hosting). */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/** Format an amount in Philippine Pesos. */
function money(float|int|string|null $amount): string
{
    return '&#8369;' . number_format((float) $amount, 2);
}

/** Human-readable peso amount (for emails / plain text). */
function money_plain(float|int|string|null $amount): string
{
    return 'PHP ' . number_format((float) $amount, 2);
}

/** Short relative time for notification timestamps (e.g. 5m ago). */
function time_ago(?string $datetime): string
{
    if ($datetime === null || $datetime === '') return '';
    $ts = strtotime($datetime);
    if ($ts === false) return '';
    $diff = time() - $ts;
    if ($diff < 0) $diff = 0; // clock skew / tz mismatch must never show "Xh ago" for new rows
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return (int) floor($diff / 60) . 'm ago';
    if ($diff < 86400) return (int) floor($diff / 3600) . 'h ago';
    if ($diff < 86400 * 7) return (int) floor($diff / 86400) . 'd ago';
    return date('M j, Y', $ts);
}

/** Generate a human-friendly reference code, e.g. PAY-260826-A1B2C3. */
function ref_code(string $prefix): string
{
    return strtoupper($prefix . '-' . date('ymd') . '-' . bin2hex(random_bytes(3)));
}

/** Emit a JSON response and terminate. */
function json_response(mixed $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Append a line to the daily application log (outside web root). */
function storage_log(string $message): void
{
    @file_put_contents(
        STORAGE_PATH . '/app-' . date('Y-m-d') . '.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

/* --------------------------- navigation helpers --------------------------- */

/**
 * Normalized current request path (e.g. /admin/enrollments).
 * Mirrors Router::dispatch base stripping so active state works under
 * a /…/public_html subfolder as well as DocumentRoot.
 *
 * Apache percent-encodes REQUEST_URI but percent-decodes SCRIPT_NAME, so a
 * subfolder containing a space (%20) or another encoded character must be
 * compared in decoded form — otherwise every navigation item loses its
 * active highlight.
 */
function current_path(): string
{
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    $path = '/' . trim($path, '/');
    if ($path !== '/') {
        $path = rtrim($path, '/');
    }

    // Strip /public_html base like Router does
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $pos = strpos($script, '/public_html');
    if ($pos !== false) {
        $base = rtrim(substr($script, 0, $pos + strlen('/public_html')), '/');
        if ($base !== '' && $base !== '/') {
            $decodedBase = rawurldecode($base);
            $decodedPath = rawurldecode($path);
            if (str_starts_with($decodedPath, $decodedBase)) {
                $path = substr($decodedPath, strlen($decodedBase));
                $path = '/' . trim($path, '/');
                if ($path !== '/') {
                    $path = rtrim($path, '/');
                }
            }
        }
    }

    return $path === '' ? '/' : $path;
}

/**
 * Whether $target path is active for the current request.
 * - Exact:  /dashboard === current (only exact match)
 * - Prefix: /admin/enrollments matches /admin/enrollments and /admin/enrollments/123
 */
function is_active_path(string $target, bool $exact = false): bool
{
    $current = current_path();
    $target  = '/' . trim($target, '/');
    if ($target !== '/') $target = rtrim($target, '/');
    if ($target === '') $target = '/';
    if ($exact) {
        return $current === $target;
    }
    if ($target === '/') {
        return $current === '/';
    }
    return $current === $target || str_starts_with($current, $target . '/');
}

/**
 * Convenience: return active vs inactive tailwind class string.
 */
function nav_classes(string $target, string $active, string $inactive, bool $exact = false): string
{
    return is_active_path($target, $exact) ? $active : $inactive;
}
