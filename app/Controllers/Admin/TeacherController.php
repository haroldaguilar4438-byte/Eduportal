<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use Throwable;

/**
 * Admin Teacher Management — Secure teacher onboarding.
 * Two paths:
 *   A) Invite code: admin generates single-use hashed code, teacher self-registers at /register-teacher with code.
 *   B) Direct creation: admin creates teacher account immediately (bypasses invite).
 */
final class TeacherController extends Controller
{
    /* List all teachers + recent invites */
    public function index(array $params = []): void
    {
        Auth::requireRole('admin');
        $teachers = Database::all(
            "SELECT t.id, t.employee_no, t.department, t.position, t.employment_status,
                    u.id AS user_id, u.email, u.first_name, u.last_name, u.is_email_verified, u.status, u.created_at
               FROM teachers t
               JOIN users u ON u.id = t.user_id
              ORDER BY t.id DESC LIMIT 100"
        );
        $invites = Database::all(
            "SELECT ti.*, CONCAT(adm.first_name,' ',adm.last_name) AS creator_name
               FROM teacher_invites ti
               JOIN users adm ON adm.id = ti.created_by
              ORDER BY ti.created_at DESC LIMIT 50"
        );
        $this->view('admin/teachers/index', [
            'title'    => 'Teacher Accounts',
            'teachers' => $teachers,
            'invites'  => $invites,
        ]);
    }

    /* Show invite generation form */
    public function showInviteForm(array $params = []): void
    {
        Auth::requireRole('admin');
        $this->view('admin/teachers/invite', ['title' => 'Generate Teacher Invite Code']);
    }

    /* Generate single-use invite code (secure) */
    public function generateInvite(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');

        $emailHint = mb_strtolower(trim((string)($_POST['email_hint'] ?? '')));
        $emailHint = $emailHint !== '' && filter_var($emailHint, FILTER_VALIDATE_EMAIL) ? $emailHint : null;
        $department = trim((string)($_POST['department'] ?? ''));
        $department = $department !== '' ? substr($department, 0, 100) : null;

        // Generate cryptographically secure code: TCH-XXXX-XXXX where X from 32-char alphabet (no ambiguous)
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $randPart = static function(int $len) use ($alphabet): string {
            $out = '';
            $max = strlen($alphabet) - 1;
            for ($i=0;$i<$len;$i++) $out .= $alphabet[random_int(0, $max)];
            return $out;
        };
        $plainCode = 'TCH-' . $randPart(4) . '-' . $randPart(4); // e.g., TCH-7K9P-3M2Q
        $codeHash = password_hash($plainCode, PASSWORD_DEFAULT);
        $preview  = substr($plainCode, -4); // last 4 for admin UI
        $enc = $this->encryptCode($plainCode);

        $expiresAt = date('Y-m-d H:i:s', time() + 7*24*3600); // 7 days

        try {
            $inviteData = [
                'code_hash'      => $codeHash,
                'code_preview'   => $preview,
                'email_hint'     => $emailHint,
                'department'     => $department,
                'created_by'     => (int)Auth::id(),
                'expires_at'     => $expiresAt,
            ];
            // code_encrypted column may not exist on older installs — add if present.
            try {
                $hasEncCol = Database::value(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teacher_invites' AND COLUMN_NAME = 'code_encrypted'"
                );
                if ($hasEncCol) $inviteData['code_encrypted'] = $enc;
            } catch (Throwable $e2) {
                // If check fails, try with encrypted column; fallback below handles it.
                $inviteData['code_encrypted'] = $enc;
            }
            try {
                Database::insert('teacher_invites', $inviteData);
            } catch (Throwable $e2) {
                if (str_contains($e2->getMessage(), 'code_encrypted')) {
                    unset($inviteData['code_encrypted']);
                    Database::insert('teacher_invites', $inviteData);
                } else {
                    throw $e2;
                }
            }
            $newId = Database::lastInsertId();
            Database::insert('audit_logs', [
                'actor_user_id'=> (int)Auth::id(),
                'action'       => 'teacher.invite_generated',
                'entity_type'  => 'teacher_invites',
                'entity_id'    => $newId,
                'ip_address'   => client_ip(),
            ]);
            // Show plain code ONCE — also stored encrypted for secure reveal later
            set_flash('success', 'Invite code generated: ' . $plainCode . ' (expires in 7 days, single-use, hashed). Share securely. You can securely reveal it later via Invite Details → Reveal (requires password, audit-logged).');
            if ($emailHint) set_flash('info', 'Restricted to email: ' . $emailHint);
        } catch (Throwable $e) {
            storage_log('teacher invite generate failed: '.$e->getMessage());
            set_flash('error', 'Could not generate invite: '.$e->getMessage());
        }
        redirect('/admin/teachers');
    }

    /* Revoke unused invite */
    public function revokeInvite(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');
        $id = (int)($params['id'] ?? 0);
        $inv = Database::one("SELECT id, is_used FROM teacher_invites WHERE id=? LIMIT 1", [$id]);
        if (!$inv || (int)$inv['is_used']===1) { set_flash('error','Invite not found or already used.'); redirect('/admin/teachers'); }
        Database::run("DELETE FROM teacher_invites WHERE id=? AND is_used=0", [$id]);
        Database::insert('audit_logs', ['actor_user_id'=>(int)Auth::id(),'action'=>'teacher.invite_revoked','entity_type'=>'teacher_invites','entity_id'=>$id,'ip_address'=>client_ip()]);
        set_flash('success','Invite revoked.');
        redirect('/admin/teachers');
    }

    /* Direct creation by admin (bypass invite) */
    public function createDirect(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');

        $first = trim((string)($_POST['first_name'] ?? ''));
        $last  = trim((string)($_POST['last_name'] ?? ''));
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $dept  = trim((string)($_POST['department'] ?? ''));
        $pos   = trim((string)($_POST['position'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        $errors = [];
        if ($first===''||$last==='') $errors[]='First and last name required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[]='Valid email required.';
        if (strlen($password)<10 || !preg_match('/[A-Z]/',$password) || !preg_match('/[a-z]/',$password) || !preg_match('/\d/',$password)) $errors[]='Password must be 10+ chars, include upper, lower, and number.';
        if (Database::one("SELECT id FROM users WHERE email=? LIMIT 1", [$email])) $errors[]='Email already exists.';

        if ($errors!==[]) { foreach($errors as $e) set_flash('error',$e); redirect('/admin/teachers'); }

        try {
            $userId = Database::transaction(function() use ($first,$last,$email,$phone,$password, $dept,$pos) {
                $uid = Database::insert('users', [
                    'role_id'       => 2,
                    'email'         => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'first_name'    => $first,
                    'last_name'     => $last,
                    'phone'         => $phone ?: null,
                    'is_email_verified'=> 0,
                    'verified_at'   => null,
                    'status'        => 'pending_verification',
                ]);
                $empNo = 'T-' . date('Y') . '-' . str_pad((string)$uid, 4, '0', STR_PAD_LEFT);
                Database::insert('teachers', [
                    'user_id'     => $uid,
                    'employee_no' => $empNo,
                    'department'  => $dept ?: null,
                    'position'    => $pos ?: null,
                ]);
                return $uid;
            });
            try {
                $tok = Auth::issueVerification($userId);
                $u = \App\Models\User::find($userId) ?? ['email'=>$email,'first_name'=>$first,'last_name'=>$last];
                send_verification_link_email($u, $tok);
                try { $otp = Auth::issueOtp($userId); send_otp_email($u, $otp); } catch (Throwable $e) {}
            } catch (Throwable $e) { storage_log('teacher direct verify email failed '.$userId.': '.$e->getMessage()); }
            Database::insert('audit_logs', ['actor_user_id'=>(int)Auth::id(),'action'=>'teacher.direct_created_pending_verify','entity_type'=>'users','entity_id'=>$userId,'ip_address'=>client_ip()]);
            set_flash('success','Teacher account created (pending verification): ' . $email . ' — verification link + OTP sent. They must verify before signing in (proves real email).');
        } catch (Throwable $e) {
            storage_log('direct teacher create failed: '.$e->getMessage());
            set_flash('error','Failed: '.$e->getMessage());
        }
        redirect('/admin/teachers');
    }

    public function revealInvite(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');
        $id = (int)($params['id'] ?? 0);
        $password = (string)($_POST['password'] ?? '');
        if ($password === '') { json_response(['ok'=>false,'error'=>'Password required'], 400); }

        $admin = Database::one("SELECT password_hash FROM users WHERE id=? LIMIT 1", [(int)Auth::id()]);
        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            Database::insert('audit_logs',['actor_user_id'=>(int)Auth::id(),'action'=>'teacher.invite_reveal_failed','entity_type'=>'teacher_invites','entity_id'=>$id,'ip_address'=>client_ip()]);
            json_response(['ok'=>false,'error'=>'Password incorrect — reveal denied and logged.'], 403);
        }

        $inv = Database::one("SELECT * FROM teacher_invites WHERE id=? LIMIT 1", [$id]);
        if (!$inv) json_response(['ok'=>false,'error'=>'Invite not found'],404);
        if ((int)$inv['is_used']===1) json_response(['ok'=>false,'error'=>'Already used — cannot reveal'],400);
        if (strtotime($inv['expires_at']) < time()) json_response(['ok'=>false,'error'=>'Expired — cannot reveal'],400);
        if (empty($inv['code_encrypted'])) json_response(['ok'=>false,'error'=>'Full code not available — generated before encryption. Generate a new invite.'],400);

        $plain = $this->decryptCode((string)$inv['code_encrypted']);
        if ($plain === null) json_response(['ok'=>false,'error'=>'Decryption failed — key mismatch'],500);

        Database::insert('audit_logs',['actor_user_id'=>(int)Auth::id(),'action'=>'teacher.invite_revealed','entity_type'=>'teacher_invites','entity_id'=>$id,'ip_address'=>client_ip()]);
        json_response(['ok'=>true,'code'=>$plain,'preview'=>$inv['code_preview']]);
    }

    private function encryptCode(string $plain): string
    {
        $keyHex = defined('INVITE_ENC_KEY') ? INVITE_ENC_KEY : '';
        $key = hex2bin($keyHex) ?: hash('sha256', $keyHex, true);
        if (strlen($key) < 32) $key = str_pad($key, 32, "\0");
        $key = substr($key, 0, 32);
        $iv = random_bytes(16);
        $ct = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $ct);
    }

    private function decryptCode(string $enc): ?string
    {
        $keyHex = defined('INVITE_ENC_KEY') ? INVITE_ENC_KEY : '';
        $key = hex2bin($keyHex) ?: hash('sha256', $keyHex, true);
        if (strlen($key) < 32) $key = str_pad($key, 32, "\0");
        $key = substr($key, 0, 32);
        $raw = base64_decode($enc, true);
        if ($raw === false || strlen($raw) < 17) return null;
        $iv = substr($raw, 0, 16);
        $ct = substr($raw, 16);
        $plain = openssl_decrypt($ct, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return $plain === false ? null : $plain;
    }
}
