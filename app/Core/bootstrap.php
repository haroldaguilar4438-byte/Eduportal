<?php
declare(strict_types=1);

/**
 * EduPortal - Application bootstrap.
 * Datamex College of Saint Adeline
 *
 * Responsibilities:
 *   1. Load configuration + secrets
 *   2. Register the PSR-4 style autoloader (App\ -> app/, Lib\ -> app/lib/)
 *   3. Load procedural helpers (functions + mailer)
 *   4. Install error/exception handlers (never leak details in production)
 *   5. Start a hardened session cookie
 *
 * Routing is intentionally NOT done here so that non-MVC entry points
 * (PayRex webhook, CLI installer) can reuse this file safely. See index.php.
 */

require_once __DIR__ . '/../config/config.php';

/* ------------------------------ autoloader -------------------------------- */
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'App\\' => APP_ROOT . '/',
        'Lib\\' => APP_ROOT . '/lib/',
    ];
    foreach ($prefixes as $prefix => $baseDir) {
        if (str_starts_with($class, $prefix)) {
            $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
            $file = $baseDir . $relative . '.php';
            if (is_file($file)) {
                require $file;
                return;
            }
            // Fallback for case mismatch (e.g. app/core vs app/Core on Linux)
            $parts = explode('/', $relative);
            if (count($parts) > 1) {
                $parts[0] = strtolower($parts[0]);
                $alt = $baseDir . implode('/', $parts) . '.php';
                if (is_file($alt)) {
                    require $alt;
                    return;
                }
            }
            return;
        }
    }
});

require_once APP_ROOT . '/helpers/functions.php';
require_once APP_ROOT . '/helpers/ui.php';
require_once APP_ROOT . '/helpers/mailer.php';

/* --------------------------- error & exception handling -------------------- */
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (!is_dir(STORAGE_PATH)) {
    @mkdir(STORAGE_PATH, 0775, true);
}
ini_set('error_log', STORAGE_PATH . '/php-error.log');

set_exception_handler(static function (Throwable $e): void {
    $reqId = bin2hex(random_bytes(4));
    $path = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    error_log('[EduPortal][' . $reqId . '] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . ' | ' . $path);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    if (APP_DEBUG) {
        echo '<pre>' . e($e->__toString()) . '</pre>';
        exit;
    }
    echo '<!doctype html><html lang="en"><meta charset="utf-8">'
        . '<title>Service temporarily unavailable</title>'
        . '<body style="font-family:sans-serif;text-align:center;padding-top:12vh">'
        . '<h1>We&rsquo;ll be right back</h1><p>The system hit an unexpected error. '
        . 'Please try again in a few minutes.</p>'
        . '<p style="color:#94a3b8;font-size:.8rem">Reference: ' . e($reqId) . ' &middot; <a href="/">Back to portal</a></p></body></html>';
    exit;
});

/* ------------------------------- session start ----------------------------- */
if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('EDUPORTALSESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* Ensure CSRF token exists early for any form rendered this request. */
\App\Core\Csrf::token();
