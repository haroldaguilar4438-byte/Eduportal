<?php
declare(strict_types=1);

/**
 * EduPortal - ONE-TIME installer: creates the first ADMIN account.
 * Datamex College of Saint Adeline
 *
 * USAGE
 *   CLI (preferred):  php setup-admin.php "Juan Dela Cruz" admin@datamex.edu.ph "Str0ngPass!23" [--force]
 *   Browser:          https://YOUR-DOMAIN/install/setup-admin.php  (renders a form)
 *
 * The account is created with is_email_verified = 0 and status = pending_verification.
 * A verification link is emailed - admin must verify to prove real email ownership.
 *
 * !! DELETE THIS FILE (or hit the self-destruct button) IMMEDIATELY AFTER USE !!
 */

$__root = dirname(__DIR__);
if (!is_dir($__root . '/app')) $__root = dirname(__DIR__, 2);
define('APP_ROOT', $__root . '/app');
require APP_ROOT . '/Core/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;

const INSTALLER_VERSION = '1.0';

/* --------------------------- self-destruct hook ---------------------------- */
if (PHP_SAPI !== 'cli' && isset($_GET['selfdestruct'])) {
    @unlink(__FILE__);
    @rmdir(__DIR__);
    exit('<meta charset="utf-8"><p style="font-family:sans-serif">Installer deleted. '
        . '<a href="../">Go to portal</a></p>');
}

/* ------------------------------ guard: one run ----------------------------- */
$adminCount = 0;
$adminEmailExisting = '';
try {
    $adminCount = (int) Database::value(
        "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'admin'"
    );
    if ($adminCount > 0) {
        $row = Database::one("SELECT u.email FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='admin' ORDER BY u.id ASC LIMIT 1");
        $adminEmailExisting = $row['email'] ?? '';
    }
} catch (\Throwable $e) {
    exit('<meta charset="utf-8"><p style="font-family:sans-serif;color:#b91c1c">Database error: '
        . 'import database/schema.sql first.</p>');
}

$force = PHP_SAPI === 'cli' && in_array('--force', $argv, true);
if ($adminCount > 0 && !$force) {
    // show lively already-installed screen instead of plain exit
    $alreadyHtml = true;
} else {
    $alreadyHtml = false;
}

/* ------------------------------ collect input ------------------------------- */
$name = $email = $password = $error = '';
$successEmail = '';

if (!$alreadyHtml) {
if (PHP_SAPI === 'cli') {
    $opts = array_values(array_filter($argv, static fn($a) => $a !== '--force'));
    if (count($opts) < 4) {
        exit("Usage: php setup-admin.php \"Full Name\" email@domain.com \"Password\" [--force]\n");
    }
    [, $name, $email, $password] = $opts;
    try {
        if ($name === '' || mb_strlen($name) > 120) throw new InvalidArgumentException('Please enter the administrator full name.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Please enter a valid email address.');
        $weak = Auth::assertPasswordStrength($password);
        if ($weak !== []) throw new InvalidArgumentException('Password needs: ' . implode(', ', $weak) . '.');
        $uid = create_admin($name, $email, $password);
        echo "Admin #$uid created: $email\nVerification email sent - admin must click link (or use OTP at /verify-pending) to activate.\n";
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) echo "WARNING: fake domain may not receive email - check email_log and logs/app-*.log\n";
        exit(0);
    } catch (Throwable $e) { fwrite(STDERR, "Error: ".$e->getMessage()."\n"); exit(1); }
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    \App\Core\Csrf::guard();
    $name     = trim((string) ($_POST['name'] ?? ''));
    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    try {
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Please enter the administrator full name.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Please enter a valid email address.');
        }
        $weak = Auth::assertPasswordStrength($password);
        if ($weak !== []) {
            throw new InvalidArgumentException('Password needs: ' . implode(', ', $weak) . '.');
        }
        create_admin($name, $email, $password);
        installer_success_screen($email);
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}
}

/* -------------------------------- helpers ---------------------------------- */

function create_admin(string $name, string $email, string $password): int
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = array_shift($parts) ?: $name;
    $last  = implode(' ', $parts);
    $emailNorm = strtolower(trim($email));

    $uid = Database::insert('users', [
        'role_id'           => 1,
        'email'             => $emailNorm,
        'password_hash'     => password_hash($password, PASSWORD_DEFAULT),
        'first_name'        => $first,
        'last_name'         => $last !== '' ? $last : $first,
        'is_email_verified' => 0,
        'verified_at'       => null,
        'status'            => 'pending_verification',
    ]);

    try {
        $token = Auth::issueVerification($uid);
        $user = \App\Models\User::find($uid) ?? ['email'=>$emailNorm,'first_name'=>$first,'last_name'=>$last];
        send_verification_link_email($user, $token);
        try { $code = Auth::issueOtp($uid); send_otp_email($user, $code); } catch (Throwable $e) {}
        Database::insert('audit_logs', ['actor_user_id'=>$uid,'action'=>'admin.created_pending_verify','entity_type'=>'users','entity_id'=>$uid,'ip_address'=>client_ip()]);
    } catch (Throwable $e) { storage_log('admin verify email failed '.$uid.': '.$e->getMessage()); }

    return $uid;
}

function installer_success_screen(string $email): never
{
    $verifyUrl = htmlspecialchars(url('/verify-pending'), ENT_QUOTES);
    $loginUrl = htmlspecialchars(url('/login'), ENT_QUOTES);
    $mailSafe = htmlspecialchars($email, ENT_QUOTES);
    exit('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin Created - EduPortal</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"><style>@import url("https://fonts.googleapis.com/css2?family=Inter:wght@600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap");body{font-family:Inter,sans-serif;background:#020617;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;overflow:hidden;position:relative}#bg{position:fixed;inset:0;background:radial-gradient(600px 400px at 20% 10%,rgba(59,130,246,.25),transparent 60%),radial-gradient(800px 500px at 90% 90%,rgba(16,185,129,.18),transparent 60%),linear-gradient(135deg,#020617 0%,#0F172A 55%,#1E1B4B 100%)}.card{position:relative;background:rgba(255,255,255,.98);backdrop-filter:blur(16px);border-radius:20px;box-shadow:0 20px 60px rgba(0,0,0,.35);max-width:560px;width:100%;padding:32px 28px;animation:pop .6s cubic-bezier(.34,1.56,.64,1)}@keyframes pop{0%{transform:scale(.88) translateY(20px);opacity:0}100%{transform:scale(1) translateY(0);opacity:1}}.icon{width:64px;height:64px;border-radius:16px;background:linear-gradient(135deg,#059669,#10B981);display:flex;align-items:center;justify-content:center;color:#fff;font-size:28px;box-shadow:0 8px 24px rgba(16,185,129,.4);animation:float 3s ease-in-out infinite}@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}.step{display:flex;gap:12px;align-items:center;padding:12px;border:1px solid #E2E8F0;border-radius:12px;background:#F8FAFC}.check{width:28px;height:28px;border-radius:50%;background:#DCFCE7;color:#15803D;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0}</style></head><body><div id="bg"></div><div class="card text-center"><div class="icon mx-auto mb-3"><i class="bi bi-check-lg"></i></div><h2 style="font-family:Plus Jakarta Sans,sans-serif;font-weight:800;color:#0F172A">Admin created — verify email</h2><p class="text-muted">We sent a verification link + 6-digit OTP to<br><strong style="color:#1E3A8A">'.$mailSafe.'</strong></p><div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:12px;padding:14px;text-align:left;font-size:13px;color:#1E40AF"><i class="bi bi-info-circle"></i> Fake emails will <b>not</b> receive the code — account stays <code>pending_verification</code>. Check inbox & spam, then click link or enter OTP at <code>/verify-pending</code>.</div><div class="d-flex gap-2 mt-3"><a href="'.$verifyUrl.'" class="btn btn-primary flex-fill" style="background:#2563EB;border:0;border-radius:10px;padding:12px;font-weight:700"><i class="bi bi-shield-check"></i> Go to Verify</a><a href="'.$loginUrl.'" class="btn btn-outline-secondary" style="border-radius:10px;padding:12px 18px">Sign in</a></div><div class="mt-4 p-3" style="background:#FEF2F2;border:1px solid #FECACA;border-radius:12px"><div style="color:#DC2626;font-weight:800;font-size:13px"><i class="bi bi-exclamation-triangle"></i> CRITICAL: delete installer now</div><p class="small text-muted mb-2">Anyone with this URL can create admins until deleted.</p><a href="?selfdestruct=1" onclick="return confirm(\'Delete setup-admin.php permanently? This cannot be undone.\')" class="btn btn-danger w-100" style="border-radius:10px;font-weight:700"><i class="bi bi-trash3"></i> Self-destruct installer</a></div></div></body></html>');
}

/* --------------------------------- UI -------------------------------------- */
if ($alreadyHtml) {
    // lively already-installed screen
    $existingSafe = htmlspecialchars($adminEmailExisting, ENT_QUOTES);
    exit('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Already Installed - EduPortal</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"><style>body{font-family:Inter,sans-serif;background:#020617;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}#bg{position:fixed;inset:0;background:radial-gradient(600px 400px at 50% 0%,rgba(251,191,36,.15),transparent 60%),linear-gradient(135deg,#020617,#1E293B)} .card{position:relative;background:#fff;border-radius:20px;padding:32px;max-width:520px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.35);animation:pop .5s ease}@keyframes pop{0%{transform:scale(.9);opacity:0}100%{transform:scale(1);opacity:1}}</style></head><body><div id="bg" style="position:fixed;inset:0"></div><div class="card text-center" style="position:relative"><div class="mx-auto mb-3 d-flex align-items-center justify-content:center" style="width:64px;height:64px;border-radius:16px;background:linear-gradient(135deg,#F59E0B,#FBBF24);color:#fff;font-size:28px"><i class="bi bi-shield-lock"></i></div><h2 style="font-weight:800;color:#0F172A">Already installed</h2><p class="text-muted">An administrator <strong>'.$existingSafe.'</strong> already exists. The installer is locked to prevent duplicate admins.</p><div class="d-flex gap-2 mt-3"><a href="'.htmlspecialchars(url('/login'),ENT_QUOTES).'" class="btn btn-primary flex-fill" style="background:#1E3A8A;border:0;border-radius:10px;padding:12px;font-weight:700">Go to Login</a><a href="'.htmlspecialchars(url('/'),ENT_QUOTES).'" class="btn btn-outline-secondary" style="border-radius:10px;padding:12px 18px">Home</a></div><div class="mt-3 p-3" style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:12px;text-align:left;font-size:13px"><i class="bi bi-lightbulb" style="color:#D97706"></i> Lost access? Reset password in <code>phpMyAdmin > users</code> or delete this admin and re-run with <code>?force</code> via CLI: <code>php setup-admin.php "Name" email "Pass" --force</code></div><div class="mt-3"><a href="?selfdestruct=1" onclick="return confirm(\'Delete installer?\')" class="btn btn-outline-danger btn-sm w-100" style="border-radius:10px"><i class="bi bi-trash"></i> Delete installer anyway</a></div></div></body></html>');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>EduPortal — Create First Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
<style>
*{font-family:Inter,sans-serif}
body{margin:0;min-height:100vh;background:#020617;display:flex;align-items:center;justify-content:center;padding:20px;overflow-x:hidden}
#bg{position:fixed;inset:0;z-index:-1;background:radial-gradient(700px 500px at 15% 10%,rgba(59,130,246,.28),transparent 60%),radial-gradient(800px 600px at 85% 90%,rgba(16,185,129,.20),transparent 60%),radial-gradient(500px 400px at 50% 50%,rgba(139,92,246,.12),transparent 70%),linear-gradient(135deg,#020617 0%,#0F172A 45%,#1E1B4B 100%)}
#bg canvas{width:100%;height:100%;display:block;opacity:.9}
.wrap{width:100%;max-width:520px;position:relative}
.brand{text-align:center;margin-bottom:18px;animation:fadeIn .6s ease}
.brand .logo{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#1E3A8A,#3B82F6);display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:22px;box-shadow:0 8px 24px rgba(59,130,246,.4);animation:float 3s ease-in-out infinite}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}
@keyframes fadeIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.card{background:rgba(255,255,255,.98);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,.6);border-radius:20px;box-shadow:0 20px 60px rgba(0,0,0,.35),0 0 0 1px rgba(255,255,255,.1) inset;padding:28px 26px;animation:pop .6s cubic-bezier(.34,1.56,.64,1)}
@keyframes pop{0%{transform:scale(.92) translateY(16px);opacity:0}100%{transform:scale(1) translateY(0);opacity:1}}
h1{font-family:Plus Jakarta Sans,sans-serif;font-weight:800;font-size:23px;color:#0F172A;margin:0}
.sub{color:#64748B;font-size:13px;line-height:1.5;margin:6px 0 0}
.badge-live{display:inline-flex;align-items:center;gap:6px;background:#DCFCE7;color:#166534;font-size:11px;font-weight:700;padding:5px 10px;border-radius:20px;border:1px solid #BBF7D0}
.dot{width:7px;height:7px;border-radius:50%;background:#22C55E;box-shadow:0 0 0 4px rgba(34,197,94,.2);animation:pulse 2s infinite}
@keyframes pulse{0%,100%{box-shadow:0 0 0 4px rgba(34,197,94,.2)}50%{box-shadow:0 0 0 7px rgba(34,197,94,.08)}}
.steps{display:flex;gap:8px;margin:16px 0}
.step{flex:1;text-align:center;padding:10px 6px;border-radius:12px;border:1px solid #E2E8F0;background:#F8FAFC;font-size:11px;font-weight:600;color:#64748B;position:relative}
.step.active{background:linear-gradient(135deg,#EFF6FF,#DBEAFE);border-color:#BFDBFE;color:#1E40AF}
.step .num{width:22px;height:22px;border-radius:50%;background:#E2E8F0;color:#475569;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;margin-bottom:4px}
.step.active .num{background:#2563EB;color:#fff}
label{display:block;font-size:13px;font-weight:600;color:#334155;margin:14px 0 6px}
.input-wrap{position:relative}
.input-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94A3B8;font-size:14px}
.input-wrap input{width:100%;box-sizing:border-box;padding:11px 40px 11px 36px;border:1.5px solid #CBD5E1;border-radius:10px;font-size:14px;background:#fff;transition:.2s;outline:none}
.input-wrap input:focus{border-color:#3B82F6;box-shadow:0 0 0 3px rgba(59,130,246,.12);background:#fff}
.input-wrap input.valid{border-color:#22C55E;background:#F0FDF4}
.input-wrap input.invalid{border-color:#EF4444;background:#FEF2F2}
.eye{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:#F1F5F9;border:1px solid #E2E8F0;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#64748B}
.avatar-preview{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#1E3A8A,#3B82F6);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;box-shadow:0 4px 12px rgba(59,130,246,.25)}
.strength{display:flex;gap:4px;margin-top:6px}
.strength span{height:4px;flex:1;border-radius:2px;background:#E2E8F0;transition:.3s}
.strength span.s1{background:#EF4444}
.strength span.s2{background:#F59E0B}
.strength span.s3{background:#3B82F6}
.strength span.s4{background:#22C55E}
.checks{margin-top:6px;display:flex;flex-wrap:wrap;gap:6px}
.check{font-size:11px;padding:4px 8px;border-radius:20px;border:1px solid #E2E8F0;background:#F8FAFC;color:#64748B}
.check.ok{background:#DCFCE7;border-color:#BBF7D0;color:#166534}
.btn-primary{width:100%;margin-top:18px;padding:13px;background:linear-gradient(135deg,#2563EB,#1D4ED8);border:0;color:#fff;border-radius:10px;font-weight:700;font-size:15px;cursor:pointer;box-shadow:0 8px 20px rgba(37,99,235,.3);transition:.2s}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 12px 28px rgba(37,99,235,.35)}
.btn-primary:active{transform:scale(.98)}
.err{background:#FEF2F2;border:1px solid #FECACA;color:#B91C1C;padding:10px 12px;border-radius:10px;font-size:13px;margin-bottom:10px;display:flex;gap:8px;align-items:start}
.feature{margin-top:16px;padding:14px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:12px}
.feature h3{font-size:12px;font-weight:800;color:#0F172A;margin:0 0 8px;letter-spacing:.02em}
.feature ul{margin:0;padding:0;list-style:none}
.feature li{font-size:12px;color:#475569;padding:4px 0;display:flex;gap:8px;align-items:center}
.feature li i{color:#22C55E}
.footer{margin-top:14px;text-align:center;font-size:11px;color:rgba(255,255,255,.7)}
.footer a{color:#93C5FD;text-decoration:none}
</style>
</head>
<body>
<div id="bg"><canvas id="c"></canvas></div>
<div class="wrap">
  <div class="brand">
    <div class="logo"><i class="bi bi-mortarboard-fill"></i></div>
    <div style="color:#fff;font-family:Plus Jakarta Sans,sans-serif;font-weight:800;font-size:18px;margin-top:8px">Datamex College of Saint Adeline</div>
    <div style="color:rgba(255,255,255,.7);font-size:12px">EduPortal • One-time Admin Setup</div>
  </div>

  <div class="card">
    <div style="display:flex;align-items:start;justify-content:space-between;gap:12px">
      <div>
        <h1>Create the first administrator</h1>
        <p class="sub">Run once after importing database. This creates the verified admin.</p>
      </div>
      <span class="badge-live"><span class="dot"></span> DB OK • <?= (int)$adminCount ?> admin(s)</span>
    </div>

    <div class="steps">
      <div class="step active"><div class="num">1</div><div>Create</div></div>
      <div class="step"><div class="num">2</div><div>Verify</div></div>
      <div class="step"><div class="num">3</div><div>Launch</div></div>
    </div>

    <?php if ($error !== ''): ?><div class="err"><i class="bi bi-exclamation-triangle" style="margin-top:2px"></i><span><?= e($error) ?></span></div><?php endif; ?>

    <form method="post" autocomplete="off" id="f">
      <?= csrf_field() ?>
      <label for="name">Full name <span style="font-weight:400;color:#94A3B8">— preview</span></label>
      <div style="display:flex;gap:10px;align-items:center">
        <div id="avatar" class="avatar-preview">AD</div>
        <div class="input-wrap" style="flex:1">
          <i class="bi bi-person"></i>
          <input id="name" name="name" required maxlength="120" value="<?= e($name) ?>" placeholder="Juan Dela Cruz" oninput="updateAvatar(this.value);validateName(this)">
        </div>
      </div>
      <div id="nameHint" style="font-size:11px;color:#94A3B8;margin-top:4px">First + last name — e.g., Maria Santos</div>

      <label for="email">Admin email</label>
      <div class="input-wrap">
        <i class="bi bi-envelope"></i>
        <input id="email" type="email" name="email" required value="<?= e($email) ?>" placeholder="admin@datamex.edu.ph" oninput="validateEmail(this)">
      </div>
      <div id="emailHint" style="font-size:11px;color:#94A3B8;margin-top:4px">Must be real — verification link will be sent</div>

      <label for="password">Password</label>
      <div class="input-wrap">
        <i class="bi bi-shield-lock"></i>
        <input id="password" type="password" name="password" required minlength="10" placeholder="Min 10 chars, upper + lower + number" oninput="checkStrength(this.value)">
        <button type="button" class="eye" onclick="togglePwd()" tabindex="-1"><i id="eyeIcon" class="bi bi-eye"></i></button>
      </div>
      <div class="strength"><span id="s1"></span><span id="s2"></span><span id="s3"></span><span id="s4"></span></div>
      <div class="checks">
        <span id="cLen" class="check"><i class="bi bi-x"></i> 10+ chars</span>
        <span id="cUp" class="check"><i class="bi bi-x"></i> Uppercase</span>
        <span id="cLow" class="check"><i class="bi bi-x"></i> Lowercase</span>
        <span id="cNum" class="check"><i class="bi bi-x"></i> Number</span>
      </div>

      <button type="submit" class="btn-primary" id="btn"><i class="bi bi-rocket-takeoff"></i> Create admin account</button>
      <div style="text-align:center;margin-top:8px;font-size:11px;color:#94A3B8"><i class="bi bi-lightning"></i> Creates with <code>pending_verification</code> — must verify via email/OTP</div>
    </form>

    <div class="feature">
      <h3><i class="bi bi-stars" style="color:#F59E0B"></i> AFTER CREATION — ADMIN CAN</h3>
      <ul>
        <li><i class="bi bi-check-circle-fill"></i> Verify enrollments & payments (PayRex)</li>
        <li><i class="bi bi-check-circle-fill"></i> Create teacher invites & manage users</li>
        <li><i class="bi bi-check-circle-fill"></i> View Live Analytics & at-risk flags</li>
        <li><i class="bi bi-check-circle-fill"></i> Post announcements & manage permits</li>
      </ul>
    </div>

    <div style="margin-top:12px;padding:10px 12px;background:#FFFBEB;border:1px solid #FDE68A;border-radius:10px;display:flex;gap:10px;align-items:start">
      <i class="bi bi-shield-exclamation" style="color:#D97706;font-size:16px;margin-top:1px"></i>
      <div style="font-size:12px;color:#92400E"><strong>Delete after use!</strong> Anyone with this URL can create admins. Use <strong>Self-destruct</strong> on success screen.</div>
    </div>
  </div>
  <div class="footer">EduPortal v1.0 • <a href="<?= e(url('/')) ?>">Back to portal</a> • <a href="<?= e(url('/login')) ?>">Sign in</a></div>
</div>

<script>
function updateAvatar(v){
  const a=document.getElementById('avatar');
  const p=v.trim().split(/\s+/).filter(Boolean);
  const initials=((p[0]?.[0]||'A')+(p[1]?.[0]|| p[0]?.[1]||'D')).toUpperCase().slice(0,2);
  a.textContent=initials||'AD';
}
function validateName(el){
  el.classList.toggle('valid', el.value.trim().split(/\s+/).length>=2 && el.value.length>=3);
  el.classList.toggle('invalid', el.value && !el.classList.contains('valid'));
}
function validateEmail(el){
  const ok=/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(el.value);
  el.classList.toggle('valid', ok);
  el.classList.toggle('invalid', el.value && !ok);
  document.getElementById('emailHint').textContent= ok ? '✓ Valid email — verification will be sent' : 'Must be real — verification link will be sent';
  document.getElementById('emailHint').style.color= ok ? '#16A34A' : '#94A3B8';
}
function checkStrength(v){
  const hasLen=v.length>=10, hasUp=/[A-Z]/.test(v), hasLow=/[a-z]/.test(v), hasNum=/[0-9]/.test(v);
  const score=[hasLen,hasUp,hasLow,hasNum].filter(Boolean).length;
  ['s1','s2','s3','s4'].forEach((id,i)=>{
    const el=document.getElementById(id);
    el.className= i < score ? (score===1?'s1':score===2?'s2':score===3?'s3':'s4') : '';
  });
  [['cLen',hasLen],['cUp',hasUp],['cLow',hasLow],['cNum',hasNum]].forEach(([id,ok])=>{
    const el=document.getElementById(id);
    el.className= ok ? 'check ok' : 'check';
    el.innerHTML= ok ? '<i class="bi bi-check"></i> '+el.textContent.replace(/^[✓×x]\s*/,'').replace(/^<i.*?<\/i>\s*/,'') : '<i class="bi bi-x"></i> '+el.textContent.replace(/^[✓×x]\s*/,'').replace(/^<i.*?<\/i>\s*/,'');
    // keep label text
    const labels={cLen:'10+ chars',cUp:'Uppercase',cLow:'Lowercase',cNum:'Number'};
    el.innerHTML=(ok?'<i class="bi bi-check"></i> ':'<i class="bi bi-x"></i> ')+labels[id];
  });
  const inp=document.getElementById('password');
  inp.classList.toggle('valid', score===4);
  inp.classList.toggle('invalid', v && score<4);
}
function togglePwd(){
  const i=document.getElementById('password'), ic=document.getElementById('eyeIcon');
  i.type=i.type==='password'?'text':'password';
  ic.className=i.type==='password'?'bi bi-eye':'bi bi-eye-slash';
}
updateAvatar(document.getElementById('name').value);
// animated bg paths
(function(){
  const c=document.getElementById('c'), ctx=c.getContext('2d');
  let dpr=window.devicePixelRatio||1;
  function resize(){c.width=innerWidth*dpr;c.height=innerHeight*dpr;c.style.width=innerWidth+'px';c.style.height=innerHeight+'px';ctx.setTransform(dpr,0,0,dpr,0,0)}
  resize();addEventListener('resize',resize);
  let t=0;
  function frame(){
    t+=0.006;ctx.clearRect(0,0,innerWidth,innerHeight);
    for(let i=0;i<28;i++){
      const yBase=innerHeight*0.45+(i/27-0.5)*innerHeight*0.95;
      const off=Math.sin(t+i*0.14)*14;
      const op=0.05+(i%4)*0.02+Math.sin(t*0.5+i)*0.012;
      ctx.beginPath();ctx.strokeStyle='rgba(255,255,255,'+Math.max(.03,Math.min(.16,op))+')';ctx.lineWidth=i%5===0?1.1:0.8;
      ctx.moveTo(-100,yBase+off);
      for(let x=-100;x<innerWidth+100;x+=110){
        const cpY=yBase+Math.sin(x*0.003+i*0.2+t*0.6)*18+Math.cos(x*0.0018-t*0.3)*8;
        ctx.quadraticCurveTo(x+55,cpY,x+110,yBase+Math.sin((x+110)*0.003+t*0.6)*12);
      }
      ctx.stroke();
    }
    requestAnimationFrame(frame);
  }
  frame();
})();
</script>
</body>
</html>
