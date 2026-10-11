<?php
declare(strict_types=1);

/**
 * EduPortal - Mailer (helpers/mailer.php)
 * Datamex College of Saint Adeline
 *
 * PHPMailer over Hostinger Webmail SMTP.
 *   Port 465 => SMTPS  ('ssl')   <- recommended by Hostinger
 *   Port 587 => STARTTLS ('tls')
 *
 * INSTALLING PHPMAILER (pick ONE):
 *   A) Composer (preferred): run `composer require phpmailer/phpmailer`
 *      locally, then upload the generated /vendor folder next to /app
 *      (i.e. <home-dir>/vendor). It is auto-detected.
 *   B) Manual: download the PHPMailer "src" folder and upload it to
 *      app/lib/phpmailer/src/  (Exception.php, PHPMailer.php, SMTP.php).
 *
 * Every send is recorded in the email_log table for auditing/debugging.
 */

/* ------------------------------------------------------------------ */
/* PHPMailer discovery                                                 */
/* ------------------------------------------------------------------ */

/** @internal Loads PHPMailer classes; returns true when available. */
function eduportal_phpmailer_bootstrap(): bool
{
    static $loaded = false;
    if ($loaded) {
        return true;
    }

    // A) Composer autoloads (project root or inside the lib folder)
    foreach ([ROOT_PATH . '/vendor/autoload.php', APP_ROOT . '/lib/phpmailer/vendor/autoload.php'] as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;
            $loaded = class_exists('\PHPMailer\PHPMailer\PHPMailer');
            return $loaded;
        }
    }

    // B) Manual src bundle
    $src = APP_ROOT . '/lib/phpmailer/src/';
    if (is_file($src . 'PHPMailer.php')) {
        require_once $src . 'Exception.php';
        require_once $src . 'PHPMailer.php';
        require_once $src . 'SMTP.php';
        $loaded = class_exists('\PHPMailer\PHPMailer\PHPMailer');
        return $loaded;
    }

    return false;
}

/* ------------------------------------------------------------------ */
/* Settings: credentials constants + admin-editable overrides          */
/* ------------------------------------------------------------------ */

function edu_mail_settings(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    // Defaults from credentials.php
    $settings = [
        'host'       => SMTP_HOST,
        'port'       => SMTP_PORT,
        'encryption' => SMTP_PORT === 465 ? 'ssl' : 'tls',
        'user'       => SMTP_USER,
        'pass'       => SMTP_PASS,
        'from_email' => SMTP_FROM_EMAIL,
        'from_name'  => MAIL_FROM_NAME,
    ];

    // Admin panel may override via system_settings (group = smtp)
    try {
        $rows = \App\Core\Database::all(
            "SELECT setting_key, setting_value FROM system_settings WHERE setting_group = 'smtp'"
        );
        foreach ($rows as $row) {
            $v = trim((string) $row['setting_value']);
            if ($v === '') {
                continue;
            }
            switch ($row['setting_key']) {
                case 'smtp_host':      $settings['host'] = $v; break;
                case 'smtp_port':      $settings['port'] = (int) $v;
                                       $settings['encryption'] = ((int) $v) === 465 ? 'ssl' : 'tls'; break;
                case 'smtp_encryption': $settings['encryption'] = strtolower($v); break;
                case 'smtp_username':  $settings['user'] = $v; break;
                case 'smtp_password':  $settings['pass'] = $v; break;
            }
        }
    } catch (\Throwable $e) {
        storage_log('edu_mail_settings override load failed: ' . $e->getMessage());
    }

    $cache = $settings;
    return $cache;
}

/* ------------------------------------------------------------------ */
/* Sender service                                                      */
/* ------------------------------------------------------------------ */

final class EduMailer
{
    /**
     * Send an HTML email through Hostinger SMTP. Returns bool success.
     * Failures are logged (email_log + storage log) but never fatal.
     */
    public function send(string $toEmail, string $toName, string $subject, string $html, string $template = 'generic'): bool
    {
        $loggedId = edu_email_log($toEmail, $subject, $template, 'queued');

        if (!eduportal_phpmailer_bootstrap()) {
            storage_log('PHPMailer not installed. See helpers/mailer.php header comment.');
            edu_email_mark($loggedId, 'failed', 'PHPMailer library missing');
            return false;
        }

        try {
            $cfg = edu_mail_settings();

            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $cfg['host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $cfg['user'];
            $mail->Password   = $cfg['pass'];
            $mail->Port       = $cfg['port'];
            $mail->CharSet    = 'UTF-8';
            $mail->SMTPSecure = $cfg['encryption'] === 'ssl'
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS          // 465
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;      // 587

            $isLocalMail = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost','127.0.0.1','::1'], true) || str_starts_with($_SERVER['HTTP_HOST'] ?? '', 'localhost:') || str_starts_with($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1:');
            if ($isLocalMail) {
                $mail->SMTPOptions = ['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]];
            }
            // Keep TLS verification ON in production (default behavior).
            $mail->setFrom($cfg['from_email'], $cfg['from_name']);
            $mail->addAddress($toEmail, $toName !== '' ? $toName : $toEmail);

            $mail->isHTML(true);
            $mail->Subject  = $subject;
            $mail->Body     = $html;
            $mail->AltBody  = trim(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $html)));
            $mail->XMailer  = 'EduPortal Mailer';                       // hide PHPMailer version

            $sent = $mail->send();
            edu_email_mark($loggedId, $sent ? 'sent' : 'failed', $sent ? null : 'unknown failure');
            return $sent;
        } catch (\Throwable $e) {
            edu_email_mark($loggedId, 'failed', mb_substr($e->getMessage(), 0, 490));
            return false;
        }
    }
}

/** Shared instance helper. */
function edu_mailer(): EduMailer
{
    static $mailer = null;
    return $mailer ??= new EduMailer();
}

/* ------------------------------------------------------------------ */
/* email_log plumbing                                                  */
/* ------------------------------------------------------------------ */

function edu_email_log(string $recipient, string $subject, string $template, string $status): int
{
    try {
        return \App\Core\Database::insert('email_log', [
            'recipient_email' => $recipient,
            'subject'         => mb_substr($subject, 0, 190),
            'template'        => $template,
            'status'          => $status,
        ]);
    } catch (\Throwable $e) {
        storage_log('email_log insert failed: ' . $e->getMessage());
        return 0;
    }
}

function edu_email_mark(int $logId, string $status, ?string $error): void
{
    if ($logId === 0) {
        return;
    }
    try {
        \App\Core\Database::run(
            'UPDATE email_log SET status = ?, error_message = ?, sent_at = IF(? = \'sent\', NOW(), NULL) WHERE id = ?',
            [$status, $error, $status, $logId]
        );
    } catch (\Throwable $e) {
        storage_log('email_log update failed: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------ */
/* Branded HTML shell                                                  */
/* ------------------------------------------------------------------ */

function edu_email_shell(string $title, string $innerHtml): string
{
    return edu_email_shell_role($title, $innerHtml, 'student');
}

function edu_email_shell_role(string $title, string $innerHtml, string $role): string
{
    $school = e(\APP_NAME === '' ? 'EduPortal' : MAIL_FROM_NAME);
    $year   = date('Y');
    $isAdmin = $role === 'admin';
    $isTeacher = $role === 'teacher';
    $isRegistrar = $role === 'registrar';
    $isAccounting = $role === 'accounting';
    if ($isAdmin) { $headerBg = '#0F172A'; $badge = 'ADMIN • Full Control'; $icon = '👑'; }
    elseif ($isTeacher) { $headerBg = '#7c2d12'; $badge = 'TEACHER • Invite-Only'; $icon = '🛡️'; }
    elseif ($isRegistrar) { $headerBg = '#312e81'; $badge = 'REGISTRAR • Academic Records'; $icon = '📋'; }
    elseif ($isAccounting) { $headerBg = '#0f766e'; $badge = 'ACCOUNTING • Finance'; $icon = '🧾'; }
    else { $headerBg = '#1e3a5f'; $badge = 'STUDENT • Verified Enrollment'; $icon = '🎓'; }
    $headerAccent = $isTeacher ? '#92400e' : ($isAdmin ? '#B45309' : '#1e3a5f');
    return <<<HTML
<!doctype html>
<html lang="en">
<body style="margin:0;padding:24px;background:#f3f5f9;font-family:Segoe UI,Arial,sans-serif;">
  <div style="max-width:600px;margin:auto;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e2e6ee;">
    <div style="background:{$headerBg};color:#ffffff;padding:22px 28px;position:relative;">
      <div style="position:absolute;top:12px;right:16px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.25);padding:4px 10px;border-radius:20px;font-size:11px;letter-spacing:.06em;">{$badge}</div>
      <div style="font-size:20px;font-weight:700;">{$icon} EduPortal</div>
      <div style="font-size:13px;opacity:.88;">{$school}</div>
    </div>
    <div style="padding:28px;">
      <h2 style="margin:0 0 14px;font-size:18px;color:#1e293b;">{$title}</h2>
      {$innerHtml}
    </div>
    <div style="background:#f8fafc;border-top:1px solid #e2e6ee;padding:16px 28px;font-size:12px;color:#64748b;line-height:1.5;">
      This is an automated message from the {$school} portal. Please do not reply directly.<br>
      &copy; {$year} {$school}. All rights reserved.
    </div>
  </div>
</body>
</html>
HTML;
}

function edu_email_button(string $link, string $label): string
{
    return edu_email_button_role($link, $label, 'student');
}

function edu_email_button_role(string $link, string $label, string $role): string
{
    $safeLink = e($link);
    if ($role === 'admin') $bg = '#0F172A';
    elseif ($role === 'teacher') $bg = '#d97706';
    elseif ($role === 'registrar') $bg = '#4f46e5';
    elseif ($role === 'accounting') $bg = '#0d9488';
    else $bg = '#2563eb';
    return '<p style="margin:22px 0;text-align:center;">'
        . '<a href="' . $safeLink . '" style="display:inline-block;background:'.$bg.';color:#ffffff;'
        . 'text-decoration:none;padding:12px 26px;border-radius:6px;font-weight:700;letter-spacing:.02em;">' . e($label) . '</a></p>'
        . '<p style="font-size:12px;color:#64748b;word-break:break-all;">If the button does not work, copy this link:<br>'
        . $safeLink . '</p>';
}

function edu_get_user_role(array $user): string
{
    if (!empty($user['role_name'])) return strtolower((string)$user['role_name']);
    if (!empty($user['role'])) return strtolower((string)$user['role']);
    if (isset($user['role_id'])) {
        return match((int)$user['role_id']){1=>'admin',2=>'teacher',3=>'student',4=>'parent',5=>'registrar',6=>'accounting', default=>'student'};
    }
    if (!empty($user['id'])) {
        try { $r = \App\Core\Database::one("SELECT r.name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? LIMIT 1", [(int)$user['id']]); if($r) return strtolower($r['name']); } catch(Throwable $e){}
    }
    if (!empty($user['email'])) {
        try { $r = \App\Core\Database::one("SELECT r.name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.email=? LIMIT 1", [mb_strtolower($user['email'])]); if($r) return strtolower($r['name']); } catch(Throwable $e){}
    }
    return 'student';
}

/* ------------------------------------------------------------------ */
/* Domain-specific emails                                              */
/* ------------------------------------------------------------------ */

/** Verification link email — separate UI for admin / teacher / student */
function send_verification_link_email(array $user, string $token): bool
{
    $link = url('verify-email?token=' . urlencode($token));
    $name = $user['first_name'] ?? 'there';
    $role = edu_get_user_role($user);
    $isAdmin = $role === 'admin';
    $isTeacher = $role === 'teacher';
    $isRegistrar = $role === 'registrar';
    $isAccounting = $role === 'accounting';

    if ($isAdmin) {
        $inner = '<p style="color:#334155;">Hi <strong>' . e($name) . '</strong>,</p>'
            . '<p style="color:#0F172A;background:#FEF3C7;border:1px solid #FDE68A;padding:12px;border-radius:8px;"><strong>👑 Administrator Account</strong> — you are registered as <strong>Administrator</strong> for <strong>Datamex College of Saint Adeline</strong>. Verify your email to unlock full control of EduPortal (enrollments, payments, users, analytics).</p>'
            . '<p style="color:#334155;">This is a privileged account — please verify within 24 hours to activate.</p>'
            . edu_email_button_role($link, 'Verify Admin Email', 'admin')
            . '<div style="background:#FFFBEB;border:1px solid #FDE68A;padding:12px;border-radius:8px;color:#92400E;font-size:13px;"><strong>What’s next?</strong> Verify → Sign in → Admin Dashboard → Manage enrollments & PayRex, invite teachers, view live analytics.</div>'
            . '<p style="color:#64748b;font-size:13px;">Link expires in 24 hours. If you did not expect this admin invite, contact IT immediately.</p>';
        $subject = 'Administrator — verify your EduPortal account';
        $title = 'Confirm your admin email 👑';
        $template = 'verify_account_admin';
    } elseif ($isTeacher) {
        $inner = '<p style="color:#334155;">Hi <strong>' . e($name) . '</strong>,</p>'
            . '<p style="color:#78350f;background:#fffbeb;border:1px solid #fde68a;padding:12px;border-radius:8px;"><strong>🛡️ Teacher Invitation</strong> — you were invited as teaching staff for <strong>Datamex College of Saint Adeline</strong>. Verify your work email to unlock the teacher portal (invite is single-use, 7-day expiry, audit-logged).</p>'
            . '<p style="color:#334155;">Your invite code has been consumed. Please confirm your email to activate your teacher account.</p>'
            . edu_email_button_role($link, 'Verify Teacher Email', 'teacher')
            . '<p style="color:#92400e;font-size:12px;background:#fef3c7;padding:10px;border-radius:6px;"><strong>Next:</strong> After verification you can sign in and access My Subjects, grading, and permit verification.</p>'
            . '<p style="color:#64748b;font-size:13px;">Link expires in 24 hours. If your invite was restricted to a specific email, use that exact email.</p>';
        $subject = 'Teacher invitation — verify your EduPortal account';
        $title = 'Confirm your teacher email 🛡️';
        $template = 'verify_account_teacher';
    } elseif ($isRegistrar) {
        $inner = '<p style="color:#334155;">Hi <strong>' . e($name) . '</strong>,</p>'
            . '<p style="color:#312e81;background:#eef2ff;border:1px solid #c7d2fe;padding:12px;border-radius:8px;"><strong>📋 Registrar Account</strong> — you are registered as <strong>Registrar</strong> for <strong>Datamex College of Saint Adeline</strong>. Verify your work email to unlock academic records, grade verification, permits, and academic documents.</p>'
            . edu_email_button_role($link, 'Verify Registrar Email', 'registrar')
            . '<div style="background:#eef2ff;border:1px solid #c7d2fe;padding:12px;border-radius:8px;color:#312e81;font-size:13px;"><strong>What’s next?</strong> Verify → Sign in → Registrar Dashboard → Review grades, permits, and student records.</div>'
            . '<p style="color:#64748b;font-size:13px;">Link expires in 24 hours. Office account — no enrollment steps required.</p>';
        $subject = 'Registrar — verify your EduPortal account';
        $title = 'Confirm your registrar email 📋';
        $template = 'verify_account_registrar';
    } elseif ($isAccounting) {
        $inner = '<p style="color:#334155;">Hi <strong>' . e($name) . '</strong>,</p>'
            . '<p style="color:#0f766e;background:#f0fdfa;border:1px solid #99f6e4;padding:12px;border-radius:8px;"><strong>🧾 Accounting Account</strong> — you are registered as <strong>Accounting</strong> for <strong>Datamex College of Saint Adeline</strong>. Verify your work email to unlock enrollment finance, payments, balances, and financial clearance.</p>'
            . edu_email_button_role($link, 'Verify Accounting Email', 'accounting')
            . '<div style="background:#f0fdfa;border:1px solid #99f6e4;padding:12px;border-radius:8px;color:#0f766e;font-size:13px;"><strong>What’s next?</strong> Verify → Sign in → Accounting Dashboard → Verify enrollments, confirm payments, clearances.</div>'
            . '<p style="color:#64748b;font-size:13px;">Link expires in 24 hours. Office account — no enrollment steps required.</p>';
        $subject = 'Accounting — verify your EduPortal account';
        $title = 'Confirm your accounting email 🧾';
        $template = 'verify_account_accounting';
    } else {
        $inner = '<p style="color:#334155;">Hi <strong>' . e($name) . '</strong>,</p>'
            . '<p style="color:#334155;">Welcome to <strong>EduPortal</strong>! 🎓 Confirm your email to start your enrollment journey at <strong>Datamex College of Saint Adeline</strong>.</p>'
            . '<div style="background:#f0f9ff;border:1px solid #bae6fd;padding:12px;border-radius:8px;color:#0c4a6e;font-size:13px;"><strong>What’s next?</strong> Verify → Complete Academic Info + documents → Accounting & Registrar → PayRex payment → Fully Enrolled → My Subjects unlocks!</div>'
            . edu_email_button_role($link, 'Verify My Email', 'student')
            . '<p style="color:#64748b;font-size:13px;">This link expires in 24 hours. Didn’t create an account? Ignore this email.</p>';
        $subject = 'Verify your EduPortal account';
        $title = 'Confirm your email 🎓';
        $template = 'verify_account_student';
    }

    return edu_mailer()->send(
        (string) $user['email'],
        trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
        $subject,
        edu_email_shell_role($title, $inner, $isAdmin ? 'admin' : ($isTeacher ? 'teacher' : ($isRegistrar ? 'registrar' : ($isAccounting ? 'accounting' : 'student')))),
        $template
    );
}

/** OTP email — separate UI for admin / teacher / registrar / accounting / student */
function send_otp_email(array $user, string $code): bool
{
    $name = $user['first_name'] ?? 'there';
    $role = edu_get_user_role($user);
    $isAdmin = $role === 'admin';
    $isTeacher = $role === 'teacher';
    $isRegistrar = $role === 'registrar';
    $isAccounting = $role === 'accounting';

    if ($isAdmin) {
        $inner = '<p style="color:#334155;">Hi <strong>' . e($name) . '</strong> (Admin),</p>'
            . '<p style="color:#0F172A;">Use this one-time password to verify your <strong>administrator</strong> account:</p>'
            . '<div style="font-size:30px;letter-spacing:8px;font-weight:800;color:#0F172A;background:#FFFBEB;border:1px solid #FDE68A;padding:14px;text-align:center;border-radius:8px;">' . e($code) . '</div>'
            . '<p style="color:#92400E;font-size:12px;margin-top:12px;background:#FEF3C7;padding:8px;border-radius:6px;">Admin OTP — expires in 10 minutes. Never share it. Single-use.</p>';
        $subject = 'Your admin verification code (EduPortal)';
        $title = 'Admin OTP 👑';
        $template = 'otp_admin';
    } elseif ($isTeacher) {
        $inner = '<p style="color:#334155;">Hi <strong>' . e($name) . '</strong> (Teacher),</p>'
            . '<p style="color:#78350f;">Use this one-time password to verify your <strong>teacher</strong> account:</p>'
            . '<div style="font-size:30px;letter-spacing:8px;font-weight:800;color:#7c2d12;background:#fffbeb;border:1px solid #fde68a;padding:14px;text-align:center;border-radius:8px;">' . e($code) . '</div>'
            . '<p style="color:#92400e;font-size:12px;margin-top:12px;background:#fef3c7;padding:8px;border-radius:6px;">Teacher OTP — expires in 10 minutes. Never share it.</p>';
        $subject = 'Your teacher verification code (EduPortal)';
        $title = 'Teacher OTP 🛡️';
        $template = 'otp_teacher';
    } elseif ($isRegistrar) {
        $inner = '<p style="color:#334155;">Hi <strong>' . e($name) . '</strong> (Registrar),</p>'
            . '<p style="color:#312e81;">Use this one-time password to verify your <strong>registrar</strong> account:</p>'
            . '<div style="font-size:30px;letter-spacing:8px;font-weight:800;color:#312e81;background:#eef2ff;border:1px solid #c7d2fe;padding:14px;text-align:center;border-radius:8px;">' . e($code) . '</div>'
            . '<p style="color:#4338ca;font-size:12px;margin-top:12px;background:#eef2ff;padding:8px;border-radius:6px;">Registrar OTP — expires in 10 minutes. Never share it.</p>';
        $subject = 'Your registrar verification code (EduPortal)';
        $title = 'Registrar OTP 📋';
        $template = 'otp_registrar';
    } elseif ($isAccounting) {
        $inner = '<p style="color:#334155;">Hi <strong>' . e($name) . '</strong> (Accounting),</p>'
            . '<p style="color:#0f766e;">Use this one-time password to verify your <strong>accounting</strong> account:</p>'
            . '<div style="font-size:30px;letter-spacing:8px;font-weight:800;color:#0f766e;background:#f0fdfa;border:1px solid #99f6e4;padding:14px;text-align:center;border-radius:8px;">' . e($code) . '</div>'
            . '<p style="color:#0f766e;font-size:12px;margin-top:12px;background:#f0fdfa;padding:8px;border-radius:6px;">Accounting OTP — expires in 10 minutes. Never share it.</p>';
        $subject = 'Your accounting verification code (EduPortal)';
        $title = 'Accounting OTP 🧾';
        $template = 'otp_accounting';
    } else {
        $inner = '<p style="color:#334155;">Hi <strong>' . e($name) . '</strong>,</p>'
            . '<p style="color:#334155;">Use this one-time password to verify your student account:</p>'
            . '<div style="font-size:30px;letter-spacing:8px;font-weight:700;color:#1e3a5f;background:#eef2ff;padding:14px;text-align:center;border-radius:8px;">' . e($code) . '</div>'
            . '<p style="color:#64748b;font-size:13px;margin-top:14px;">Expires in 10 minutes. Never share this code.</p>';
        $subject = 'Your EduPortal verification code';
        $title = 'One-Time Password 🎓';
        $template = 'otp_student';
    }

    return edu_mailer()->send(
        (string) $user['email'],
        trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
        $subject,
        edu_email_shell_role($title, $inner, $isAdmin ? 'admin' : ($isTeacher ? 'teacher' : ($isRegistrar ? 'registrar' : ($isAccounting ? 'accounting' : 'student')))),
        $template
    );
}

/** Password reset link email (Module 1). $token is plain token from Auth::issuePasswordReset(). */
function send_password_reset_email(array $user, string $token): bool
{
    $link = url('reset-password?token=' . urlencode($token));
    $name = $user['first_name'] ?? 'there';
    $inner = '<p style="color:#334155;">Hi <strong>' . e($name) . '</strong>,</p>'
        . '<p style="color:#334155;">We received a request to reset your EduPortal password.</p>'
        . edu_email_button($link, 'Reset Password')
        . '<p style="color:#64748b;font-size:13px;">This link expires in 1 hour. '
        . 'If you did not request a reset, you can safely ignore this email.</p>';

    return edu_mailer()->send(
        (string) $user['email'],
        trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
        'Reset your EduPortal password',
        edu_email_shell('Password reset request', $inner),
        'reset_password'
    );
}

function send_enrollment_payment_email(array $user, string $applicationNo, float $amount, int $applicationId): bool
{
    $link = url('enroll/'.$applicationId.'/pay');
    $inner = '<p style="color:#334155;">Hello <strong>' . e($user['first_name'] ?? 'there') . '</strong>,</p>'
        . '<p style="color:#334155;">Great news — your enrollment <strong>' . e($applicationNo) . '</strong> was <strong>approved by Accounting & Registrar</strong>.</p>'
        . '<p style="color:#334155;">To be <strong>fully enrolled</strong>, please settle the enrollment fee of <strong>' . e(money_plain($amount)) . '</strong> via PayRex (GCash / Maya / Card / QRPh).</p>'
        . edu_email_button($link, 'Pay Now via PayRex — '.money_plain($amount))
        . '<p style="color:#64748b;font-size:13px;">Secure PayRex checkout. After payment, your status becomes <strong>Enrolled</strong> automatically. Link: ' . e($link) . '</p>';
    return edu_mailer()->send((string)$user['email'], trim(($user['first_name']??'').' '.($user['last_name']??'')), 'Pay to complete enrollment - '.$applicationNo, edu_email_shell('Complete your enrollment', $inner), 'enrollment_payment');
}

/** Enrollment application status update (Module 2). */
function send_enrollment_status_email(array $user, string $applicationNo, string $statusLabel, ?string $remarks): bool
{
    $remarksHtml = $remarks
        ? '<p style="color:#334155;"><strong>Registrar notes:</strong><br>' . nl2br(e($remarks)) . '</p>'
        : '';
    $inner = '<p style="color:#334155;">Hello,</p>'
        . '<p style="color:#334155;">Your enrollment application '
        . '<strong>' . e($applicationNo) . '</strong> is now marked as '
        . '<strong>' . e($statusLabel) . '</strong>.</p>'
        . $remarksHtml
        . '<p style="color:#64748b;font-size:13px;">Log in to the portal for details and next steps.</p>';

    return edu_mailer()->send(
        (string) $user['email'],
        trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
        'Enrollment update - ' . $applicationNo,
        edu_email_shell('Enrollment status update', $inner),
        'enrollment_status'
    );
}

/** Electronic payment receipt (Module 6). $p: receipt_no, transaction_ref, amount, method_label, paid_at, student_name (+optional payment_type, remaining_balance) */
function send_payment_receipt_email(array $user, array $p): bool
{
    $rows = '';
    $fields = [
        'Receipt No.'     => $p['receipt_no'] ?? '',
        'Transaction Ref' => $p['transaction_ref'] ?? '',
        'Student'         => $p['student_name'] ?? '',
        'Payment method'  => $p['method_label'] ?? 'Online',
        'Date paid'       => $p['paid_at'] ?? '',
    ];
    if (!empty($p['payment_type'])) {
        $fields['Payment type'] = $p['payment_type'];
    }
    foreach ($fields as $k => $v) {
        $rows .= '<tr>'
            . '<td style="padding:8px 12px;color:#64748b;border-bottom:1px solid #eef1f6;">' . e((string) $k) . '</td>'
            . '<td style="padding:8px 12px;color:#1e293b;border-bottom:1px solid #eef1f6;">' . e((string) $v) . '</td>'
            . '</tr>';
    }
    $amountRow = '<tr>'
        . '<td style="padding:10px 12px;font-weight:700;color:#1e293b;">Amount paid</td>'
        . '<td style="padding:10px 12px;font-weight:700;color:#166534;">' . money_plain($p['amount'] ?? 0) . '</td>'
        . '</tr>';
    if (isset($p['remaining_balance']) && $p['remaining_balance'] !== null) {
        $amountRow .= '<tr>'
            . '<td style="padding:10px 12px;font-weight:700;color:#1e293b;">Remaining balance</td>'
            . '<td style="padding:10px 12px;font-weight:700;color:#b91c1c;">' . money_plain($p['remaining_balance']) . '</td>'
            . '</tr>';
    }

    $inner = '<p style="color:#334155;">We have confirmed your payment. Thank you!</p>'
        . '<table style="width:100%;border-collapse:collapse;font-size:14px;background:#f8fafc;border-radius:8px;">'
        . $rows . $amountRow . '</table>'
        . '<p style="color:#64748b;font-size:13px;margin-top:16px;">A copy of this receipt is always available '
        . 'under Payments &rarr; History in your portal account.</p>';

    return edu_mailer()->send(
        (string) $user['email'],
        trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
        'Payment receipt - ' . ($p['receipt_no'] ?? ''),
        edu_email_shell('Payment confirmation', $inner),
        'payment_receipt'
    );
}

/* ------------------------------------------------------------------ */
/* MFA + device-verification security emails                           */
/* ------------------------------------------------------------------ */

/** New-device login attempt: single-use 15-minute approval link. */
function send_new_device_email(array $user, string $plainToken, int $pendingId): bool
{
    $link = url('verify-device?token=' . urlencode($plainToken));
    $name = $user['first_name'] ?? 'there';
    $role = edu_get_user_role($user);
    $when = date('F j, Y g:i A');
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown browser');
    try {
        $device = \App\Core\Mfa::deviceName($ua);
    } catch (Throwable $e) {
        $device = 'Unknown device';
    }
    $ip = client_ip();
    $inner = '<p style="color:#334155;">Hi <strong>' . e($name) . '</strong>,</p>'
        . '<p style="color:#334155;">We detected a sign-in attempt from a <strong>new device</strong> on your EduPortal account. '
        . 'If this was you, approve the device to continue signing in.</p>'
        . '<table style="width:100%;border-collapse:collapse;font-size:14px;background:#f8fafc;border-radius:8px;margin:14px 0;">'
        . '<tr><td style="padding:8px 12px;color:#64748b;border-bottom:1px solid #eef1f6;">Date / time</td><td style="padding:8px 12px;color:#1e293b;border-bottom:1px solid #eef1f6;">' . e($when) . ' (Asia/Manila)</td></tr>'
        . '<tr><td style="padding:8px 12px;color:#64748b;border-bottom:1px solid #eef1f6;">Device</td><td style="padding:8px 12px;color:#1e293b;border-bottom:1px solid #eef1f6;">' . e($device) . '</td></tr>'
        . '<tr><td style="padding:8px 12px;color:#64748b;">IP address</td><td style="padding:8px 12px;color:#1e293b;">' . e($ip) . '</td></tr>'
        . '</table>'
        . edu_email_button_role($link, 'Approve This Device', $role)
        . '<p style="color:#64748b;font-size:13px;">This link expires in 15 minutes and works only once. '
        . 'If you did not try to sign in, ignore this email — no access was granted — and consider changing your password.</p>';

    return edu_mailer()->send(
        (string) $user['email'],
        trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
        'New device sign-in — approval needed (EduPortal)',
        edu_email_shell_role('Approve new device login', $inner, $role),
        'security_new_device'
    );
}

/** MFA enabled / disabled notification. */
function send_mfa_status_email(array $user, bool $enabled): bool
{
    $name = $user['first_name'] ?? 'there';
    $role = edu_get_user_role($user);
    $inner = $enabled
        ? '<p style="color:#334155;">Hi <strong>' . e($name) . '</strong>,</p>'
          . '<p style="color:#334155;">Multi-factor authentication is now <strong style="color:#166534;">ENABLED</strong> on your EduPortal account. '
          . 'Every sign-in will now ask for a code from your authenticator app.</p>'
          . '<p style="color:#64748b;font-size:13px;">Store your recovery codes somewhere safe. If you did not enable this, contact the administrator immediately.</p>'
        : '<p style="color:#334155;">Hi <strong>' . e($name) . '</strong>,</p>'
          . '<p style="color:#334155;">Multi-factor authentication was <strong style="color:#b91c1c;">DISABLED</strong> on your EduPortal account.</p>'
          . '<p style="color:#64748b;font-size:13px;">If you did not do this, change your password now and contact the administrator immediately.</p>';

    return edu_mailer()->send(
        (string) $user['email'],
        trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
        $enabled ? 'MFA enabled on your EduPortal account' : 'MFA disabled on your EduPortal account',
        edu_email_shell_role($enabled ? 'MFA enabled' : 'MFA disabled', $inner, $role),
        $enabled ? 'security_mfa_enabled' : 'security_mfa_disabled'
    );
}

/** Recovery code used notification (with remaining count). */
function send_recovery_used_email(array $user, int $remaining): bool
{
    $name = $user['first_name'] ?? 'there';
    $role = edu_get_user_role($user);
    $warn = $remaining <= 2
        ? '<p style="color:#92400e;background:#fef3c7;padding:10px;border-radius:6px;font-size:13px;"><strong>Low codes:</strong> only ' . $remaining . ' recovery code(s) left. Generate a new set from Security Settings soon.</p>'
        : '<p style="color:#64748b;font-size:13px;">' . $remaining . ' recovery code(s) remaining.</p>';
    $inner = '<p style="color:#334155;">Hi <strong>' . e($name) . '</strong>,</p>'
        . '<p style="color:#334155;">One of your MFA <strong>recovery codes</strong> was just used to sign in (' . e(date('F j, Y g:i A')) . ').</p>'
        . $warn
        . '<p style="color:#64748b;font-size:13px;">If this was not you, change your password immediately and contact the administrator.</p>';

    return edu_mailer()->send(
        (string) $user['email'],
        trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
        'A recovery code was used (EduPortal)',
        edu_email_shell_role('Recovery code used', $inner, $role),
        'security_recovery_used'
    );
}

/** Password changed notification. */
function send_password_changed_email(array $user): bool
{
    $name = $user['first_name'] ?? 'there';
    $role = edu_get_user_role($user);
    $inner = '<p style="color:#334155;">Hi <strong>' . e($name) . '</strong>,</p>'
        . '<p style="color:#334155;">Your EduPortal password was just changed (' . e(date('F j, Y g:i A')) . '). '
        . 'All other signed-in devices were logged out for safety.</p>'
        . '<p style="color:#64748b;font-size:13px;">If this was not you, use Forgot Password now and contact the administrator.</p>';

    return edu_mailer()->send(
        (string) $user['email'],
        trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
        'Your password was changed (EduPortal)',
        edu_email_shell_role('Password changed', $inner, $role),
        'security_password_changed'
    );
}

/** Trusted device added / revoked notification. */
function send_trusted_device_email(array $user, string $deviceName, bool $revoked): bool
{
    $name = $user['first_name'] ?? 'there';
    $role = edu_get_user_role($user);
    $inner = $revoked
        ? '<p style="color:#334155;">Hi <strong>' . e($name) . '</strong>,</p>'
          . '<p style="color:#334155;">The trusted device <strong>' . e($deviceName) . '</strong> was <strong>revoked</strong> — it will need email approval on next sign-in.</p>'
        : '<p style="color:#334155;">Hi <strong>' . e($name) . '</strong>,</p>'
          . '<p style="color:#334155;">A new trusted device was added to your account: <strong>' . e($deviceName) . '</strong> (' . e(date('F j, Y g:i A')) . ').</p>'
          . '<p style="color:#64748b;font-size:13px;">If this was not you, revoke it from Security Settings and change your password.</p>';

    return edu_mailer()->send(
        (string) $user['email'],
        trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
        $revoked ? 'A trusted device was revoked (EduPortal)' : 'New trusted device (EduPortal)',
        edu_email_shell_role($revoked ? 'Device revoked' : 'Device trusted', $inner, $role),
        $revoked ? 'security_device_revoked' : 'security_device_added'
    );
}

/** "Remember this device" 30-day MFA skip granted / removed. */
function send_mfa_exempt_email(array $user, bool $granted): bool
{
    $name = $user['first_name'] ?? 'there';
    $role = edu_get_user_role($user);
    $inner = $granted
        ? '<p style="color:#334155;">Hi <strong>' . e($name) . '</strong>,</p>'
          . '<p style="color:#334155;">This trusted device will <strong>skip the authenticator code for 30 days</strong>. Your password is still required at every sign-in, and any new device still faces email + MFA.</p>'
          . '<p style="color:#64748b;font-size:13px;">If this was not you, remove it in Security Settings and change your password immediately.</p>'
        : '<p style="color:#334155;">Hi <strong>' . e($name) . '</strong>,</p>'
          . '<p style="color:#334155;">The 30-day MFA skip was <strong>removed</strong> — the authenticator code is required again on every sign-in.</p>';

    return edu_mailer()->send(
        (string) $user['email'],
        trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
        $granted ? 'This device skips MFA for 30 days (EduPortal)' : 'MFA skip removed (EduPortal)',
        edu_email_shell_role($granted ? 'Device remembered' : 'MFA skip removed', $inner, $role),
        $granted ? 'security_mfa_exempt' : 'security_mfa_exempt_off'
    );
}

/** Repeated-failed-login warning. */
function send_lockout_warning_email(array $user, string $reason): bool
{
    $name = $user['first_name'] ?? 'there';
    $role = edu_get_user_role($user);
    $inner = '<p style="color:#334155;">Hi <strong>' . e($name) . '</strong>,</p>'
        . '<p style="color:#334155;">We noticed ' . e($reason) . ' on your EduPortal account (' . e(date('F j, Y g:i A')) . '). '
        . 'Access is temporarily limited for your protection.</p>'
        . '<p style="color:#64748b;font-size:13px;">If this was not you, change your password and review Security Settings.</p>';

    return edu_mailer()->send(
        (string) $user['email'],
        trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
        'Security alert: repeated failed attempts (EduPortal)',
        edu_email_shell_role('Security alert', $inner, $role),
        'security_lockout_warning'
    );
}
