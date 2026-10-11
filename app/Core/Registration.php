<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Registration guards (EXTENSION — existing auth/verification flows untouched).
 *
 * Centralizes the rules both the form (UX hints) and AuthController::register()
 * (authoritative backend) enforce:
 *   - personal-name quality: min 2 chars, real letters, no digits/symbols,
 *     small obvious-fake denylist (exact match only — never fuzzy, so
 *     legitimate names like "Li" or "O'Connor" always pass)
 *   - throttling of repeated attempts per IP / email (temporary, fail-open
 *     when the log table is not installed yet)
 *   - adaptive bot challenge: a simple math question appears only after
 *     suspicious volume from the same IP (no external CAPTCHA keys needed)
 *   - honeypot + minimum form-fill time
 */
final class Registration
{
    /** Temporary throttle windows (shared IPs are never blocked permanently). */
    public const IP_MAX_15MIN = 8;
    public const EMAIL_MAX_60MIN = 4;
    public const CAPTCHA_AFTER_IP_ATTEMPTS = 3;
    public const MIN_FILL_SECONDS = 3;

    /**
     * Obvious-fake tokens. Compared EXACTLY (lowercased, spaces/hyphens/
     * apostrophes stripped) so unusual-but-real names are never rejected.
     */
    private const FAKE_NAMES = [
        'test', 'tests', 'testing', 'tester',
        'asdf', 'asdfg', 'asdfgh', 'asdfghj',
        'qwerty', 'qwertyu', 'qwertyuiop',
        'zxcv', 'zxcvbn',
        'abc', 'abcd', 'abcde', 'abcdef',
        'xxxx', 'xxxxx', 'xxxxxx',
        'aaaa', 'aaaaa', 'zzzz', 'zzzzz',
        'demo', 'sample', 'example', 'foo', 'foobar',
        'admin', 'administrator', 'root',
        'null', 'undefined', 'temp', 'temporary',
        'unknown', 'none', 'noname', 'name',
        'firstname', 'lastname', 'middlename', 'surname',
        'user', 'newuser',
    ];

    /**
     * Validate one personal-name field. Returns an error message or null.
     * Allows spaces, hyphens, apostrophes (straight + curly), periods and
     * accented/unicode letters: "John Paul", "Anne-Marie", "O'Connor",
     * "José", "De La Cruz" all pass.
     */
    public static function validateName(?string $value, string $label, bool $required, int $max = 100): ?string
    {
        $v = trim((string) $value);
        if ($v === '') {
            return $required ? "$label is required." : null;
        }
        if (mb_strlen($v) < 2) {
            return "$label must contain at least 2 valid characters.";
        }
        if (mb_strlen($v) > $max) {
            return "$label must be $max characters or fewer.";
        }
        $letterCount = preg_match_all('/\p{L}/u', $v);
        if ($letterCount < 2) {
            return "$label must contain at least 2 letters.";
        }
        if (preg_match('/\d/u', $v)) {
            return "$label must not contain numbers.";
        }
        if (!preg_match("/^[\p{L} .'\-’‘]+$/u", $v)) {
            return "$label contains invalid characters.";
        }
        $normalized = mb_strtolower((string) preg_replace("/[\s\-'’‘.]+/u", '', $v));
        if (in_array($normalized, self::FAKE_NAMES, true)) {
            return "$label looks invalid — please enter your real $label.";
        }
        if ($normalized !== '' && preg_match('/^(.)\1+$/u', $normalized)) {
            return "$label looks invalid — please enter your real $label.";
        }
        return null;
    }

    /** Optional suffix: letters/periods only (Jr., Sr., II, III, IV ...). */
    public static function validateSuffix(?string $value): ?string
    {
        $v = trim((string) $value);
        if ($v === '') return null;
        if (mb_strlen($v) > 20) return 'Suffix must be 20 characters or fewer.';
        if (preg_match('/\d/u', $v)) return 'Suffix must not contain numbers.';
        if (!preg_match("/^[\p{L}. ]+$/u", $v)) return 'Suffix contains invalid characters.';
        if (preg_match_all('/\p{L}/u', $v) < 1) return 'Suffix looks invalid.';
        return null;
    }

    /* ------------------------- throttling ------------------------- */

    private static function hasTable(): bool
    {
        try {
            return Database::value("SHOW TABLES LIKE 'registration_attempts'") !== null;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Null = allowed; string = user-facing block message (temporary). */
    public static function checkThrottle(string $ip, string $email): ?string
    {
        if (!self::hasTable()) return null; // fail open pre-migration
        try {
            $ipHits = (int) Database::value(
                "SELECT COUNT(*) FROM registration_attempts WHERE ip_address = ? AND attempted_at > ?",
                [$ip, date('Y-m-d H:i:s', time() - 15 * 60)]
            );
            if ($ipHits >= self::IP_MAX_15MIN) {
                return 'Too many registration attempts. Please wait a few minutes and try again.';
            }
            if ($email !== '') {
                $emailHits = (int) Database::value(
                    "SELECT COUNT(*) FROM registration_attempts WHERE email = ? AND attempted_at > ?",
                    [mb_strtolower($email), date('Y-m-d H:i:s', time() - 60 * 60)]
                );
                if ($emailHits >= self::EMAIL_MAX_60MIN) {
                    return 'Too many registration attempts for this email. Please wait a while and try again.';
                }
            }
        } catch (Throwable $e) {
            return null;
        }
        return null;
    }

    public static function record(string $email, string $phone, string $ip, bool $success): void
    {
        if (!self::hasTable()) return;
        try {
            Database::insert('registration_attempts', [
                'email'      => $email !== '' ? mb_strtolower($email) : null,
                'phone'      => $phone !== '' ? $phone : null,
                'ip_address' => $ip,
                'success'    => $success ? 1 : 0,
                'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (Throwable $e) {
        }
    }

    public static function recentIpAttempts(string $ip, int $minutes = 15): int
    {
        if (!self::hasTable()) return 0;
        try {
            return (int) Database::value(
                "SELECT COUNT(*) FROM registration_attempts WHERE ip_address = ? AND attempted_at > ?",
                [$ip, date('Y-m-d H:i:s', time() - $minutes * 60)]
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /* --------------------- adaptive bot challenge --------------------- */

    /** Challenge appears only after suspicious volume from the same IP. */
    public static function captchaRequired(string $ip): bool
    {
        return self::recentIpAttempts($ip) >= self::CAPTCHA_AFTER_IP_ATTEMPTS;
    }

    /** Generate a math question; answer kept server-side in session. */
    public static function newCaptcha(): string
    {
        $a = random_int(2, 9);
        $b = random_int(2, 9);
        $_SESSION['reg_captcha_answer'] = (string) ($a + $b);
        return "What is $a + $b?";
    }

    public static function verifyCaptcha(?string $given): bool
    {
        $expected = $_SESSION['reg_captcha_answer'] ?? null;
        if ($expected === null) return true; // not required for this session
        return trim((string) $given) !== '' && trim((string) $given) === (string) $expected;
    }

    public static function clearCaptcha(): void
    {
        unset($_SESSION['reg_captcha_answer']);
    }
}
