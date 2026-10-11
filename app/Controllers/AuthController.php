<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Models\User;
use Throwable;

/**
 * Authentication & account lifecycle screens (Module 1).
 *
 *   GET  /login              POST /login
 *   POST /logout
 *   GET  /register           POST /register
 *   GET  /verify-pending     POST /verify/resend-link | /verify/send-otp | /verify/otp
 *   GET  /forgot-password    POST /forgot-password
 *   GET  /reset-password     POST /reset-password
 */
final class AuthController extends Controller
{
    /* ================================ login ================================ */

    public function showLogin(array $params = []): void
    {
        if (Auth::check()) {
            redirect('/dashboard');
        }
        // A half-finished step-up challenge resumes at its gate.
        $pending = Auth::pendingLogin();
        if ($pending !== null) {
            redirect(match ((string) ($pending['stage'] ?? 'mfa')) {
                'device' => '/login/device',
                'setup' => '/mfa/setup',
                default => '/mfa/verify',
            });
        }
        if (!empty($_SESSION['pending_remember_uid'])) {
            redirect('/mfa/verify');
        }
        $this->view('auth/login', ['title' => 'Sign in'], 'shared/layout_auth');
    }

    public function login(array $params = []): void
    {
        Csrf::guard();

        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            set_flash('error', 'Please enter both email and password.');
            redirect('/login');
        }

        $result = Auth::attempt($email, $password, client_ip());

        if (!empty($result['ok'])) {
            // Only reachable via the 30-day remembered-device exemption
            // (password verified on a trusted, exempt device).
            Auth::directLogin(
                (int) $result['user']['id'],
                (!empty($_POST['remember']) && $_POST['remember'] == '1')
            );
            set_flash('success', 'Welcome back, ' . $result['user']['first_name'] . '!');
            redirect('/dashboard');
        }
        // Step-up: password was correct but a gate (device / MFA / setup)
        // still stands. NEVER create a full session here.
        if (!empty($result['pending'])) {
            $_SESSION['mfa_remember'] = (!empty($_POST['remember']) && $_POST['remember'] == '1') ? 1 : 0;
            if ($result['pending'] === 'device') {
                try {
                    $u = \App\Models\User::findByEmail($email);
                    if ($u !== null && ($block = Auth::checkSendThrottle('device', (string) $u['email'])) === null) {
                        send_new_device_email($u, (string) ($result['device_token'] ?? ''), (int) ($result['pending_id'] ?? 0));
                        Auth::recordSend('device', (string) $u['email']);
                    }
                } catch (Throwable $e) {
                    storage_log('device email failed: ' . $e->getMessage());
                }
                set_flash('info', 'We sent a verification email to your registered email address. This device is not recognized.');
                redirect('/login/device');
            }
            if ($result['pending'] === 'setup') {
                set_flash('warning', $result['message']);
                redirect('/mfa/setup');
            }
            redirect('/mfa/verify');
        }
        if (!empty($result['needs_verification'])) {
            $_SESSION['pending_verify_uid'] = (int) $result['user_id'];
            set_flash('warning', $result['message']);
            redirect('/verify-pending');
        }
        set_flash('error', $result['message']);
        redirect('/login');
    }

    public function logout(array $params = []): void
    {
        Csrf::guard();
        Auth::logout();
        set_flash('success', 'You have been signed out.');
        redirect('/login');
    }

    /* =============================== register ============================== */

    public function showRegister(array $params = []): void
    {
        if (Auth::check()) {
            redirect('/dashboard');
        }
        // Bot-timing baseline (server-side; read again on POST).
        $_SESSION['register_form_at'] = time();
        // Adaptive challenge: only after suspicious volume from this IP.
        $captchaQ = null;
        try {
            if (\App\Core\Registration::captchaRequired(client_ip())) {
                $captchaQ = $_SESSION['reg_captcha_q'] ?? \App\Core\Registration::newCaptcha();
                $_SESSION['reg_captcha_q'] = $captchaQ;
            } else {
                \App\Core\Registration::clearCaptcha();
                unset($_SESSION['reg_captcha_q']);
            }
        } catch (Throwable $e) {
            $captchaQ = null;
        }
        $this->view('auth/register', ['title' => 'Create account', 'old' => [], 'errors' => [], 'captchaQ' => $captchaQ], 'shared/layout_auth');
    }

    /* ------------------------ Secure Teacher Registration -------------------- */
    public function showRegisterTeacher(array $params = []): void
    {
        if (Auth::check()) redirect('/dashboard');
        $prefillCode = trim((string)($_GET['code'] ?? ''));
        $this->view('auth/register_teacher', ['title' => 'Teacher Registration (Invite Only)', 'old' => ['invite_code'=>$prefillCode], 'errors' => []], 'shared/layout_auth');
    }

    public function registerTeacher(array $params = []): void
    {
        Csrf::guard();
        if (Auth::check()) redirect('/dashboard');

        $old = [
            'invite_code' => trim((string)($_POST['invite_code'] ?? '')),
            'first_name'  => trim((string)($_POST['first_name'] ?? '')),
            'middle_name' => trim((string)($_POST['middle_name'] ?? '')),
            'last_name'   => trim((string)($_POST['last_name'] ?? '')),
            'suffix'      => trim((string)($_POST['suffix'] ?? '')),
            'email'       => mb_strtolower(trim((string)($_POST['email'] ?? ''))),
            'phone'       => trim((string)($_POST['phone'] ?? '')),
            'department'  => trim((string)($_POST['department'] ?? '')),
            'position'    => trim((string)($_POST['position'] ?? '')),
        ];
        $password = (string)($_POST['password'] ?? '');
        $confirm  = (string)($_POST['password_confirm'] ?? '');
        $invitePlain = $old['invite_code'];
        $termsAccepted = ((string)($_POST['terms'] ?? '')) === '1';

        $errors = [];
        if (!$termsAccepted) $errors[] = 'You must open and agree to the Data Privacy Notice & Teacher Terms before creating a teacher account.';
        if ($invitePlain === '') $errors[] = 'Teacher invite code is required (format TCH-XXXX-XXXX). Contact admin for a valid code.';
        foreach ([['first_name','First name'],['last_name','Last name']] as [$f,$l]) if ($old[$f]==='') $errors[]="$l is required.";
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || strlen($old['email'])>190 || !preg_match('/^[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}$/i', $old['email']) || str_contains($old['email'], '..')) $errors[]='Enter a valid work email like name@domain.com';
        $phoneDigitsT = preg_replace('/\D/','', $old['phone'] ?? '');
        if ($phoneDigitsT==='' || $phoneDigitsT===null) $errors[]='Mobile is required — 11 digits starting with 09 (e.g., 09123456789).';
        elseif (!preg_match('/^09\d{9}$/', $phoneDigitsT)) $errors[]='Mobile must be exactly 11 digits starting with 09 — you entered '.strlen($phoneDigitsT).' digits.';
        else $old['phone']=$phoneDigitsT;
        foreach (Auth::assertPasswordStrength($password) as $u) $errors[]='Password needs '.$u.'.';
        if ($password !== $confirm) $errors[]='Password confirmation does not match.';
        if (\App\Models\User::findByEmail($old['email']) !== null) $errors[]='Email already exists.';

        // Secure invite verification (hashed, single-use, expiry, email_hint, attempt limit)
        $inviteRow = null;
        if ($invitePlain !== '' && $errors===[]) {
            try {
                $cands = \App\Core\Database::all("SELECT * FROM teacher_invites WHERE is_used=0 AND expires_at > NOW() ORDER BY created_at DESC LIMIT 50");
                foreach ($cands as $c) {
                    if (password_verify($invitePlain, $c['code_hash'])) { $inviteRow=$c; break; }
                }
                if ($inviteRow===null) {
                    $errors[]='Invalid or expired invite code. Request a new code from admin.';
                } elseif (!empty($inviteRow['email_hint']) && mb_strtolower($inviteRow['email_hint']) !== $old['email']) {
                    $errors[]='Invite code is restricted to email: '.$inviteRow['email_hint'].'. Use that email or request a new invite.';
                } elseif ((int)$inviteRow['attempts'] >= 5) {
                    $errors[]='Invite code locked due to too many attempts. Contact admin.';
                }
            } catch (Throwable $e) { $errors[]='Could not verify invite code. Try again.'; }
        }

        if ($errors !== []) {
            $this->view('auth/register_teacher', ['title'=>'Teacher Registration (Invite Only)','old'=>$old,'errors'=>$errors], 'shared/layout_auth');
            return;
        }

        // Create teacher user + teachers profile atomically, then consume invite
        try {
            $userId = \App\Core\Database::transaction(function() use ($old, $password, $inviteRow, $invitePlain) {
                $userRow = [
                    'role_id'       => \App\Models\User::ROLE_TEACHER,
                    'email'         => $old['email'],
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'first_name'    => $old['first_name'],
                    'middle_name'   => $old['middle_name'] ?: null,
                    'last_name'     => $old['last_name'],
                    'suffix'        => $old['suffix'] ?: null,
                    'phone'         => $old['phone'] ?: null,
                    'status'        => 'pending_verification',
                ];
                try {
                    $userRow['terms_accepted_at'] = date('Y-m-d H:i:s');
                    $uid = \App\Core\Database::insert('users', $userRow);
                } catch (\Throwable $e) {
                    // Mixed deploy state: column arrives with the migration.
                    if (str_contains($e->getMessage(), 'terms_accepted_at')) {
                        unset($userRow['terms_accepted_at']);
                        $uid = \App\Core\Database::insert('users', $userRow);
                    } else {
                        throw $e;
                    }
                }
                $empNo = 'T-'.date('Y').'-'.str_pad((string)$uid,4,'0',STR_PAD_LEFT);
                // Ensure unique employee_no (fallback to random suffix on collision)
                $try = 0;
                while (\App\Core\Database::one("SELECT id FROM teachers WHERE employee_no=? LIMIT 1", [$empNo])) {
                    $empNo = 'T-'.date('Y').'-'.str_pad((string)$uid,4,'0',STR_PAD_LEFT).'-'.bin2hex(random_bytes(1));
                    if (++$try>3) break;
                }
                \App\Core\Database::insert('teachers', [
                    'user_id'     => $uid,
                    'employee_no' => $empNo,
                    'department'  => $old['department'] ?: ($inviteRow['department'] ?? null),
                    'position'    => $old['position'] ?: null,
                ]);
                // Mark invite as used
                \App\Core\Database::run("UPDATE teacher_invites SET is_used=1, used_at=NOW(), used_by=? WHERE id=?", [$uid, (int)$inviteRow['id']]);
                return $uid;
            });

            // Email verification (same as normal register)
            try {
                $token = Auth::issueVerification($userId);
                send_verification_link_email(\App\Models\User::find($userId) ?? [], $token);
            } catch (Throwable $e) { storage_log('teacher verify email failed '.$userId.': '.$e->getMessage()); }

            \App\Core\Database::insert('audit_logs', ['actor_user_id'=>$userId,'action'=>'teacher.registered_via_invite','entity_type'=>'teacher_invites','entity_id'=>(int)$inviteRow['id'],'ip_address'=>client_ip()]);
            $_SESSION['pending_verify_uid']=$userId;
            set_flash('success','Teacher account created! Check inbox to verify email. Invite code consumed (single-use).');
            redirect('/verify-pending');
        } catch (Throwable $e) {
            storage_log('registerTeacher failed: '.$e->getMessage());
            // Increment attempts on the invite if found
            if ($inviteRow) try { \App\Core\Database::run("UPDATE teacher_invites SET attempts=attempts+1 WHERE id=?", [(int)$inviteRow['id']]); } catch(Throwable $e2){}
            $this->view('auth/register_teacher', ['title'=>'Teacher Registration (Invite Only)','old'=>$old,'errors'=>['Could not create account: '.$e->getMessage()]], 'shared/layout_auth');
            return;
        }
    }

    public function register(array $params = []): void
    {
        Csrf::guard();

        $ip = client_ip();
        $regFail = function (array $old, array $errors) use ($ip): void {
            \App\Core\Registration::record($old['email'] ?? '', $old['phone'] ?? '', $ip, false);
            $captchaQ = $_SESSION['reg_captcha_q'] ?? null;
            try {
                if ($captchaQ === null && \App\Core\Registration::captchaRequired($ip)) {
                    $captchaQ = \App\Core\Registration::newCaptcha();
                    $_SESSION['reg_captcha_q'] = $captchaQ;
                }
            } catch (Throwable $e) {
                $captchaQ = null;
            }
            $this->view('auth/register', ['title' => 'Create account', 'old' => $old, 'errors' => $errors, 'captchaQ' => $captchaQ], 'shared/layout_auth');
        };

        // ---- anti-abuse: temporary throttling (never a permanent IP block) ----
        if (($block = \App\Core\Registration::checkThrottle($ip, mb_strtolower(trim((string) ($_POST['email'] ?? ''))))) !== null) {
            try {
                \App\Core\Database::insert('audit_logs', ['actor_user_id' => null, 'action' => 'register.rate_limited', 'entity_type' => 'users', 'entity_id' => 0, 'ip_address' => $ip]);
            } catch (Throwable $e) {
            }
            $regFail(['role' => 'student', 'first_name' => '', 'middle_name' => '', 'last_name' => '', 'suffix' => '', 'email' => '', 'phone' => ''], [$block]);
            return;
        }

        // ---- honeypot: invisible field humans never fill ----
        if (trim((string) ($_POST['website'] ?? '')) !== '') {
            storage_log('register honeypot hit from ' . $ip);
            $regFail(['role' => 'student', 'first_name' => '', 'middle_name' => '', 'last_name' => '', 'suffix' => '', 'email' => '', 'phone' => ''], ['We could not create your account right now. Please try again.']);
            return;
        }

        // ---- minimum fill time: instant posts are almost always bots ----
        if (isset($_SESSION['register_form_at']) && (time() - (int) $_SESSION['register_form_at']) < \App\Core\Registration::MIN_FILL_SECONDS) {
            $regFail(['role' => 'student', 'first_name' => trim((string) ($_POST['first_name'] ?? '')), 'middle_name' => trim((string) ($_POST['middle_name'] ?? '')), 'last_name' => trim((string) ($_POST['last_name'] ?? '')), 'suffix' => trim((string) ($_POST['suffix'] ?? '')), 'email' => mb_strtolower(trim((string) ($_POST['email'] ?? ''))), 'phone' => trim((string) ($_POST['phone'] ?? ''))], ['Please take a moment to complete the form and try again.']);
            return;
        }

        // ---- adaptive challenge (only required after suspicious volume) ----
        if (!\App\Core\Registration::verifyCaptcha($_POST['captcha'] ?? null)) {
            $old = [
                'role' => (string) ($_POST['role'] ?? 'student'),
                'first_name' => trim((string) ($_POST['first_name'] ?? '')),
                'middle_name' => trim((string) ($_POST['middle_name'] ?? '')),
                'last_name' => trim((string) ($_POST['last_name'] ?? '')),
                'suffix' => trim((string) ($_POST['suffix'] ?? '')),
                'email' => mb_strtolower(trim((string) ($_POST['email'] ?? ''))),
                'phone' => trim((string) ($_POST['phone'] ?? '')),
            ];
            \App\Core\Registration::record($old['email'], $old['phone'], $ip, false);
            $captchaQ = \App\Core\Registration::newCaptcha();
            $_SESSION['reg_captcha_q'] = $captchaQ;
            $this->view('auth/register', ['title' => 'Create account', 'old' => $old, 'errors' => ['The verification answer was incorrect. Please try again.'], 'captchaQ' => $captchaQ], 'shared/layout_auth');
            return;
        }

        $old = [
            'role'        => (string) ($_POST['role'] ?? 'student'),
            'first_name'  => trim((string) ($_POST['first_name'] ?? '')),
            'middle_name' => trim((string) ($_POST['middle_name'] ?? '')),
            'last_name'   => trim((string) ($_POST['last_name'] ?? '')),
            'suffix'      => trim((string) ($_POST['suffix'] ?? '')),
            'email'       => mb_strtolower(trim((string) ($_POST['email'] ?? ''))),
            'phone'       => trim((string) ($_POST['phone'] ?? '')),
        ];
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['password_confirm'] ?? '');
        $termsAccepted = (string) ($_POST['terms'] ?? '') === '1';

        /* ---------------------------- validation --------------------------- */
        $errors = [];

        // SECURITY: block teacher/admin self-registration via public form (invite-only)
        if (in_array($old['role'], ['teacher','admin'], true)) {
            $errors[] = 'Teacher registration requires an invite code. Use "Register as Teacher (Invite Only)" or contact admin.';
        }
        if (!in_array($old['role'], ['student', 'parent'], true)) {
            $errors[] = 'Please choose whether you are a student or a parent/guardian.';
        }
        // Personal-name quality (min 2 chars, real letters, no digits/symbols/fakes).
        foreach ([
            ['first_name', 'First name', true],
            ['last_name', 'Last name', true],
            ['middle_name', 'Middle name', false],
        ] as [$field, $label, $req]) {
            $err = \App\Core\Registration::validateName($old[$field], $label, $req);
            if ($err !== null) $errors[] = $err;
        }
        if (($suffixErr = \App\Core\Registration::validateSuffix($old['suffix'])) !== null) {
            $errors[] = $suffixErr;
        }
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || strlen($old['email']) > 190 || !preg_match('/^[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}$/i', $old['email']) || str_contains($old['email'], '..')) {
            $errors[] = 'Please enter a valid email address (e.g., name@domain.com).';
        }
        $phoneDigits = preg_replace('/\D/', '', $old['phone'] ?? '');
        if ($phoneDigits === '' || $phoneDigits === null) {
            $errors[] = 'Mobile number is required — must be exactly 11 digits starting with 09 (e.g., 09123456789).';
        } elseif (!preg_match('/^09\d{9}$/', $phoneDigits)) {
            $errors[] = 'Mobile number must be exactly 11 digits and start with 09 (e.g., 09123456789). You entered '.strlen($phoneDigits).' digits.';
        } else {
            $old['phone'] = $phoneDigits;
        }
        foreach (Auth::assertPasswordStrength($password) as $unmet) {
            $errors[] = 'Password needs ' . $unmet . '.';
        }
        if ($password !== $confirm) {
            $errors[] = 'Password confirmation does not match.';
        }
        if (User::findByEmail($old['email']) !== null) {
            $errors[] = 'An account with this email already exists. Try signing in instead.';
        }
        // Exact-identity duplicate: same mobile + same person (shared family
        // numbers with different names are still allowed, but audit-noted).
        if ($errors === [] && ($phoneDigits ?? '') !== '' && preg_match('/^09\d{9}$/', (string) $phoneDigits)) {
            try {
                $samePhone = \App\Core\Database::all("SELECT first_name, last_name FROM users WHERE phone = ? LIMIT 5", [$phoneDigits]);
                foreach ($samePhone as $sp) {
                    if (mb_strtolower(trim((string) $sp['first_name'])) === mb_strtolower($old['first_name'])
                        && mb_strtolower(trim((string) $sp['last_name'])) === mb_strtolower($old['last_name'])) {
                        $errors[] = 'An account with these details already exists. Try signing in instead.';
                        try {
                            \App\Core\Database::insert('audit_logs', ['actor_user_id' => null, 'action' => 'register.duplicate_blocked', 'entity_type' => 'users', 'entity_id' => 0, 'ip_address' => $ip]);
                        } catch (Throwable $e) {
                        }
                        break;
                    }
                }
                if ($errors === [] && $samePhone !== []) {
                    try {
                        \App\Core\Database::insert('audit_logs', ['actor_user_id' => null, 'action' => 'register.shared_phone', 'entity_type' => 'users', 'entity_id' => 0, 'new_values_json' => json_encode(['phone' => $phoneDigits]), 'ip_address' => $ip]);
                    } catch (Throwable $e) {
                    }
                }
            } catch (Throwable $e) {
            }
        }
        // Privacy/Terms acceptance is enforced server-side (checkbox has a
        // name and is only enableable after the modal review on the form).
        if (!$termsAccepted) {
            $errors[] = 'You must agree to the Data Privacy Notice & Portal Terms.';
        }

        if ($errors !== []) {
            $regFail($old, $errors);
            return;
        }

        /* ---------------------------- persistence -------------------------- */
        try {
            $userId = User::register($old + ['password' => $password, 'terms_accepted_at' => date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            storage_log('register failed: ' . $e->getMessage());
            $regFail($old, ['We could not create your account right now. Please try again.']);
            return;
        }
        \App\Core\Registration::record($old['email'], $old['phone'], $ip, true);
        \App\Core\Registration::clearCaptcha();
        unset($_SESSION['reg_captcha_q'], $_SESSION['register_form_at']);
        try {
            \App\Core\Database::insert('audit_logs', ['actor_user_id' => $userId, 'action' => 'user.registered', 'entity_type' => 'users', 'entity_id' => $userId, 'ip_address' => $ip]);
        } catch (Throwable $e) {
        }

        /* --------------------- verification email (best effort) ------------ */
        try {
            $token = Auth::issueVerification($userId);
            send_verification_link_email(User::find($userId) ?? [], $token);
        } catch (Throwable $e) {
            storage_log('verification email failed for user ' . $userId . ': ' . $e->getMessage());
        }

        $_SESSION['pending_verify_uid'] = $userId;
        set_flash('success', 'Account created! Check your inbox to verify your email address.');
        redirect('/verify-pending');
    }

    /* ===================== email verification pending ====================== */

    public function verifyPending(array $params = []): void
    {
        $uid = $_SESSION['pending_verify_uid'] ?? null;
        if (!$uid) {
            redirect('/login');
        }
        $user = User::find((int) $uid);
        if ($user === null) {
            unset($_SESSION['pending_verify_uid']);
            redirect('/login');
        }
        if ((int) $user['is_email_verified'] === 1) {
            $this->view('auth/verify_notice', ['title' => 'Verified', 'user' => $user, 'alreadyVerified' => true], 'shared/layout_auth');
            return;
        }
        $cooldownLink = max(0, 60 - (time() - (int)($_SESSION['last_resend_link_at'] ?? 0)));
        $cooldownOtp = max(0, 60 - (time() - (int)($_SESSION['last_send_otp_at'] ?? 0)));
        $this->view('auth/verify_notice', ['title' => 'Verify email', 'user' => $user, 'alreadyVerified'=>false, 'cooldownLink'=>$cooldownLink, 'cooldownOtp'=>$cooldownOtp], 'shared/layout_auth');
    }

    public function verifyStatus(array $params = []): void
    {
        $uid = $_SESSION['pending_verify_uid'] ?? null;
        if (!$uid) { json_response(['verified'=>false,'reason'=>'no_session']); }
        $user = User::find((int)$uid);
        if (!$user) { json_response(['verified'=>false,'reason'=>'not_found']); }
        $verified = (int)$user['is_email_verified'] === 1;
        json_response(['verified'=>$verified,'email'=>$user['email'] ?? '','status'=>$user['status'] ?? '']);
    }

    public function resendLink(array $params = []): void
    {
        Csrf::guard();
        $now = time();
        $last = (int)($_SESSION['last_resend_link_at'] ?? 0);
        if ($now - $last < 60) {
            $wait = 60 - ($now - $last);
            set_flash('error', 'Please wait '.$wait.'s before requesting another verification link (cooldown).');
            redirect('/verify-pending');
        }
        $user = $this->pendingUserOrRedirect();
        if (($block = Auth::checkSendThrottle('link', (string) ($user['email'] ?? ''))) !== null) {
            set_flash('error', $block);
            redirect('/verify-pending');
        }
        $token = Auth::issueVerification((int) $user['id']);
        send_verification_link_email($user, $token);
        Auth::recordSend('link', (string) ($user['email'] ?? ''));
        $_SESSION['last_resend_link_at'] = $now;
        set_flash('success', 'A new verification link was sent to ' . $user['email'] . '. (Cooldown 60s)');
        redirect('/verify-pending');
    }

    public function sendOtp(array $params = []): void
    {
        Csrf::guard();
        $now = time();
        $last = (int)($_SESSION['last_send_otp_at'] ?? 0);
        if ($now - $last < 60) {
            $wait = 60 - ($now - $last);
            set_flash('error', 'Please wait '.$wait.'s before requesting another 6-digit code (cooldown).');
            redirect('/verify-pending');
        }
        $user = $this->pendingUserOrRedirect();
        if (($block = Auth::checkSendThrottle('otp', (string) ($user['email'] ?? ''))) !== null) {
            set_flash('error', $block);
            redirect('/verify-pending');
        }
        $code = Auth::issueOtp((int) $user['id']);
        send_otp_email($user, $code);
        Auth::recordSend('otp', (string) ($user['email'] ?? ''));
        $_SESSION['last_send_otp_at'] = $now;
        set_flash('success', 'We emailed a 6-digit code to ' . $user['email'] . '. It expires in 10 minutes. (Cooldown 60s)');
        redirect('/verify-pending');
    }

    public function checkOtp(array $params = []): void
    {
        Csrf::guard();
        $uid  = $_SESSION['pending_verify_uid'] ?? null;
        $code = trim((string) ($_POST['otp'] ?? ''));
        if (!$uid) {
            redirect('/login');
        }

        $result = Auth::verifyOtp((int) $uid, $code);
        if (!$result['ok']) {
            set_flash('error', $result['message']);
            redirect('/verify-pending');
        }

        // Email ownership proven. MFA is mandatory: force authenticator
        // setup before ANY dashboard access (no direct full session here).
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        \App\Core\Mfa::invalidateUserPendings((int) $uid);
        $pid = \App\Core\Mfa::createPending(
            (int) $uid, 'setup', client_ip(), $ua, \App\Core\Mfa::deviceName($ua)
        );
        session_regenerate_id(true);
        unset($_SESSION['auth_user_id'], $_SESSION['auth_completed']);
        $_SESSION['pending_login_id'] = $pid;
        $_SESSION['last_activity'] = time();
        unset($_SESSION['pending_verify_uid']);

        set_flash('success', 'Email verified — now protect your account with MFA.');
        redirect('/mfa/setup');
    }

    /* =========================== password reset ============================ */

    public function showForgotPassword(array $params = []): void
    {
        $this->view('auth/forgot_password', ['title' => 'Forgot password'], 'shared/layout_auth');
    }

    public function sendResetLink(array $params = []): void
    {
        Csrf::guard();
        $email = trim((string) ($_POST['email'] ?? ''));

        // Always answer identically to avoid account enumeration.
        // Throttled quietly: on throttle we still show the generic success.
        $throttled = Auth::checkSendThrottle('reset', $email) !== null;
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && !$throttled) {
            $token = Auth::issuePasswordReset($email);
            if ($token !== null) {
                $user = User::findByEmail($email);
                if ($user !== null) {
                    send_password_reset_email($user, $token);
                }
                Auth::recordSend('reset', $email);
            }
        } elseif ($throttled) {
            storage_log('reset throttled for ' . $email);
        }
        set_flash(
            'success',
            'If that email address belongs to an account, a reset link is on its way. '
            . 'Check your inbox (and spam folder).'
        );
        redirect('/login');
    }

    public function showResetPassword(array $params = []): void
    {
        $token = trim((string) ($_GET['token'] ?? ''));
        $this->view(
            'auth/reset_password',
            ['title' => 'Reset password', 'token' => $token, 'errors' => []],
            'shared/layout_auth'
        );
    }

    public function doResetPassword(array $params = []): void
    {
        Csrf::guard();
        $token    = trim((string) ($_POST['token'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['password_confirm'] ?? '');

        $errors = [];
        if ($password !== $confirm) {
            $errors[] = 'Password confirmation does not match.';
        }
        foreach (Auth::assertPasswordStrength($password) as $unmet) {
            $errors[] = 'Password needs ' . $unmet . '.';
        }

        $result = $errors === [] ? Auth::resetPassword($token, $password) : ['ok' => false];
        if (!empty($result['ok'])) {
            set_flash('success', $result['message']);
            redirect('/login');
        }

        $this->view('auth/reset_password', [
            'title'  => 'Reset password',
            'token'  => $token,
            'errors' => array_merge($errors, isset($result['message']) ? [$result['message']] : []),
        ], 'shared/layout_auth');
    }

    /* ============================== internals ============================== */

    /** Fetch the unverified user tracked in session; bounce otherwise. */
    private function pendingUserOrRedirect(): array
    {
        $uid = $_SESSION['pending_verify_uid'] ?? null;
        if (!$uid) {
            redirect('/login');
        }
        $user = User::find((int) $uid);
        if ($user === null || (int) $user['is_email_verified'] === 1) {
            unset($_SESSION['pending_verify_uid']);
            redirect('/login');
        }
        return $user;
    }
}