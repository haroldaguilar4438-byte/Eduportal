<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Mfa;
use Throwable;

/**
 * MFA + new-device verification + security settings.
 * Datamex College of Saint Adeline - EduPortal
 *
 * Every decision here is enforced server-side against the pending_logins /
 * user_mfa / trusted_devices tables. The browser is never trusted.
 *
 *   GET  /mfa/verify              MFA challenge (TOTP or recovery code)
 *   POST /mfa/verify
 *   GET  /mfa/setup               TOTP setup (QR + manual key)
 *   POST /mfa/setup               confirm setup with a valid code
 *   GET  /mfa/codes               show-once recovery codes
 *   GET  /login/device            "check your email" pending screen
 *   POST /login/device/resend     resend approval email (throttled)
 *   GET  /verify-device?token=..  single-use approval link
 *   POST /login/cancel            abandon a pending login
 *   GET  /security                Security settings (full auth only)
 *   POST /security/mfa/disable    disable MFA (password-confirmed)
 *   POST /security/mfa/codes      regenerate recovery codes
 *   POST /security/devices/{id}/revoke
 *   POST /security/sessions/{id}/revoke
 *   POST /security/sessions/revoke-others
 */
final class SecurityController extends Controller
{
    /* ============================ MFA challenge ============================ */

    public function showMfaVerify(array $params = []): void
    {
        if (Auth::check()) {
            redirect('/dashboard');
        }
        $pending = Auth::pendingLogin();
        $rememberUid = (int) ($_SESSION['pending_remember_uid'] ?? 0);
        if ($pending === null && $rememberUid <= 0) {
            redirect('/login');
        }
        if ($pending !== null && $pending['stage'] === 'device') {
            redirect('/login/device');
        }
        if ($pending !== null && $pending['stage'] === 'setup') {
            redirect('/mfa/setup');
        }
        $uid = $pending !== null ? (int) $pending['user_id'] : $rememberUid;
        if (!Mfa::isEnabled($uid)) {
            redirect('/mfa/setup');
        }
        $this->view('auth/mfa_verify', [
            'title' => 'Two-factor verification',
            'email' => $pending['email'] ?? '',
        ], 'shared/layout_auth');
    }

    public function verifyMfa(array $params = []): void
    {
        Csrf::guard();
        if (Auth::check()) {
            redirect('/dashboard');
        }
        $code = trim((string) ($_POST['code'] ?? ''));
        if ($code === '') {
            set_flash('error', 'Enter the 6-digit code from your authenticator app.');
            redirect('/mfa/verify');
        }

        // Remember-me step-up path (no pending row yet).
        $rememberUid = (int) ($_SESSION['pending_remember_uid'] ?? 0);
        if ($rememberUid > 0 && Auth::pendingLogin() === null) {
            $this->verifyRememberMfa($rememberUid, $code);
            return;
        }

        $pending = Auth::pendingLogin();
        if ($pending === null) {
            redirect('/login');
        }
        if ($pending['stage'] === 'device') {
            redirect('/login/device');
        }
        if ($pending['stage'] === 'setup') {
            redirect('/mfa/setup');
        }
        $pid = (int) $pending['id'];
        $uid = (int) $pending['user_id'];

        $attempts = Mfa::bumpPendingAttempts($pid);
        if ($attempts > MFA_PENDING_MAX) {
            Mfa::consumePending($pid);
            unset($_SESSION['pending_login_id']);
            Mfa::event($uid, 'mfa.challenge_expired');
            set_flash('error', 'Too many incorrect attempts. Please sign in again.');
            redirect('/login');
        }

        $result = Mfa::verifyChallenge($uid, $code);
        if (empty($result['ok'])) {
            if (!empty($result['locked'])) {
                try {
                    $u = Database::one('SELECT * FROM users WHERE id = ? LIMIT 1', [$uid]);
                    if ($u !== null && Auth::checkSendThrottle('mfa-lock', (string) $u['email']) === null) {
                        send_lockout_warning_email($u, 'repeated incorrect MFA codes');
                        Auth::recordSend('mfa-lock', (string) $u['email']);
                    }
                } catch (Throwable $e) {
                }
            }
            set_flash('error', $result['message'] ?? 'Incorrect MFA code. Please try again.');
            redirect('/mfa/verify');
        }

        if (!empty($result['recovery'])) {
            try {
                $u = Database::one('SELECT * FROM users WHERE id = ? LIMIT 1', [$uid]);
                if ($u !== null) {
                    send_recovery_used_email($u, Mfa::recoveryRemaining($uid));
                }
            } catch (Throwable $e) {
            }
            set_flash('warning', 'You signed in with a recovery code. Generate a fresh set in Security Settings if you are running low.');
        }

        $this->finishLogin($uid, $pid, $pending, !empty($_POST['remember_device']));
    }

    /** MFA check for the remember-me step-up path. */
    private function verifyRememberMfa(int $uid, string $code): void
    {
        $user = Database::one('SELECT * FROM users WHERE id = ? LIMIT 1', [$uid]);
        if ($user === null || !(int) $user['is_active'] || $user['status'] !== 'active' || !(int) $user['is_email_verified']) {
            unset($_SESSION['pending_remember_uid']);
            set_flash('error', 'Please sign in again.');
            redirect('/login');
        }
        $result = Mfa::verifyChallenge($uid, $code);
        if (empty($result['ok'])) {
            set_flash('error', $result['message'] ?? 'Incorrect MFA code. Please try again.');
            redirect('/mfa/verify');
        }
        $ip = client_ip();
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $trusted = Mfa::trustedDeviceForRequest($uid);
        if ($trusted !== null) {
            Mfa::touchTrustedDevice((int) $trusted['id'], $ip);
            $did = (int) $trusted['id'];
            $wantExempt = !empty($_POST['remember_device']);
            unset($_SESSION['pending_remember_uid']);
            // Direct finalize (no pending row in this path).
            session_regenerate_id(true);
            $_SESSION['auth_user_id'] = $uid;
            $_SESSION['auth_completed'] = true;
            $_SESSION['last_activity'] = time();
            unset($_SESSION['pending_login_id'], $_SESSION['pending_remember_uid'], $_SESSION['mfa_remember'], $_SESSION['mfa_setup']);
            Auth::setRememberToken($uid);
            Mfa::registerSession($uid, session_id(), $ip, $ua);
            if ($wantExempt && $did > 0) {
                Mfa::grantMfaExemption($uid, $did);
            }
            Mfa::event($uid, 'login.success');
            set_flash('success', 'Welcome back!');
            redirect('/dashboard');
        }
        // Untrusted device: fall into the email-approval gate.
        $plain = bin2hex(random_bytes(32));
        $pid = Mfa::createPending($uid, 'device', $ip, $ua, Mfa::deviceName($ua), true, $plain);
        unset($_SESSION['pending_remember_uid']);
        $_SESSION['pending_login_id'] = $pid;
        try {
            if (Auth::checkSendThrottle('device', (string) $user['email']) === null) {
                send_new_device_email($user, $plain, $pid);
                Auth::recordSend('device', (string) $user['email']);
            }
        } catch (Throwable $e) {
        }
        set_flash('info', 'We sent a verification email to your registered email address. This device is not recognized.');
        redirect('/login/device');
    }

    /** Shared finalizer: trust device (post-verification) then full session. */
    private function finishLogin(int $uid, int $pid, array $pending, bool $wantRememberDevice = false): void
    {
        $ip = client_ip();
        $remember = !empty($_SESSION['mfa_remember']);
        unset($_SESSION['mfa_remember']);
        $deviceId = 0;
        if (!empty($pending['trust_device'])) {
            $fresh = Mfa::trustedDeviceForRequest($uid);
            if ($fresh !== null) {
                $deviceId = (int) $fresh['id'];
                Mfa::touchTrustedDevice($deviceId, $ip);
            } else {
                $deviceId = Mfa::trustCurrentDevice($uid, $ip, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), (string) ($pending['device_name'] ?? ''));
                if ($deviceId > 0) {
                    try {
                        $u = Database::one('SELECT * FROM users WHERE id = ? LIMIT 1', [$uid]);
                        if ($u !== null) {
                            send_trusted_device_email($u, (string) ($pending['device_name'] ?? Mfa::deviceName((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))), false);
                        }
                    } catch (Throwable $e) {
                    }
                }
            }
        }
        // Opt-in 30-day MFA skip: only on an already-trusted device, granted
        // AFTER the code was verified this login. Never on a first-seen device.
        $exemptGranted = false;
        if ($wantRememberDevice && $deviceId > 0) {
            Mfa::grantMfaExemption($uid, $deviceId);
            $exemptGranted = true;
            try {
                $u = Database::one('SELECT * FROM users WHERE id = ? LIMIT 1', [$uid]);
                if ($u !== null) {
                    send_mfa_exempt_email($u, true);
                }
            } catch (Throwable $e) {
            }
        }
        Auth::finalizeLogin($uid, $pid, $remember);
        set_flash(
            'success',
            $exemptGranted
                ? 'Welcome back! This device will skip the MFA code for 30 days. You can undo this anytime in Security Settings.'
                : 'Welcome back! Your account is protected with MFA.'
        );
        redirect('/dashboard');
    }

    /* ============================== MFA setup ============================== */

    public function showSetup(array $params = []): void
    {
        $pending = Auth::pendingLogin();
        // Allowed when: forced setup during login, OR fully logged-in user
        // enabling MFA voluntarily. Nothing else may see the secret.
        if ($pending !== null && $pending['stage'] === 'device') {
            redirect('/login/device');
        }
        if ($pending !== null && $pending['stage'] === 'mfa') {
            redirect('/mfa/verify');
        }
        if ($pending === null && !Auth::check()) {
            if (!empty($_SESSION['pending_remember_uid'])) {
                redirect('/mfa/verify');
            }
            redirect('/login');
        }
        $uid = $pending !== null ? (int) $pending['user_id'] : (int) Auth::id();
        if ($uid <= 0) {
            redirect('/login');
        }
        if (Mfa::isEnabled($uid)) {
            set_flash('info', 'MFA is already enabled. Your account is protected with MFA.');
            redirect($pending !== null ? '/mfa/verify' : '/security');
        }
        // Fresh secret per setup view (old disabled secret is replaced).
        $setup = Mfa::beginSetup($uid);
        $layout = $pending !== null ? 'shared/layout_auth' : 'shared/layout';
        $this->view('auth/mfa_setup', [
            'title' => 'Set up MFA',
            'secret' => $setup['secret'],
            'otpauth' => $setup['otpauth'],
            'isLoginFlow' => $pending !== null,
        ], $layout);
    }

    public function confirmSetup(array $params = []): void
    {
        Csrf::guard();
        $pending = Auth::pendingLogin();
        if ($pending === null && !Auth::check()) {
            redirect('/login');
        }
        $uid = $pending !== null ? (int) $pending['user_id'] : (int) Auth::id();
        $code = trim((string) ($_POST['code'] ?? ''));
        $result = Mfa::confirmSetup($uid, $code);
        if (empty($result['ok'])) {
            set_flash('error', $result['message'] ?? 'Incorrect MFA code. Please try again.');
            redirect('/mfa/setup');
        }
        try {
            $u = Database::one('SELECT * FROM users WHERE id = ? LIMIT 1', [$uid]);
            if ($u !== null) {
                send_mfa_status_email($u, true);
            }
        } catch (Throwable $e) {
        }
        // Show-once recovery codes.
        $_SESSION['mfa_codes_once'] = $result['codes'] ?? [];
        if ($pending !== null) {
            // Device gate already passed (or device was already trusted):
            // finish the login immediately — never leave a half session.
            $pid = (int) $pending['id'];
            $ip = client_ip();
            $fresh = Mfa::trustedDeviceForRequest($uid);
            if ($fresh !== null) {
                Mfa::touchTrustedDevice((int) $fresh['id'], $ip);
            } elseif (!empty($pending['trust_device'])) {
                Mfa::trustCurrentDevice($uid, $ip, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), (string) ($pending['device_name'] ?? ''));
            }
            $remember = !empty($_SESSION['mfa_remember']);
            unset($_SESSION['mfa_remember']);
            Auth::finalizeLogin($uid, $pid, $remember);
            set_flash('success', 'MFA enabled. Your account is now protected.');
            redirect('/mfa/codes');
        }
        set_flash('success', 'MFA enabled. Save your recovery codes now — they are shown only once.');
        redirect('/mfa/codes');
    }

    /** Show-once recovery codes (immediately cleared from session). */
    public function showCodes(array $params = []): void
    {
        $codes = $_SESSION['mfa_codes_once'] ?? null;
        unset($_SESSION['mfa_codes_once']);
        if (!is_array($codes) || $codes === []) {
            redirect(Auth::check() ? '/security' : '/login');
        }
        $this->view('auth/recovery_codes', [
            'title' => 'Recovery codes',
            'codes' => array_values($codes),
        ], Auth::check() ? 'shared/layout' : 'shared/layout_auth');
    }

    /* ========================= new-device verification ===================== */

    public function showDevicePending(array $params = []): void
    {
        if (Auth::check()) {
            redirect('/dashboard');
        }
        $pending = Auth::pendingLogin();
        if ($pending === null) {
            redirect('/login');
        }
        if ($pending['stage'] !== 'device') {
            redirect($pending['stage'] === 'setup' ? '/mfa/setup' : '/mfa/verify');
        }
        $masked = $this->maskEmail((string) ($pending['email'] ?? ''));
        $cooldown = max(0, 60 - (time() - (int) ($_SESSION['last_device_resend_at'] ?? 0)));
        $this->view('auth/device_pending', [
            'title' => 'Verify new device',
            'maskedEmail' => $masked,
            'deviceName' => (string) ($pending['device_name'] ?? ''),
            'cooldown' => $cooldown,
        ], 'shared/layout_auth');
    }

    public function resendDevice(array $params = []): void
    {
        Csrf::guard();
        $pending = Auth::pendingLogin();
        if ($pending === null || $pending['stage'] !== 'device') {
            redirect('/login');
        }
        $now = time();
        $last = (int) ($_SESSION['last_device_resend_at'] ?? 0);
        if ($now - $last < 60) {
            set_flash('error', 'Please wait ' . (60 - ($now - $last)) . 's before resending (cooldown).');
            redirect('/login/device');
        }
        $uid = (int) $pending['user_id'];
        $user = Database::one('SELECT * FROM users WHERE id = ? LIMIT 1', [$uid]);
        if ($user === null) {
            redirect('/login');
        }
        if (($block = Auth::checkSendThrottle('device', (string) $user['email'])) !== null) {
            set_flash('error', $block);
            redirect('/login/device');
        }
        $plain = bin2hex(random_bytes(32));
        Mfa::advancePending((int) $pending['id'], 'device', $plain);
        try {
            send_new_device_email($user, $plain, (int) $pending['id']);
        } catch (Throwable $e) {
            storage_log('device resend failed: ' . $e->getMessage());
        }
        Auth::recordSend('device', (string) $user['email']);
        $_SESSION['last_device_resend_at'] = $now;
        Mfa::event($uid, 'login.device_resent');
        set_flash('success', 'A new approval email is on its way. The previous link no longer works.');
        redirect('/login/device');
    }

    /**
     * Lightweight poll endpoint for the "check your email" page (session-bound,
     * read-only). Tells the waiting browser when the attempt was approved on
     * another device so it can advance automatically.
     *   GET /login/device/status -> {ok, stage} | {ok:false, gone:true}
     */
    public function deviceStatus(array $params = []): void
    {
        $pid = (int) ($_SESSION['pending_login_id'] ?? 0);
        if ($pid <= 0) {
            json_response(['ok' => false, 'gone' => true]);
        }
        $row = Mfa::getPending($pid);
        if ($row === null) {
            unset($_SESSION['pending_login_id']);
            json_response(['ok' => false, 'gone' => true]);
        }
        json_response(['ok' => true, 'stage' => (string) $row['stage']]);
    }

    /**
     * Single-use approval link. May be opened in a different browser than
     * the login attempt: the approving session adopts the pending login
     * (email ownership IS the proof). Expired/used links show clear errors.
     */
    public function verifyDevice(array $params = []): void
    {
        $token = trim((string) ($_GET['token'] ?? ''));
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            set_flash('error', 'This verification link is invalid.');
            redirect('/login');
        }
        $row = Mfa::findPendingByDeviceToken($token);
        if ($row === null) {
            // Distinguish used/expired from unknown for a helpful message.
            $known = false;
            try {
                $known = Database::exists(
                    'SELECT 1 FROM pending_logins WHERE token_hash = ? LIMIT 1',
                    [Mfa::tokenHash($token)]
                );
            } catch (Throwable $e) {
            }
            set_flash(
                'error',
                $known
                    ? 'Your verification link has already been used or has expired. Please sign in again to get a new one.'
                    : 'This verification link has expired or is invalid. Please sign in again.'
            );
            redirect('/login');
        }
        $uid = (int) $row['user_id'];
        $pid = (int) $row['id'];

        // One link approves exactly ONE attempt: rotate the stored hash so a
        // replayed request can never pass, then advance the stage.
        $nextStage = Mfa::isEnabled($uid) ? 'mfa' : 'setup';
        Mfa::advancePending($pid, $nextStage, null);
        if (Auth::check() && (int) Auth::id() !== $uid) {
            Auth::logout();
        }
        // Was the link opened on the SAME browser that started the login?
        // If not, the other browser advances on its own — say so clearly.
        $startedUa = (string) ($row['user_agent'] ?? '');
        $nowUa = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $sameBrowser = $startedUa !== '' && mb_substr($startedUa, 0, 60) === mb_substr($nowUa, 0, 60);
        session_regenerate_id(true);
        $_SESSION['pending_login_id'] = $pid;
        $_SESSION['last_activity'] = time();
        unset($_SESSION['auth_user_id'], $_SESSION['auth_completed']);
        Mfa::event($uid, 'login.device_verified');

        if ($nextStage === 'setup') {
            set_flash(
                'success',
                $sameBrowser
                    ? 'Device approved. Now protect your account with MFA.'
                    : 'Device approved from another browser. You can finish signing in here — or just go back to your other browser and continue there.'
            );
            redirect('/mfa/setup');
        }
        set_flash(
            'success',
            $sameBrowser
                ? 'Device approved. Enter your MFA code to finish signing in.'
                : 'Device approved from another browser. Enter your MFA code to finish here — or go back to your other browser, it advances on its own.'
        );
        redirect('/mfa/verify');
    }

    /** Abandon a pending login (destroys the server-side challenge). */
    public function cancelPending(array $params = []): void
    {
        Csrf::guard();
        try {
            $pid = (int) ($_SESSION['pending_login_id'] ?? 0);
            if ($pid > 0) {
                Mfa::consumePending($pid);
            }
        } catch (Throwable $e) {
        }
        unset($_SESSION['pending_login_id'], $_SESSION['pending_remember_uid'], $_SESSION['mfa_remember']);
        set_flash('info', 'Sign-in cancelled.');
        redirect('/login');
    }

    /* =========================== security settings ========================= */

    public function index(array $params = []): void
    {
        Auth::requireRole('admin', 'teacher', 'student', 'parent', 'registrar', 'accounting');
        $uid = (int) Auth::id();
        $mfa = Mfa::record($uid);
        $this->view('security/index', [
            'title' => 'Security settings',
            'mfaEnabled' => $mfa !== null && (int) $mfa['is_enabled'] === 1,
            'mfaSince' => $mfa['verified_at'] ?? null,
            'recoveryLeft' => Mfa::recoveryRemaining($uid),
            'devices' => Mfa::listDevices($uid),
            'sessions' => Mfa::listSessions($uid),
            'activity' => $this->recentActivity($uid),
        ]);
    }

    public function disableMfa(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin', 'teacher', 'student', 'parent', 'registrar', 'accounting');
        $uid = (int) Auth::id();
        if (!$this->confirmPassword($uid)) {
            set_flash('error', 'Current password is incorrect.');
            redirect('/security');
        }
        if (!Mfa::isEnabled($uid)) {
            set_flash('info', 'MFA is not enabled.');
            redirect('/security');
        }
        Mfa::disable($uid);
        try {
            $u = Database::one('SELECT * FROM users WHERE id = ? LIMIT 1', [$uid]);
            if ($u !== null) {
                send_mfa_status_email($u, false);
            }
        } catch (Throwable $e) {
        }
        set_flash('warning', 'MFA disabled. Re-enable it soon — your account is less protected without it.');
        redirect('/security');
    }

    public function regenCodes(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin', 'teacher', 'student', 'parent', 'registrar', 'accounting');
        $uid = (int) Auth::id();
        if (!Mfa::isEnabled($uid)) {
            set_flash('error', 'Enable MFA first.');
            redirect('/security');
        }
        if (!$this->confirmPassword($uid)) {
            set_flash('error', 'Current password is incorrect.');
            redirect('/security');
        }
        $codes = Mfa::generateRecoveryCodes($uid);
        if ($codes === []) {
            set_flash('error', 'Could not generate codes. Please try again.');
            redirect('/security');
        }
        $_SESSION['mfa_codes_once'] = $codes;
        set_flash('warning', 'New recovery codes generated — the old set no longer works. Save these now.');
        redirect('/mfa/codes');
    }

    public function revokeDevice(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin', 'teacher', 'student', 'parent', 'registrar', 'accounting');
        $uid = (int) Auth::id();
        $did = (int) ($params['id'] ?? 0);
        $row = null;
        try {
            $row = Database::one('SELECT device_name FROM trusted_devices WHERE id = ? AND user_id = ? LIMIT 1', [$did, $uid]);
        } catch (Throwable $e) {
        }
        if (Mfa::revokeDevice($uid, $did)) {
            try {
                $u = Database::one('SELECT * FROM users WHERE id = ? LIMIT 1', [$uid]);
                if ($u !== null) {
                    send_trusted_device_email($u, (string) ($row['device_name'] ?? 'device'), true);
                }
            } catch (Throwable $e) {
            }
            set_flash('success', 'Device revoked. It will need email approval on next sign-in.');
        } else {
            set_flash('error', 'Device not found.');
        }
        redirect('/security');
    }

    public function revokeSession(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin', 'teacher', 'student', 'parent', 'registrar', 'accounting');
        if (Mfa::revokeSession((int) Auth::id(), (int) ($params['id'] ?? 0))) {
            set_flash('success', 'Session revoked.');
        } else {
            set_flash('error', 'Session not found (or it is your current session — use Logout for that).');
        }
        redirect('/security');
    }

    public function revokeOtherSessions(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin', 'teacher', 'student', 'parent', 'registrar', 'accounting');
        $n = Mfa::revokeOtherSessions((int) Auth::id());
        set_flash('success', $n > 0 ? "Logged out of {$n} other device(s)." : 'No other active sessions.');
        redirect('/security');
    }

    /**
     * Grant the 30-day MFA skip to the CURRENT device (must already be
     * trusted). The caller is fully step-up authenticated, so no password
     * re-check is needed here.
     */
    public function rememberDevice(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin', 'teacher', 'student', 'parent', 'registrar', 'accounting');
        $uid = (int) Auth::id();
        if (!Mfa::isEnabled($uid)) {
            set_flash('error', 'Enable MFA first.');
            redirect('/security');
        }
        $dev = Mfa::trustedDeviceForRequest($uid);
        if ($dev === null) {
            set_flash('error', 'This browser is not a trusted device yet.');
            redirect('/security');
        }
        Mfa::grantMfaExemption($uid, (int) $dev['id']);
        try {
            $u = Database::one('SELECT * FROM users WHERE id = ? LIMIT 1', [$uid]);
            if ($u !== null) {
                send_mfa_exempt_email($u, true);
            }
        } catch (Throwable $e) {
        }
        set_flash('success', 'This device will skip the MFA code for 30 days. Password is still required every login.');
        redirect('/security');
    }

    /** Drop the 30-day MFA skip on one device (device stays trusted). */
    public function forgetDevice(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin', 'teacher', 'student', 'parent', 'registrar', 'accounting');
        $uid = (int) Auth::id();
        if (Mfa::forgetMfaExemption($uid, (int) ($params['id'] ?? 0))) {
            set_flash('success', 'MFA skip removed. That device will ask for the code again.');
        } else {
            set_flash('error', 'Device not found.');
        }
        redirect('/security');
    }

    /* ================================ internals ============================ */

    private function recentActivity(int $uid): array
    {
        try {
            return Database::all(
                "SELECT action, created_at, ip_address FROM audit_logs
                  WHERE actor_user_id = ? AND (action LIKE 'mfa.%' OR action LIKE 'login%' OR action LIKE 'device.%'
                    OR action LIKE 'session.%' OR action LIKE 'password.%' OR action = 'logout')
                  ORDER BY id DESC LIMIT 25",
                [$uid]
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    private function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return 'your email';
        }
        [$local, $domain] = explode('@', $email, 2);
        $shown = mb_substr($local, 0, 2) . str_repeat('*', max(0, min(6, mb_strlen($local) - 2)));
        return $shown . '@' . $domain;
    }

    /** Re-verify the account password before high-risk changes. */
    private function confirmPassword(int $uid): bool
    {
        $pw = (string) ($_POST['password'] ?? '');
        if ($pw === '') {
            return false;
        }
        try {
            $row = Database::one('SELECT password_hash FROM users WHERE id = ? LIMIT 1', [$uid]);
        } catch (Throwable $e) {
            return false;
        }
        return $row !== null && password_verify($pw, (string) $row['password_hash']);
    }
}
