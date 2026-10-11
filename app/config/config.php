<?php
declare(strict_types=1);

/**
 * EduPortal - Application configuration
 * Datamex College of Saint Adeline
 *
 * Non-secret settings live here. Secrets (DB, SMTP, PayRex) live in
 * credentials.php - copy credentials.example.php -> credentials.php on the
 * server and fill in real values. Both files sit OUTSIDE public_html.
 */

define('APP_NAME', 'EduPortal');
define('APP_ENV', getenv('APP_ENV') ?: 'development');          // production | development
define('APP_DEBUG', APP_ENV === 'development');

// Dynamic BASE_URL — localhost always wins over env (fixes verify email links on XAMPP)
$hostHint = $_SERVER['HTTP_HOST'] ?? '';
$isLocalHost = $hostHint !== '' && (
    $hostHint === 'localhost' || $hostHint === '127.0.0.1' || $hostHint === '::1'
    || str_starts_with($hostHint, 'localhost:') || str_starts_with($hostHint, '127.0.0.1:')
);
$envBase = getenv('BASE_URL');
if ($envBase && !$isLocalHost) {
    define('BASE_URL', rtrim((string) $envBase, '/'));
} elseif (PHP_SAPI === 'cli') {
    define('BASE_URL', 'http://localhost');
} else {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $base   = '';
    if ($script !== '' && str_contains($script, '/public_html')) {
        $pos  = strpos($script, '/public_html');
        $base = substr($script, 0, $pos + strlen('/public_html'));
    } elseif ($script !== '') {
        $base = rtrim(dirname($script), '/\\');
        if ($base === '/' || $base === '.' || $base === '\\') $base = '';
    }
    define('BASE_URL', rtrim($scheme . '://' . $host . $base, '/'));
}

// Filesystem paths (project root = parent of /app; on Hostinger this is the
// account home directory, one level above public_html).
define('ROOT_PATH', dirname(__DIR__, 2));
define('STORAGE_PATH', ROOT_PATH . '/logs');
define('UPLOAD_DIR', ROOT_PATH . '/public_html/uploads');

date_default_timezone_set('Asia/Manila');
mb_internal_encoding('UTF-8');

/* ------------------------- Security & session limits ------------------------ */
const SESSION_IDLE_TIMEOUT = 30 * 60;   // auto-logout after 30 min idle (paper spec)
const LOGIN_MAX_ATTEMPTS   = 5;         // failed attempts allowed ...
const LOGIN_WINDOW         = 15 * 60;   // ... within this window (per IP + per email)
const LOGIN_LOCKOUT        = 15 * 60;   // lock duration once threshold is hit

/* ------------------------------- Token lifetimes ---------------------------- */
const VERIFY_TOKEN_TTL = 24 * 3600;     // email verification link validity
const RESET_TOKEN_TTL  = 1 * 3600;      // password reset link validity
const OTP_TTL          = 10 * 60;       // one-time password validity

/* ------------------- MFA + new-device verification limits ----------------- */
const PENDING_LOGIN_TTL  = 15 * 60;     // password-OK pending challenge validity
const DEVICE_VERIFY_TTL  = 15 * 60;     // new-device email link validity (= pending TTL)
const DEVICE_COOKIE_TTL  = 180 * 86400; // trusted-device cookie + rolling DB expiry
const MFA_MAX_ATTEMPTS   = 5;           // wrong TOTP/recovery codes before lockout
const MFA_LOCKOUT        = 15 * 60;     // MFA lockout duration
const MFA_PENDING_MAX    = 10;          // wrong codes per single login challenge
const RECOVERY_CODE_COUNT = 10;         // recovery codes per generated set
const MFA_EXEMPT_DAYS    = 30;          // "remember this device" MFA-skip duration

/* --------------------------------- Uploads ---------------------------------- */
const UPLOAD_MAX_BYTES = 10 * 1024 * 1024;
const UPLOAD_ALLOWED_MIME = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'image/webp',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];

/* Load secrets (never commit real values) */
require __DIR__ . '/credentials.php';
