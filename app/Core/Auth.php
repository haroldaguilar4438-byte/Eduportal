<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Authentication, RBAC and account-lifecycle workflows.
 * Datamex College of Saint Adeline - EduPortal
 *
 * Covers Module 1 of the spec:
 *   - login with brute-force throttling + temporary lockout
 *   - email verification (link token and 6-digit OTP)
 *   - password reset via emailed token
 *   - role-based access control (admin, teacher, student, parent)
 *   - idle session auto-timeout (SESSION_IDLE_TIMEOUT)
 *
 * All database access goes through App\Core\Database (PDO prepared statements).
 */
final class Auth
{
    private static ?array $cachedUser = null;

    /* =====================================================================
     *  Session boot & idle timeout
     * ===================================================================== */

    /**
     * Hash a long random token for storage (SHA-256 hex). Cookie format is
     * unchanged (64-hex), so validation regexes keep working; only the DB
     * representation changes (a DB read can no longer replay the cookie).
     */
    private static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function rememberCookieParams(int $expiry): array
    {
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        return [
            'expires' => $expiry, 'path' => '/', 'domain' => '',
            'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax',
        ];
    }

    /** Call once per request after bootstrap (see index.php). */
    public static function boot(): void
    {
        // A pending step-up challenge must NEVER look authenticated, even if
        // a stale auth_user_id lingers in the session (multi-tab safety).
        if (!empty($_SESSION['pending_login_id'])) {
            unset($_SESSION['auth_user_id'], $_SESSION['auth_completed']);
        }

        // Remember Me auto-restore (token stored hashed; rotate on each use).
        // Step-up: MFA accounts and untrusted devices get a PENDING challenge,
        // never a full session — EXCEPT a trusted device with a live 30-day
        // "remember this device" exemption, which restores directly.
        if (!isset($_SESSION['auth_user_id']) && isset($_COOKIE['remember_token'])) {
            $token = (string)$_COOKIE['remember_token'];
            if ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token)) {
                $u = Database::one("SELECT id FROM users WHERE remember_token = ? AND is_active=1 AND status='active' AND is_email_verified=1 LIMIT 1", [self::tokenHash($token)]);
                if ($u) {
                    $uid = (int)$u['id'];
                    self::$cachedUser = null;
                    $trustedRemember = Mfa::trustedDeviceForRequest($uid);
                    if ($trustedRemember !== null) {
                        Mfa::touchTrustedDevice((int) $trustedRemember['id'], client_ip());
                    }
                    $needsStepUp = ($trustedRemember === null)
                        || (Mfa::isEnabled($uid) && !Mfa::isMfaExempt($trustedRemember));
                    if ($needsStepUp) {
                        session_regenerate_id(true);
                        $_SESSION['pending_remember_uid'] = $uid;
                        $_SESSION['last_activity'] = time();
                        self::setRememberToken($uid);
                    } else {
                        session_regenerate_id(true);
                        $_SESSION['auth_user_id'] = $uid;
                        $_SESSION['auth_completed'] = true;
                        $_SESSION['last_activity'] = time();
                        self::$cachedUser = null;
                        self::setRememberToken($uid);
                        Mfa::registerSession($uid, session_id(), client_ip(), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
                        if (Mfa::isEnabled($uid)) {
                            Mfa::event($uid, 'login.mfa_skipped');
                        }
                    }
                } else {
                    // invalid token, clear cookie
                    setcookie('remember_token', '', self::rememberCookieParams(time() - 3600));
                }
            }
        }

        if (!isset($_SESSION['auth_user_id'])) {
            return;
        }
        // Belt-and-braces: full sessions require the completion flag.
        if (empty($_SESSION['auth_completed'])) {
            self::logout();
            return;
        }
        // Revoked sessions (user clicked "revoke" elsewhere) die immediately.
        if (Mfa::sessionRevoked(session_id())) {
            self::logout();
            set_flash('warning', 'That session was revoked. Please sign in again.');
            return;
        }
        $idleAt = (int) ($_SESSION['last_activity'] ?? 0);
        if ($idleAt > 0 && (time() - $idleAt) > SESSION_IDLE_TIMEOUT) {
            self::logout();
            set_flash('warning', 'You were signed out automatically due to inactivity.');
            return;
        }
        $_SESSION['last_activity'] = time();
        Mfa::touchSession(session_id());
    }

    public static function setRememberToken(int $userId): void
    {
        $token = bin2hex(random_bytes(32));
        Database::run("UPDATE users SET remember_token=? WHERE id=?", [self::tokenHash($token), $userId]);
        setcookie('remember_token', $token, self::rememberCookieParams(time()+30*24*3600));
        $_COOKIE['remember_token']=$token;
    }

    public static function clearRememberToken(?int $userId=null): void
    {
        if($userId) try{ Database::run("UPDATE users SET remember_token=NULL WHERE id=?",[$userId]); } catch(Throwable $e){}
        setcookie('remember_token','', self::rememberCookieParams(time()-3600));
        unset($_COOKIE['remember_token']);
    }

    /* =====================================================================
     *  Login / logout
     * ===================================================================== */

    /**
     * Attempt a login. Returns ['ok' => bool, 'message' => string, ...].
     * Messages are intentionally generic to prevent user enumeration.
     */
    public static function attempt(string $email, string $password, string $ip): array
    {
        $email = mb_strtolower(trim($email));

        // ---- throttle: failures by IP or email within LOGIN_WINDOW --------
        $cutoff = date('Y-m-d H:i:s', time() - LOGIN_WINDOW);
        $failures = (int) Database::value(
            'SELECT COUNT(*) FROM login_attempts
             WHERE success = 0 AND attempted_at > ? AND (ip_address = ? OR email_attempted = ?)',
            [$cutoff, $ip, $email]
        );
        if ($failures >= LOGIN_MAX_ATTEMPTS) {
            return [
                'ok'      => false,
                'message' => 'Too many failed attempts. Please try again in '
                    . (int) ceil(LOGIN_LOCKOUT / 60) . ' minutes.',
            ];
        }

        // ---- fetch user + role --------------------------------------------
        $user = Database::one(
            'SELECT u.*, r.name AS role_name
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.email = ? LIMIT 1',
            [$email]
        );

        $invalidMsg = 'Invalid email or password.';
        if ($user === null) {
            self::logAttempt($email, $ip, false);
            return ['ok' => false, 'message' => $invalidMsg];
        }

        if ($user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time()) {
            $mins = (int) ceil((strtotime((string) $user['locked_until']) - time()) / 60);
            return ['ok' => false, 'message' => "Account temporarily locked. Try again in {$mins} minute(s)."];
        }

        if (!(int) $user['is_active'] || in_array($user['status'], ['suspended','archived','banned','locked','on_hold'], true)) {
            $msg = match($user['status']){'banned'=>'This account has been banned. Contact the admin.','locked'=>'This account is locked (banned). Contact the admin.','on_hold'=>'This account is on hold by admin. Please contact the registrar.','suspended'=>'This account is suspended (on hold). Please contact the registrar.','archived'=>'This account has been removed/archived.', default=>'This account is disabled. Please contact the registrar.'};
            return ['ok' => false, 'message' => $msg];
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            self::registerFailedPassword((int) $user['id'], (int) $user['failed_login_attempts']);
            self::logAttempt($email, $ip, false);
            return ['ok' => false, 'message' => $invalidMsg];
        }

        if (!(int) $user['is_email_verified'] || $user['status'] === 'pending_verification') {
            return [
                'ok'                 => false,
                'needs_verification' => true,
                'user_id'            => (int) $user['id'],
                'message'            => 'Please confirm your email address first. '
                    . 'Check your inbox for the verification link or OTP.',
            ];
        }

        // ---- success housekeeping ------------------------------------------
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            Database::run(
                'UPDATE users SET password_hash = ? WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]
            );
        }
        Database::run(
            'UPDATE users SET failed_login_attempts = 0, locked_until = NULL,
                    last_login_at = NOW(), last_login_ip = ?
              WHERE id = ?',
            [$ip, (int) $user['id']]
        );
        self::logAttempt($email, $ip, true);

        // ---- step-up: password alone NEVER creates a full session -----------
        // Invalidate any older unconsumed challenges (multi-tab safety), then
        // route NEW devices through email verification and MFA-enabled
        // accounts through a TOTP challenge. Accounts without MFA yet are
        // forced into setup before any dashboard access.
        $uid = (int) $user['id'];
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        Mfa::invalidateUserPendings($uid);
        $trusted = Mfa::trustedDeviceForRequest($uid);
        if ($trusted !== null) {
            Mfa::touchTrustedDevice((int) $trusted['id'], $ip);
        }
        $mfaOn = Mfa::isEnabled($uid);

        session_regenerate_id(true);                 // anti session-fixation
        unset($_SESSION['auth_user_id'], $_SESSION['auth_completed']);
        $_SESSION['last_activity'] = time();
        self::$cachedUser = null;

        if ($trusted === null) {
            $plain = bin2hex(random_bytes(32));
            $pid = Mfa::createPending(
                $uid, 'device', $ip, $ua, Mfa::deviceName($ua), true, $plain
            );
            if ($pid <= 0) {
                return ['ok' => false, 'message' => 'Could not start verification. Please try again.'];
            }
            $_SESSION['pending_login_id'] = $pid;
            Mfa::event($uid, 'login.device_challenge');
            self::audit($uid, 'login_password_ok', 'users', $uid);
            return [
                'ok' => false, 'pending' => 'device', 'pending_id' => $pid,
                'device_token' => $plain,
                'message' => 'Password correct. Verify this new device to continue.',
            ];
        }

        if ($mfaOn) {
            // "Remember this device": trusted + live 30-day exemption skips
            // the code challenge (password was still required this login).
            if ($trusted !== null && Mfa::isMfaExempt($trusted)) {
                Mfa::touchTrustedDevice((int) $trusted['id'], $ip);
                session_regenerate_id(true);
                unset($_SESSION['auth_user_id'], $_SESSION['auth_completed'], $_SESSION['pending_login_id']);
                $_SESSION['last_activity'] = time();
                self::$cachedUser = null;
                self::logAttempt($email, $ip, true);
                unset($user['password_hash'], $user['otp_code_hash'], $user['remember_token']);
                return ['ok' => true, 'user' => $user, 'via_exemption' => true];
            }
            $pid = Mfa::createPending($uid, 'mfa', $ip, $ua, Mfa::deviceName($ua));
            if ($pid <= 0) {
                return ['ok' => false, 'message' => 'Could not start verification. Please try again.'];
            }
            $_SESSION['pending_login_id'] = $pid;
            Mfa::event($uid, 'login.mfa_challenge');
            self::audit($uid, 'login_password_ok', 'users', $uid);
            return [
                'ok' => false, 'pending' => 'mfa', 'pending_id' => $pid,
                'message' => 'Enter the code from your authenticator app.',
            ];
        }

        // MFA mandatory: no MFA yet -> forced setup before full access.
        $pid = Mfa::createPending($uid, 'setup', $ip, $ua, Mfa::deviceName($ua));
        if ($pid <= 0) {
            return ['ok' => false, 'message' => 'Could not start verification. Please try again.'];
        }
        $_SESSION['pending_login_id'] = $pid;
        Mfa::event($uid, 'login.mfa_setup_required');
        self::audit($uid, 'login_password_ok', 'users', $uid);
        return [
            'ok' => false, 'pending' => 'setup', 'pending_id' => $pid,
            'message' => 'Protect your account: set up MFA to continue.',
        ];
    }

    /**
     * The pending step-up challenge for this session (or null).
     * Returns the pending_logins row with the owning user's email.
     */
    public static function pendingLogin(): ?array
    {
        $pid = (int) ($_SESSION['pending_login_id'] ?? 0);
        if ($pid <= 0) {
            return null;
        }
        $row = Mfa::getPending($pid);
        if ($row === null) {
            unset($_SESSION['pending_login_id']);
            return null;
        }
        try {
            $u = Database::one('SELECT email, first_name, last_name, role_id FROM users WHERE id = ? LIMIT 1', [(int) $row['user_id']]);
            if ($u !== null) {
                $row = $row + ['email' => $u['email'], 'first_name' => $u['first_name'], 'last_name' => $u['last_name'], 'role_id' => $u['role_id']];
            }
        } catch (Throwable $e) {
        }
        return $row;
    }

    /**
     * Create the REAL authenticated session after ALL gates passed.
     * $remember: honor the "keep me signed in" checkbox only here.
     */
    public static function finalizeLogin(int $userId, int $pendingId, bool $remember = false): void
    {
        Mfa::consumePending($pendingId);
        Mfa::invalidateUserPendings($userId, $pendingId);
        session_regenerate_id(true);
        $_SESSION['auth_user_id'] = $userId;
        $_SESSION['auth_completed'] = true;
        $_SESSION['last_activity'] = time();
        unset($_SESSION['pending_login_id'], $_SESSION['pending_remember_uid'], $_SESSION['mfa_setup']);
        self::$cachedUser = null;
        Mfa::registerSession($userId, session_id(), client_ip(), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($remember) {
            self::setRememberToken($userId);
        } else {
            self::clearRememberToken($userId);
        }
        self::audit($userId, 'login', 'users', $userId);
        Mfa::event($userId, 'login.success');
    }

    /**
     * Direct full login for the ONE exempt path: password verified on a
     * trusted device with a live 30-day "remember this device" exemption.
     * Never call this for any other outcome of attempt().
     */
    public static function directLogin(int $userId, bool $remember = false): void
    {
        session_regenerate_id(true);
        $_SESSION['auth_user_id'] = $userId;
        $_SESSION['auth_completed'] = true;
        $_SESSION['last_activity'] = time();
        unset($_SESSION['pending_login_id'], $_SESSION['pending_remember_uid'], $_SESSION['mfa_remember']);
        self::$cachedUser = null;
        Mfa::registerSession($userId, session_id(), client_ip(), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($remember) {
            self::setRememberToken($userId);
        } else {
            self::clearRememberToken($userId);
        }
        self::audit($userId, 'login', 'users', $userId);
        Mfa::event($userId, 'login.success');
        Mfa::event($userId, 'login.mfa_skipped');
    }

    /** Tear down the session completely and start a clean one (for flashes). */
    public static function logout(): void
    {
        $uid = $_SESSION['auth_user_id'] ?? null;
        if ($uid) {
            try {
                Mfa::event((int) $uid, 'logout');
            } catch (Throwable $e) {
            }
            self::clearRememberToken((int)$uid);
        } else {
            self::clearRememberToken(null);
        }
        // Mark this session revoked in the registry (best effort).
        try {
            if (session_status() === PHP_SESSION_ACTIVE && session_id() !== '') {
                Database::run(
                    'UPDATE user_sessions SET revoked_at = NOW() WHERE session_hash = ?',
                    [Mfa::tokenHash(session_id())]
                );
            }
        } catch (Throwable $e) {
        }
        // A pending challenge dies with the session: invalidate server-side.
        try {
            $pid = (int) ($_SESSION['pending_login_id'] ?? 0);
            if ($pid > 0) {
                Mfa::consumePending($pid);
            }
        } catch (Throwable $e) {
        }
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        session_start();
        session_regenerate_id(true);
        self::$cachedUser = null;
    }

    /* =====================================================================
     *  Current-user accessors & RBAC
     * ===================================================================== */

    public static function check(): bool
    {
        if (!empty($_SESSION['pending_login_id'])) {
            return false;
        }
        return self::user() !== null;
    }

    /** Fresh DB read per request (role/status changes apply immediately). */
    public static function user(): ?array
    {
        if (self::$cachedUser !== null) {
            return self::$cachedUser;
        }
        $uid = $_SESSION['auth_user_id'] ?? null;
        if (!$uid) {
            return null;
        }
        $u = Database::one(
            'SELECT u.*, r.name AS role_name
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.id = ? LIMIT 1',
            [(int) $uid]
        );
        if (
            $u === null
            || !(int) $u['is_active']
            || in_array($u['status'], ['suspended','archived','banned','locked','on_hold','pending_verification'], true)
        ) {
            if ($u !== null && in_array($u['status'], ['banned','locked','on_hold','suspended','archived'], true)) {
                // keep session but force logout with flash on next request via check()
            }
            self::logout();
            return null;
        }
        unset($u['password_hash'], $u['otp_code_hash']);
        self::$cachedUser = $u;
        return $u;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u === null ? null : (int) $u['id'];
    }

    /** Role name: admin | teacher | student | parent | registrar | accounting */
    public static function role(): ?string
    {
        $u = self::user();
        return $u === null ? null : (string) $u['role_name'];
    }

    /** True when the current user has ANY of the given roles. */
    public static function is(string ...$roles): bool
    {
        $r = self::role();
        return $r !== null && in_array($r, $roles, true);
    }

    /** Guard: must be logged in AND hold one of the given roles. */
    public static function requireRole(string ...$roles): void
    {
        // Step-up challenges never grant access to protected pages: send the
        // user back to the correct gate instead of leaking any content.
        if (!empty($_SESSION['pending_login_id']) || !empty($_SESSION['pending_remember_uid'])) {
            $pending = self::pendingLogin();
            if ($pending !== null) {
                $to = match ((string) ($pending['stage'] ?? 'mfa')) {
                    'device' => '/login/device',
                    'setup' => '/mfa/setup',
                    default => '/mfa/verify',
                };
                redirect($to);
            }
            if (!empty($_SESSION['pending_remember_uid'])) {
                redirect('/mfa/verify');
            }
            redirect('/login');
        }
        if (!self::check()) {
            redirect('/login');
        }
        if (!self::is(...$roles)) {
            http_response_code(403);
            if (
                ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
                || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            ) {
                json_response(['error' => 'Forbidden: insufficient role.'], 403);
            }
            exit('<!doctype html><html lang="en"><meta charset="utf-8"><title>403 Forbidden</title>'
                . '<body style="font-family:sans-serif;text-align:center;padding-top:12vh">'
                . '<h1>403 - Forbidden</h1><p>Your account role cannot access this area.</p>'
                . '<p><a href="/">Back to portal</a></p></body></html>');
        }
    }

    /* =====================================================================
     *  Email verification (link token + OTP)
     * ===================================================================== */

    /** Generate + store a verification token hash; returns the plain token to email. */
    public static function issueVerification(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        Database::run(
            'UPDATE users SET verification_token = ?, token_expiry = ?, otp_code_hash = NULL, otp_expiry = NULL
             WHERE id = ?',
            [self::tokenHash($token), date('Y-m-d H:i:s', time() + VERIFY_TOKEN_TTL), $userId]
        );
        return $token;
    }

    /** Consume a verification link token; flips is_email_verified on success. */
    public static function consumeVerification(string $token): array
    {
        $token = trim($token);
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return ['ok' => false, 'message' => 'Invalid verification link.'];
        }
        $user = Database::one(
            'SELECT id, email, status FROM users
              WHERE verification_token = ? AND token_expiry > NOW() LIMIT 1',
            [self::tokenHash($token)]
        );
        if ($user === null) {
            return ['ok' => false, 'message' => 'This verification link is invalid or has expired. Request a new one.'];
        }
        Database::run(
            "UPDATE users
                SET is_email_verified = 1, verified_at = NOW(),
                    verification_token = NULL, token_expiry = NULL,
                    status = IF(status = 'pending_verification', 'active', status)
              WHERE id = ?",
            [(int) $user['id']]
        );
        self::audit((int) $user['id'], 'email_verified', 'users', (int) $user['id']);
        return ['ok' => true, 'message' => 'Email verified successfully. You can now sign in.', 'email' => $user['email']];
    }

    /** Generate a 6-digit OTP; stores only its hash. Returns the plain code. */
    public static function issueOtp(int $userId): string
    {
        $code = (string) random_int(100000, 999999);
        Database::run(
            'UPDATE users SET otp_code_hash = ?, otp_expiry = ?, otp_attempts = 0 WHERE id = ?',
            [password_hash($code, PASSWORD_DEFAULT), date('Y-m-d H:i:s', time() + OTP_TTL), $userId]
        );
        return $code;
    }

    /** Verify a submitted OTP (max 5 tries per issued code). */
    public static function verifyOtp(int $userId, string $code): array
    {
        $user = Database::one(
            'SELECT otp_code_hash, otp_expiry, otp_attempts FROM users WHERE id = ?',
            [$userId]
        );
        if ($user === null || $user['otp_code_hash'] === null) {
            return ['ok' => false, 'message' => 'No active code found. Please request a new OTP.'];
        }
        if (strtotime((string) $user['otp_expiry']) < time()) {
            return ['ok' => false, 'expired' => true, 'message' => 'That code expired. Please request a new one.'];
        }
        if ((int) $user['otp_attempts'] >= 5) {
            return ['ok' => false, 'message' => 'Too many incorrect attempts. Request a new code.'];
        }
        if (!password_verify(trim($code), (string) $user['otp_code_hash'])) {
            Database::run('UPDATE users SET otp_attempts = otp_attempts + 1 WHERE id = ?', [$userId]);
            return ['ok' => false, 'message' => 'Incorrect code. Please try again.'];
        }
        Database::run(
            "UPDATE users
                SET otp_code_hash = NULL, otp_expiry = NULL, otp_attempts = 0,
                    is_email_verified = 1, verified_at = NOW(),
                    status = IF(status = 'pending_verification', 'active', status)
              WHERE id = ?",
            [$userId]
        );
        self::audit($userId, 'email_verified_otp', 'users', $userId);
        return ['ok' => true, 'message' => 'Email verified successfully. You can now sign in.'];
    }

    /* =====================================================================
     *  Password reset
     * ===================================================================== */

    /** Create reset token for the account (null when email unknown - caller still shows generic OK). */
    public static function issuePasswordReset(string $email): ?string
    {
        $user = Database::one('SELECT id FROM users WHERE email = ?', [mb_strtolower(trim($email))]);
        if ($user === null) {
            return null;
        }
        $token = bin2hex(random_bytes(32));
        Database::run(
            'UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE id = ?',
            [self::tokenHash($token), date('Y-m-d H:i:s', time() + RESET_TOKEN_TTL), (int) $user['id']]
        );
        self::audit((int) $user['id'], 'password_reset_requested', 'users', (int) $user['id']);
        return $token;
    }

    /**
     * DB-backed send throttle for OTP / verification-link / reset emails.
     * Session cooldowns stop casual double-clicks; this stops session/IP
     * rotation abuse (mail cost + enumeration oracle). Reuses login_attempts
     * (no new table). Returns a user-facing block message or null.
     */
    public static function checkSendThrottle(string $scope, string $email): ?string
    {
        try {
            $key = $scope . ':' . mb_strtolower(trim($email));
            $ip = client_ip();
            $emailHits = (int) Database::value(
                "SELECT COUNT(*) FROM login_attempts WHERE email_attempted = ? AND attempted_at > ?",
                [$key, date('Y-m-d H:i:s', time() - 3600)]
            );
            if ($emailHits >= 8) {
                return 'Too many requests for this email. Please wait a while and try again.';
            }
            $ipHits = (int) Database::value(
                "SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at > ?",
                [$ip, date('Y-m-d H:i:s', time() - 3600)]
            );
            if ($ipHits >= 30) {
                return 'Too many requests from your network. Please wait a while and try again.';
            }
        } catch (Throwable $e) {
            return null; // fail open: availability over strictness
        }
        return null;
    }

    public static function recordSend(string $scope, string $email): void
    {
        try {
            Database::insert('login_attempts', [
                'email_attempted' => $scope . ':' . mb_strtolower(trim($email)),
                'ip_address' => client_ip(),
                'success' => 1,
                'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (Throwable $e) {
        }
    }

    /** Complete a reset: validate token, enforce strength, update hash. */
    public static function resetPassword(string $token, string $newPassword): array
    {
        $errors = self::assertPasswordStrength($newPassword);
        if ($errors !== []) {
            return [
                'ok'      => false,
                'message' => 'Password needs: ' . implode(', ', $errors) . '.',
                'errors'  => $errors,
            ];
        }
        $token = trim($token);
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return ['ok' => false, 'message' => 'This reset link is invalid or has expired.'];
        }
        $user = Database::one(
            'SELECT id FROM users WHERE reset_token = ? AND reset_token_expiry > NOW() LIMIT 1',
            [self::tokenHash($token)]
        );
        if ($user === null) {
            return ['ok' => false, 'message' => 'This reset link is invalid or has expired.'];
        }
        Database::run(
            'UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expiry = NULL,
                    failed_login_attempts = 0, locked_until = NULL
              WHERE id = ?',
            [password_hash($newPassword, PASSWORD_DEFAULT), (int) $user['id']]
        );
        // A password reset kills every other session + device trust + MFA
        // lockout, and wipes pending challenges — the account starts clean.
        Mfa::revokeAllSessions((int) $user['id']);
        Mfa::clearAllMfaExemptions((int) $user['id']);
        Mfa::invalidateUserPendings((int) $user['id']);
        try {
            Database::run('UPDATE user_mfa SET failed_attempts = 0, locked_until = NULL WHERE user_id = ?', [(int) $user['id']]);
        } catch (Throwable $e) {
        }
        self::clearRememberToken((int) $user['id']);
        self::audit((int) $user['id'], 'password_reset_completed', 'users', (int) $user['id']);
        Mfa::event((int) $user['id'], 'password.changed');
        return ['ok' => true, 'message' => 'Password updated. You can now sign in.'];
    }

    /** Returns list of unmet requirements (empty array = strong enough). */
    public static function assertPasswordStrength(string $password): array
    {
        $errors = [];
        if (strlen($password) < 10) {
            $errors[] = 'at least 10 characters';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'an uppercase letter';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'a lowercase letter';
        }
        if (!preg_match('/\d/', $password)) {
            $errors[] = 'a number';
        }
        return $errors;
    }

    /* =====================================================================
     *  Internal helpers
     * ===================================================================== */

    private static function registerFailedPassword(int $userId, int $currentAttempts): void
    {
        $attempts = $currentAttempts + 1;
        if ($attempts >= LOGIN_MAX_ATTEMPTS) {
            Database::run(
                'UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?',
                [$attempts, date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT), $userId]
            );
        } else {
            Database::run('UPDATE users SET failed_login_attempts = ? WHERE id = ?', [$attempts, $userId]);
        }
    }

    private static function logAttempt(string $email, string $ip, bool $success): void
    {
        try {
            Database::insert('login_attempts', [
                'email_attempted' => $email,
                'ip_address'      => $ip,
                'success'         => $success ? 1 : 0,
                'user_agent'      => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (Throwable $e) {
            storage_log('login_attempts insert failed: ' . $e->getMessage());
        }
    }

    private static function audit(int $actorId, string $action, string $entityType, int $entityId): void
    {
        try {
            Database::insert('audit_logs', [
                'actor_user_id' => $actorId,
                'action'        => $action,
                'entity_type'   => $entityType,
                'entity_id'     => $entityId,
                'ip_address'    => client_ip(),
                'user_agent'    => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (Throwable $e) {
            storage_log('audit_logs insert failed: ' . $e->getMessage());
        }
    }
}
