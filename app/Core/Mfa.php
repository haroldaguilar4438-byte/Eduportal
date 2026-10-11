<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * MFA (TOTP) + new-device verification + trusted devices + session registry.
 * Datamex College of Saint Adeline - EduPortal
 *
 * Centralizes ALL step-up security logic so controllers stay thin and every
 * authentication decision happens server-side:
 *
 *   KNOWN DEVICE : password -> MFA -> full session
 *   NEW DEVICE   : password -> email verification -> MFA -> full session
 *
 * - TOTP follows RFC 6238 (SHA-1, 30s step, 6 digits, +/-1 window).
 * - Secrets are AES-256-CBC encrypted (key: INVITE_ENC_KEY) before storage
 *   and NEVER logged, emailed, or exposed outside the setup/verify flow.
 * - Recovery codes are stored as password_hash() only; plain codes are shown
 *   exactly once at generation time.
 * - Device / email-link tokens are stored as SHA-256 hashes; cookies are
 *   HttpOnly + SameSite=Lax (+ Secure on HTTPS).
 * - Security-relevant events reuse the existing audit_logs table.
 */
final class Mfa
{
    public const ISSUER = 'EduPortal-Datamex';
    public const STEP_SECONDS = 30;
    public const CODE_DIGITS = 6;
    public const VERIFY_WINDOW = 1;

    /* =====================================================================
     *  TOTP (RFC 6238)
     * ===================================================================== */

    /** Generate a new base32 TOTP secret (default 160-bit). */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /** otpauth:// URL rendered as QR code on the setup page. */
    public static function otpauthUrl(string $email, string $secret): string
    {
        $label = rawurlencode(self::ISSUER . ':' . mb_strtolower(trim($email)));
        return 'otpauth://totp/' . $label
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode(self::ISSUER)
            . '&algorithm=SHA1&digits=6&period=30';
    }

    /** Verify a 6-digit code against the secret (window +/-1 step). */
    public static function verifyCode(string $secret, string $code): bool
    {
        return self::verifyCodeWithCounter($secret, $code) !== null;
    }

    /**
     * Verify a code and return the matched time-step counter, or null.
     * The +/-1 window tolerates phone/server clock drift only.
     */
    public static function verifyCodeWithCounter(string $secret, string $code): ?int
    {
        $code = preg_replace('/\D/', '', trim($code)) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $key = self::base32Decode($secret);
        if ($key === null) {
            return null;
        }
        $now = (int) floor(time() / self::STEP_SECONDS);
        for ($i = -self::VERIFY_WINDOW; $i <= self::VERIFY_WINDOW; $i++) {
            if (hash_equals(self::hotp($key, $now + $i), $code)) {
                return $now + $i;
            }
        }
        return null;
    }

    private static function hotp(string $key, int $counter): string
    {
        $bin = pack('J', $counter); // 64-bit big-endian
        $hash = hash_hmac('sha1', $bin, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $val = ((ord($hash[$offset]) & 0x7f) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);
        return str_pad((string) ($val % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $out = '';
        $buffer = 0;
        $bits = 0;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $buffer = ($buffer << 8) | ord($data[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= $alphabet[($buffer >> $bits) & 31];
            }
        }
        if ($bits > 0) {
            $out .= $alphabet[($buffer << (5 - $bits)) & 31];
        }
        return $out;
    }

    public static function base32Decode(string $input): ?string
    {
        $input = strtoupper(str_replace([' ', '-', '='], '', trim($input)));
        if ($input === '' || !preg_match('/^[A-Z2-7]+$/', $input)) {
            return null;
        }
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $out = '';
        $buffer = 0;
        $bits = 0;
        $len = strlen($input);
        for ($i = 0; $i < $len; $i++) {
            $buffer = ($buffer << 5) | (int) strpos($alphabet, $input[$i]);
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($buffer >> $bits) & 0xff);
            }
        }
        return $out === '' ? null : $out;
    }

    /* =====================================================================
     *  Secret protection (AES-256-CBC, reuses server-side INVITE_ENC_KEY)
     * ===================================================================== */

    public static function protectSecret(string $secret): string
    {
        try {
            $keyHex = defined('INVITE_ENC_KEY') ? (string) INVITE_ENC_KEY : '';
            if (strlen($keyHex) >= 32 && function_exists('openssl_encrypt')) {
                $key = hex2bin(substr($keyHex, 0, 64)) ?: hash('sha256', $keyHex, true);
                $iv = random_bytes(16);
                $ct = openssl_encrypt($secret, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
                if ($ct !== false) {
                    return 'ENC:' . base64_encode($iv . $ct);
                }
            }
        } catch (Throwable $e) {
        }
        // Fail-closed storage is impossible without a key; fall back to a
        // server-side-only record (DB access is already restricted). The
        // plaintext secret is NEVER logged, dumped, or emailed.
        return 'RAW:' . $secret;
    }

    public static function unprotectSecret(string $stored): ?string
    {
        try {
            if (str_starts_with($stored, 'ENC:')) {
                $keyHex = defined('INVITE_ENC_KEY') ? (string) INVITE_ENC_KEY : '';
                $raw = base64_decode(substr($stored, 4), true);
                if ($raw === false || strlen($raw) <= 16 || $keyHex === '') {
                    return null;
                }
                $key = hex2bin(substr($keyHex, 0, 64)) ?: hash('sha256', $keyHex, true);
                $pt = openssl_decrypt(
                    substr($raw, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16)
                );
                return $pt === false ? null : $pt;
            }
            if (str_starts_with($stored, 'RAW:')) {
                return substr($stored, 4);
            }
        } catch (Throwable $e) {
        }
        return null;
    }

    /* =====================================================================
     *  MFA record lifecycle
     * ===================================================================== */

    public static function record(int $userId): ?array
    {
        try {
            return Database::one('SELECT * FROM user_mfa WHERE user_id = ? LIMIT 1', [$userId]);
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function isEnabled(int $userId): bool
    {
        $r = self::record($userId);
        return $r !== null && (int) $r['is_enabled'] === 1;
    }

    /**
     * Start (or restart) setup: stores a FRESH secret, disabled until the
     * user proves possession with a valid code. Returns secret + otpauth URL.
     */
    public static function beginSetup(int $userId): array
    {
        $secret = self::generateSecret();
        try {
            Database::run(
                'INSERT INTO user_mfa (user_id, secret, is_enabled, failed_attempts, locked_until, verified_at)
                 VALUES (?, ?, 0, 0, NULL, NULL)
                 ON DUPLICATE KEY UPDATE secret = VALUES(secret), is_enabled = 0,
                     failed_attempts = 0, locked_until = NULL, verified_at = NULL',
                [$userId, self::protectSecret($secret)]
            );
        } catch (Throwable $e) {
            storage_log('mfa beginSetup failed for ' . $userId . ': ' . $e->getMessage());
        }
        $email = '';
        try {
            $u = Database::one('SELECT email FROM users WHERE id = ? LIMIT 1', [$userId]);
            $email = (string) ($u['email'] ?? '');
        } catch (Throwable $e) {
        }
        return ['secret' => $secret, 'otpauth' => self::otpauthUrl($email, $secret)];
    }

    /**
     * Confirm setup with a valid TOTP code. Enables MFA + issues recovery
     * codes (plain, show-once). Returns ['ok'=>bool, ...].
     */
    public static function confirmSetup(int $userId, string $code): array
    {
        $rec = self::record($userId);
        if ($rec === null) {
            return ['ok' => false, 'message' => 'No MFA setup in progress. Please start setup again.'];
        }
        if ((int) $rec['is_enabled'] === 1) {
            return ['ok' => false, 'message' => 'MFA is already enabled for this account.'];
        }
        $secret = self::unprotectSecret((string) $rec['secret']);
        $counter = $secret === null ? null : self::verifyCodeWithCounter($secret, $code);
        if ($secret === null || $counter === null) {
            self::event($userId, 'mfa.setup_failed');
            return ['ok' => false, 'message' => 'Incorrect MFA code. Please try again.'];
        }
        try {
            // Seed the replay counter so the setup code itself cannot be
            // reused as the first login code within the same 30s window.
            Database::run(
                'UPDATE user_mfa SET is_enabled = 1, verified_at = NOW(), failed_attempts = 0, locked_until = NULL, last_used_counter = ?
                  WHERE user_id = ?',
                [$counter, $userId]
            );
        } catch (Throwable $e) {
            // Column may predate the replay patch: enable without seeding.
            try {
                Database::run(
                    'UPDATE user_mfa SET is_enabled = 1, verified_at = NOW(), failed_attempts = 0, locked_until = NULL WHERE user_id = ?',
                    [$userId]
                );
            } catch (Throwable $e2) {
                return ['ok' => false, 'message' => 'Could not enable MFA. Please try again.'];
            }
        }
        $codes = self::generateRecoveryCodes($userId);
        self::event($userId, 'mfa.enabled');
        return ['ok' => true, 'message' => 'MFA enabled. Your account is now protected.', 'codes' => $codes];
    }

    public static function disable(int $userId): void
    {
        try {
            Database::run('DELETE FROM user_mfa WHERE user_id = ?', [$userId]);
            Database::run('DELETE FROM mfa_recovery_codes WHERE user_id = ?', [$userId]);
        } catch (Throwable $e) {
            storage_log('mfa disable failed for ' . $userId . ': ' . $e->getMessage());
        }
        self::event($userId, 'mfa.disabled');
    }

    /**
     * Verify an MFA challenge (TOTP code OR single-use recovery code).
     * Enforces attempt limits + temporary lockout server-side.
     */
    public static function verifyChallenge(int $userId, string $code): array
    {
        $rec = self::record($userId);
        if ($rec === null || (int) $rec['is_enabled'] !== 1) {
            return ['ok' => false, 'message' => 'MFA is not enabled for this account.'];
        }
        if ($rec['locked_until'] !== null && strtotime((string) $rec['locked_until']) > time()) {
            $mins = (int) ceil((strtotime((string) $rec['locked_until']) - time()) / 60);
            return ['ok' => false, 'locked' => true, 'message' => "Too many incorrect MFA attempts. Try again in {$mins} minute(s)."];
        }
        $code = trim($code);

        // 1) TOTP path (6 digits)
        if (preg_match('/^\d{6}$/', preg_replace('/\s/', '', $code))) {
            $secret = self::unprotectSecret((string) $rec['secret']);
            $counter = $secret === null ? null : self::verifyCodeWithCounter($secret, $code);
            if ($counter !== null) {
                // Single-use: a code from an already-consumed time-step is
                // dead, even inside its 30s validity window.
                $lastUsed = (int) ($rec['last_used_counter'] ?? 0);
                if ($counter <= $lastUsed) {
                    self::event($userId, 'mfa.reused_code');
                    return ['ok' => false, 'message' => 'That code was already used. Wait for a new code and try again.'];
                }
                try {
                    Database::run(
                        'UPDATE user_mfa SET last_used_counter = ? WHERE user_id = ? AND last_used_counter < ?',
                        [$counter, $userId, $counter]
                    );
                } catch (Throwable $e) {
                    // Column may predate the replay patch: accept the code
                    // (drift window still enforced by verifyCodeWithCounter).
                }
                self::resetAttempts($userId);
                self::event($userId, 'mfa.success');
                return ['ok' => true];
            }
            self::registerFailure($userId, (int) $rec['failed_attempts']);
            self::event($userId, 'mfa.failed');
            return ['ok' => false, 'message' => 'Incorrect MFA code. Please try again.'];
        }

        // 2) Recovery-code path (format XXXX-XXXX, case-insensitive)
        $norm = strtoupper(str_replace([' ', '-'], '', $code));
        if (preg_match('/^[A-Z0-9]{8}$/', $norm)) {
            try {
                $rows = Database::all(
                    'SELECT id, code_hash FROM mfa_recovery_codes WHERE user_id = ? AND used_at IS NULL',
                    [$userId]
                );
            } catch (Throwable $e) {
                $rows = [];
            }
            foreach ($rows as $row) {
                if (password_verify($norm, (string) $row['code_hash'])) {
                    try {
                        Database::run(
                            'UPDATE mfa_recovery_codes SET used_at = NOW() WHERE id = ? AND used_at IS NULL',
                            [(int) $row['id']]
                        );
                    } catch (Throwable $e) {
                    }
                    self::resetAttempts($userId);
                    self::event($userId, 'mfa.recovery_used', ['code_id' => (int) $row['id']]);
                    return ['ok' => true, 'recovery' => true];
                }
            }
            self::registerFailure($userId, (int) $rec['failed_attempts']);
            self::event($userId, 'mfa.failed');
            return ['ok' => false, 'message' => 'Incorrect MFA code. Please try again.'];
        }

        return ['ok' => false, 'message' => 'Enter the 6-digit code from your authenticator app, or a recovery code (XXXX-XXXX).'];
    }

    private static function registerFailure(int $userId, int $current): void
    {
        $next = $current + 1;
        try {
            if ($next >= MFA_MAX_ATTEMPTS) {
                Database::run(
                    'UPDATE user_mfa SET failed_attempts = ?, locked_until = ? WHERE user_id = ?',
                    [$next, date('Y-m-d H:i:s', time() + MFA_LOCKOUT), $userId]
                );
            } else {
                Database::run('UPDATE user_mfa SET failed_attempts = ? WHERE user_id = ?', [$next, $userId]);
            }
        } catch (Throwable $e) {
        }
    }

    private static function resetAttempts(int $userId): void
    {
        try {
            Database::run('UPDATE user_mfa SET failed_attempts = 0, locked_until = NULL WHERE user_id = ?', [$userId]);
        } catch (Throwable $e) {
        }
    }

    /* =====================================================================
     *  Recovery codes (hash-only storage, single-use, show-once)
     * ===================================================================== */

    /** Generate a fresh set; invalidates the previous set. Returns PLAIN codes. */
    public static function generateRecoveryCodes(int $userId, int $count = RECOVERY_CODE_COUNT): array
    {
        $plain = [];
        for ($i = 0; $i < $count; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(4))); // 8 hex chars
            $plain[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
        }
        try {
            Database::transaction(function () use ($userId, $plain) {
                Database::run('DELETE FROM mfa_recovery_codes WHERE user_id = ?', [$userId]);
                foreach ($plain as $c) {
                    Database::insert('mfa_recovery_codes', [
                        'user_id' => $userId,
                        'code_hash' => password_hash(str_replace('-', '', $c), PASSWORD_DEFAULT),
                    ]);
                }
            });
        } catch (Throwable $e) {
            storage_log('recovery gen failed for ' . $userId . ': ' . $e->getMessage());
            return [];
        }
        self::event($userId, 'mfa.recovery_generated');
        return $plain;
    }

    public static function recoveryRemaining(int $userId): int
    {
        try {
            return (int) Database::value(
                'SELECT COUNT(*) FROM mfa_recovery_codes WHERE user_id = ? AND used_at IS NULL',
                [$userId]
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /* =====================================================================
     *  Trusted devices
     * ===================================================================== */

    public static function deviceCookieName(): string
    {
        return 'edu_device';
    }

    public static function deviceCookieParams(int $expiry): array
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return [
            'expires' => $expiry, 'path' => '/', 'domain' => '',
            'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax',
        ];
    }

    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Validate the request device cookie against non-revoked, unexpired rows. */
    public static function trustedDeviceForRequest(int $userId): ?array
    {
        $cookie = (string) ($_COOKIE[self::deviceCookieName()] ?? '');
        if ($cookie === '' || !preg_match('/^[a-f0-9]{64}$/', $cookie)) {
            return null;
        }
        try {
            $row = Database::one(
                'SELECT * FROM trusted_devices
                  WHERE user_id = ? AND token_hash = ? AND revoked_at IS NULL AND expires_at > NOW() LIMIT 1',
                [$userId, self::tokenHash($cookie)]
            );
        } catch (Throwable $e) {
            return null;
        }
        return $row;
    }

    /**
     * Trust the current browser: high-entropy token, hash-only storage,
     * rolling 180-day expiry. Sets the HttpOnly cookie. Returns the device id.
     */
    public static function trustCurrentDevice(int $userId, string $ip, string $ua, string $deviceName): int
    {
        $token = bin2hex(random_bytes(32));
        $id = 0;
        try {
            $id = Database::insert('trusted_devices', [
                'user_id' => $userId,
                'token_hash' => self::tokenHash($token),
                'device_name' => mb_substr($deviceName !== '' ? $deviceName : self::deviceName($ua), 0, 191),
                'user_agent' => mb_substr($ua, 0, 255),
                'ip_address' => $ip,
                'last_used_at' => date('Y-m-d H:i:s'),
                'expires_at' => date('Y-m-d H:i:s', time() + DEVICE_COOKIE_TTL),
            ]);
        } catch (Throwable $e) {
            storage_log('trust device failed for ' . $userId . ': ' . $e->getMessage());
            return 0;
        }
        setcookie(self::deviceCookieName(), $token, self::deviceCookieParams(time() + DEVICE_COOKIE_TTL));
        $_COOKIE[self::deviceCookieName()] = $token;
        self::event($userId, 'device.trusted', ['device_id' => $id]);
        return $id;
    }

    /* ---------------- "Remember this device" MFA exemption ----------------
     * Opt-in, 30-day skip of the TOTP challenge on an ALREADY-TRUSTED
     * device. Password is still required every login; new devices always
     * face email + MFA. Revocable per device; cleared on password change.
     * ------------------------------------------------------------------ */

    /** True when the trusted-device row carries a live MFA exemption. */
    public static function isMfaExempt(?array $deviceRow): bool
    {
        return $deviceRow !== null
            && empty($deviceRow['revoked_at'])
            && !empty($deviceRow['mfa_exempt_until'])
            && strtotime((string) $deviceRow['mfa_exempt_until']) > time();
    }

    /** Grant a 30-day MFA exemption on a trusted device row. */
    public static function grantMfaExemption(int $userId, int $deviceId): void
    {
        try {
            Database::run(
                'UPDATE trusted_devices SET mfa_exempt_until = DATE_ADD(NOW(), INTERVAL ? DAY)
                  WHERE id = ? AND user_id = ? AND revoked_at IS NULL AND expires_at > NOW()',
                [MFA_EXEMPT_DAYS, $deviceId, $userId]
            );
        } catch (Throwable $e) {
            storage_log('mfa exemption grant failed: ' . $e->getMessage());
            return;
        }
        self::event($userId, 'device.mfa_exempt_granted', ['device_id' => $deviceId, 'days' => MFA_EXEMPT_DAYS]);
    }

    /** Drop the exemption but keep the device trusted (still skips email). */
    public static function forgetMfaExemption(int $userId, int $deviceId): bool
    {
        try {
            $n = Database::run(
                'UPDATE trusted_devices SET mfa_exempt_until = NULL WHERE id = ? AND user_id = ?',
                [$deviceId, $userId]
            )->rowCount();
        } catch (Throwable $e) {
            return false;
        }
        if ($n > 0) {
            self::event($userId, 'device.mfa_exempt_revoked', ['device_id' => $deviceId]);
            return true;
        }
        return false;
    }

    /** Clear every MFA exemption of a user (password change / reset). */
    public static function clearAllMfaExemptions(int $userId): void
    {
        try {
            Database::run(
                'UPDATE trusted_devices SET mfa_exempt_until = NULL WHERE user_id = ? AND mfa_exempt_until IS NOT NULL',
                [$userId]
            );
        } catch (Throwable $e) {
        }
    }

    public static function touchTrustedDevice(int $deviceId, string $ip): void
    {
        try {
            Database::run(
                'UPDATE trusted_devices SET last_used_at = NOW(),
                    expires_at = DATE_ADD(NOW(), INTERVAL 180 DAY), ip_address = ? WHERE id = ?',
                [$ip, $deviceId]
            );
        } catch (Throwable $e) {
        }
    }

    public static function listDevices(int $userId): array
    {
        try {
            return Database::all(
                'SELECT id, device_name, user_agent, ip_address, created_at, last_used_at, expires_at,
                        mfa_exempt_until,
                        (revoked_at IS NOT NULL) AS revoked,
                        (expires_at <= NOW()) AS expired,
                        (mfa_exempt_until IS NOT NULL AND mfa_exempt_until > NOW()
                         AND revoked_at IS NULL AND expires_at > NOW()) AS mfa_exempt
                   FROM trusted_devices WHERE user_id = ? ORDER BY last_used_at DESC, id DESC LIMIT 20',
                [$userId]
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function revokeDevice(int $userId, int $deviceId): bool
    {
        try {
            $row = Database::one(
                'SELECT id, token_hash FROM trusted_devices WHERE id = ? AND user_id = ? AND revoked_at IS NULL LIMIT 1',
                [$deviceId, $userId]
            );
            if ($row === null) {
                return false;
            }
            Database::run('UPDATE trusted_devices SET revoked_at = NOW() WHERE id = ?', [$deviceId]);
            // If the revoked device is THIS browser, drop its cookie.
            $cookie = (string) ($_COOKIE[self::deviceCookieName()] ?? '');
            if ($cookie !== '' && hash_equals(self::tokenHash($cookie), (string) $row['token_hash'])) {
                setcookie(self::deviceCookieName(), '', self::deviceCookieParams(time() - 3600));
                unset($_COOKIE[self::deviceCookieName()]);
            }
            self::event($userId, 'device.revoked', ['device_id' => $deviceId]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Human-friendly device label from a User-Agent string (server-side). */
    public static function deviceName(string $ua): string
    {
        $ua = (string) $ua;
        $browser = 'Unknown browser';
        foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari', 'MSIE' => 'IE', 'Trident' => 'IE'] as $needle => $name) {
            if (stripos($ua, $needle) !== false) {
                $browser = $name;
                break;
            }
        }
        $os = 'Unknown OS';
        foreach (['Windows' => 'Windows', 'Android' => 'Android', 'iPhone' => 'iOS', 'iPad' => 'iOS', 'Macintosh' => 'macOS', 'Linux' => 'Linux'] as $needle => $name) {
            if (stripos($ua, $needle) !== false) {
                $os = $name;
                break;
            }
        }
        return $browser . ' on ' . $os;
    }

    /* =====================================================================
     *  Pending logins (password-OK, awaiting device and/or MFA gates)
     * ===================================================================== */

    /**
     * Create a pending login. $emailToken is the PLAIN single-use device
     * token (only its hash is stored). Returns the pending row id.
     */
    public static function createPending(
        int $userId,
        string $stage,
        string $ip,
        string $ua,
        string $deviceName,
        bool $trustDevice = true,
        ?string $emailToken = null
    ): int {
        self::cleanupExpired();
        try {
            return Database::insert('pending_logins', [
                'user_id' => $userId,
                'stage' => in_array($stage, ['device', 'mfa', 'setup'], true) ? $stage : 'mfa',
                'token_hash' => $emailToken !== null ? self::tokenHash($emailToken) : null,
                'ip_address' => $ip,
                'user_agent' => mb_substr($ua, 0, 255),
                'device_name' => mb_substr($deviceName, 0, 191),
                'trust_device' => $trustDevice ? 1 : 0,
                'expires_at' => date('Y-m-d H:i:s', time() + PENDING_LOGIN_TTL),
            ]);
        } catch (Throwable $e) {
            storage_log('pending create failed: ' . $e->getMessage());
            return 0;
        }
    }

    public static function getPending(int $id): ?array
    {
        try {
            $row = Database::one(
                'SELECT * FROM pending_logins WHERE id = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1',
                [$id]
            );
        } catch (Throwable $e) {
            return null;
        }
        return $row;
    }

    public static function findPendingByDeviceToken(string $plainToken): ?array
    {
        $t = trim($plainToken);
        if ($t === '' || !preg_match('/^[a-f0-9]{64}$/', $t)) {
            return null;
        }
        try {
            $row = Database::one(
                "SELECT * FROM pending_logins
                  WHERE token_hash = ? AND stage = 'device' AND used_at IS NULL AND expires_at > NOW() LIMIT 1",
                [self::tokenHash($t)]
            );
        } catch (Throwable $e) {
            return null;
        }
        return $row;
    }

    public static function advancePending(int $id, string $stage, ?string $newEmailToken = null): void
    {
        try {
            Database::run(
                'UPDATE pending_logins SET stage = ?, token_hash = ? WHERE id = ?',
                [$stage, $newEmailToken !== null ? self::tokenHash($newEmailToken) : null, $id]
            );
        } catch (Throwable $e) {
        }
    }

    public static function bumpPendingAttempts(int $id): int
    {
        try {
            Database::run('UPDATE pending_logins SET attempts = attempts + 1 WHERE id = ?', [$id]);
            return (int) Database::value('SELECT attempts FROM pending_logins WHERE id = ?', [$id]);
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function consumePending(int $id): void
    {
        try {
            Database::run('UPDATE pending_logins SET used_at = NOW(), token_hash = NULL WHERE id = ?', [$id]);
        } catch (Throwable $e) {
        }
    }

    public static function invalidateUserPendings(int $userId, ?int $exceptId = null): void
    {
        try {
            if ($exceptId !== null) {
                Database::run(
                    'UPDATE pending_logins SET used_at = NOW(), token_hash = NULL WHERE user_id = ? AND id <> ? AND used_at IS NULL',
                    [$userId, $exceptId]
                );
            } else {
                Database::run(
                    'UPDATE pending_logins SET used_at = NOW(), token_hash = NULL WHERE user_id = ? AND used_at IS NULL',
                    [$userId]
                );
            }
        } catch (Throwable $e) {
        }
    }

    public static function cleanupExpired(): void
    {
        try {
            Database::run('DELETE FROM pending_logins WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        } catch (Throwable $e) {
        }
    }

    /* =====================================================================
     *  Active session registry
     * ===================================================================== */

    public static function registerSession(int $userId, string $sessionId, string $ip, string $ua): void
    {
        try {
            Database::run(
                'INSERT INTO user_sessions (user_id, session_hash, ip_address, user_agent, last_activity)
                 VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), last_activity = NOW(), revoked_at = NULL',
                [$userId, self::tokenHash($sessionId), $ip, mb_substr($ua, 0, 255)]
            );
        } catch (Throwable $e) {
        }
    }

    public static function touchSession(string $sessionId): void
    {
        try {
            Database::run(
                'UPDATE user_sessions SET last_activity = NOW() WHERE session_hash = ? AND revoked_at IS NULL',
                [self::tokenHash($sessionId)]
            );
        } catch (Throwable $e) {
        }
    }

    public static function sessionRevoked(string $sessionId): bool
    {
        try {
            $row = Database::one(
                'SELECT revoked_at FROM user_sessions WHERE session_hash = ? LIMIT 1',
                [self::tokenHash($sessionId)]
            );
        } catch (Throwable $e) {
            return false; // fail-open when table unavailable
        }
        return $row !== null && $row['revoked_at'] !== null;
    }

    public static function listSessions(int $userId): array
    {
        try {
            return Database::all(
                'SELECT id, ip_address, user_agent, created_at, last_activity,
                        (session_hash = ?) AS is_current
                   FROM user_sessions
                  WHERE user_id = ? AND revoked_at IS NULL
                  ORDER BY last_activity DESC LIMIT 20',
                [self::tokenHash(session_id()), $userId]
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function revokeSession(int $userId, int $sessionDbId): bool
    {
        try {
            $cur = self::tokenHash(session_id());
            $row = Database::one(
                'SELECT id, session_hash FROM user_sessions WHERE id = ? AND user_id = ? AND revoked_at IS NULL LIMIT 1',
                [$sessionDbId, $userId]
            );
            if ($row === null) {
                return false;
            }
            if (hash_equals((string) $row['session_hash'], $cur)) {
                return false; // current session must use logout, not revoke
            }
            Database::run('UPDATE user_sessions SET revoked_at = NOW() WHERE id = ?', [$sessionDbId]);
            self::event($userId, 'session.revoked', ['session_id' => $sessionDbId]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Revoke every session except the current one. Returns revoked count. */
    public static function revokeOtherSessions(int $userId): int
    {
        try {
            $stmt = Database::run(
                'UPDATE user_sessions SET revoked_at = NOW()
                  WHERE user_id = ? AND session_hash <> ? AND revoked_at IS NULL',
                [$userId, self::tokenHash(session_id())]
            );
            $n = $stmt->rowCount();
            if ($n > 0) {
                self::event($userId, 'session.revoked_others', ['count' => $n]);
            }
            return $n;
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function revokeAllSessions(int $userId): void
    {
        try {
            Database::run('UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$userId]);
        } catch (Throwable $e) {
        }
    }

    /* =====================================================================
     *  Audit helper (reuses audit_logs; NEVER passes secrets/tokens/codes)
     * ===================================================================== */

    public static function event(int $userId, string $action, array $meta = []): void
    {
        try {
            Database::insert('audit_logs', [
                'actor_user_id' => $userId,
                'action' => mb_substr($action, 0, 60),
                'entity_type' => 'users',
                'entity_id' => $userId,
                'new_values_json' => $meta === [] ? null : mb_substr((string) json_encode($meta), 0, 2000),
                'ip_address' => client_ip(),
                'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (Throwable $e) {
        }
    }
}
