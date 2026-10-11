<?php
declare(strict_types=1);

namespace App\Core;

/**
 * CSRF protection: per-session token validated with hash_equals().
 * Apply Csrf::guard() to every state-changing POST endpoint.
 */
final class Csrf
{
    /** Get (or lazily create) the session token. */
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    /** Hidden input for forms. */
    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function validate(?string $token): bool
    {
        return is_string($token)
            && $token !== ''
            && hash_equals(self::token(), $token);
    }

    /**
     * Validate the current request (POST body `_csrf` or `X-CSRF-Token` header).
     * Terminates with HTTP 419 on failure.
     */
    public static function guard(): void
    {
        $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!self::validate(is_string($token) ? $token : null)) {
            http_response_code(419);
            if (
                ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
                || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            ) {
                json_response(['error' => 'CSRF token mismatch. Refresh the page and try again.'], 419);
            }
            exit('419 - Invalid security token. Please go back, refresh the page, and try again.');
        }
    }
}
