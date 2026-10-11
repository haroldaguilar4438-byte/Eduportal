<?php
declare(strict_types=1);
namespace App\Controllers\Admin;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use Throwable;
final class UserController extends Controller
{
    public function index(array $params = []): void
    {
        Auth::requireRole('admin');
        $q = trim((string)($_GET['q'] ?? ''));
        $role = trim((string)($_GET['role'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));
        $where = [];
        $bind = [];
        if ($q !== '') {
            $where[] = "(u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name,' ',u.last_name) LIKE ?)";
            $like = "%$q%";
            array_push($bind, $like, $like, $like, $like);
        }
        if (in_array($role, ['admin','teacher','student','parent','registrar','accounting'], true)) {
            $where[] = "r.name = ?";
            $bind[] = $role;
        }
        if (in_array($status, ['active','pending_verification','suspended','locked','banned','on_hold','archived'], true)) {
            $where[] = "u.status = ?";
            $bind[] = $status;
        }
        $sql = "SELECT u.id, u.email, u.first_name, u.last_name, u.middle_name, u.suffix, u.phone, u.status, u.is_email_verified, u.verified_at, u.created_at, u.last_login_at, u.failed_login_attempts, u.locked_until, r.name AS role_name, r.id AS role_id,
                       mf.is_enabled AS mfa_enabled, mf.verified_at AS mfa_since,
                       (SELECT COUNT(*) FROM mfa_recovery_codes rc WHERE rc.user_id = u.id AND rc.used_at IS NULL) AS mfa_codes_left,
                       (SELECT COUNT(*) FROM trusted_devices td WHERE td.user_id = u.id AND td.revoked_at IS NULL AND td.expires_at > NOW()) AS trusted_count,
                       (SELECT COUNT(*) FROM user_sessions us WHERE us.user_id = u.id AND us.revoked_at IS NULL) AS session_count
                FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN user_mfa mf ON mf.user_id = u.id";
        if ($where) $sql .= " WHERE " . implode(" AND ", $where);
        $sql .= " ORDER BY u.id DESC LIMIT 200";
        $users = Database::all($sql, $bind);
        $stats = Database::one("SELECT COUNT(*) AS total, SUM(u.status='pending_verification') AS pending, SUM(u.is_email_verified=1) AS verified, SUM(u.status='active') AS active FROM users u") ?? ['total'=>0,'pending'=>0,'verified'=>0,'active'=>0];
        $byRole = Database::all("SELECT r.name, COUNT(*) AS c FROM users u JOIN roles r ON r.id=u.role_id GROUP BY r.name");
        $this->view('admin/users/index', ['title'=>'All Accounts','users'=>$users,'stats'=>$stats,'byRole'=>$byRole,'q'=>$q,'roleFilter'=>$role,'statusFilter'=>$status]);
    }

    private function targetOrFail(int $id): array
    {
        $u = Database::one("SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? LIMIT 1", [$id]);
        if (!$u) { set_flash('error','Account not found.'); redirect('/admin/users'); }
        if ((int)$u['id'] === (int)Auth::id()) { set_flash('error','You cannot change your own account.'); redirect('/admin/users'); }
        if ($u['role_name']==='admin' && $u['status']==='active') {
            $activeAdmins = (int)Database::value("SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='admin' AND u.status='active'");
            if ($activeAdmins <= 1) { set_flash('error','Cannot modify the last active admin.'); redirect('/admin/users'); }
        }
        return $u;
    }

    public function hold(array $params = []): void
    {
        Csrf::guard(); Auth::requireRole('admin');
        $u = $this->targetOrFail((int)($params['id']??0));
        Database::run("UPDATE users SET status='on_hold' WHERE id=?", [(int)$u['id']]);
        if ($u['role_name']==='student') Database::run("UPDATE students SET status='on_hold' WHERE user_id=?", [(int)$u['id']]);
        Database::insert('audit_logs',['actor_user_id'=>(int)Auth::id(),'action'=>'user.on_hold','entity_type'=>'users','entity_id'=>(int)$u['id'],'ip_address'=>client_ip()]);
        set_flash('success','Account #'.$u['id'].' put On Hold (suspended).');
        redirect('/admin/users');
    }

    public function ban(array $params = []): void
    {
        Csrf::guard(); Auth::requireRole('admin');
        $u = $this->targetOrFail((int)($params['id']??0));
        Database::run("UPDATE users SET status='banned' WHERE id=?", [(int)$u['id']]);
        if ($u['role_name']==='student') Database::run("UPDATE students SET status='dropped' WHERE user_id=?", [(int)$u['id']]);
        Database::insert('audit_logs',['actor_user_id'=>(int)Auth::id(),'action'=>'user.banned','entity_type'=>'users','entity_id'=>(int)$u['id'],'ip_address'=>client_ip()]);
        set_flash('success','Account #'.$u['id'].' banned (locked).');
        redirect('/admin/users');
    }

    public function revoke(array $params = []): void
    {
        Csrf::guard(); Auth::requireRole('admin');
        $u = $this->targetOrFail((int)($params['id']??0));
        Database::run("UPDATE users SET status='pending_verification', is_email_verified=0, verified_at=NULL WHERE id=?", [(int)$u['id']]);
        Database::insert('audit_logs',['actor_user_id'=>(int)Auth::id(),'action'=>'user.revoked','entity_type'=>'users','entity_id'=>(int)$u['id'],'ip_address'=>client_ip()]);
        set_flash('success','Account #'.$u['id'].' revoked — must re-verify email to sign in.');
        redirect('/admin/users');
    }

    public function remove(array $params = []): void
    {
        Csrf::guard(); Auth::requireRole('admin');
        $u = $this->targetOrFail((int)($params['id']??0));
        $uid = (int) $u['id'];
        try {
            // Tables actually present (older installs may lack newer ones).
            $existing = [];
            try {
                foreach (Database::all("SHOW TABLES") as $r) $existing[] = (string) reset($r);
            } catch (Throwable $e) { $existing = []; }
            $has = static fn(string $t): bool => $existing === [] || in_array($t, $existing, true);
            // NOTE: $existing === [] means SHOW TABLES itself failed — fail open
            // is wrong here; treat unknown as present and let FKs decide.
            // Resolve every owned id first (all ints from the DB, safe to inline).
            $int = static fn(array $ids): string => implode(',', array_map('intval', $ids));
            $col = static function (string $sql, array $bind = []): array {
                try {
                    return array_column(Database::all($sql, $bind), 'id');
                } catch (Throwable $e) { return []; }
            };
            $sids = $col("SELECT id FROM students WHERE user_id = ?", [$uid]);
            $gids = $col("SELECT id FROM guardians WHERE user_id = ?", [$uid]);
            $appIds = $col(
                "SELECT id FROM enrollment_applications WHERE user_id = ?" . ($sids !== [] ? " OR student_id IN (" . $int($sids) . ")" : ""),
                [$uid]
            );
            $payIds = $col(
                "SELECT id FROM payments WHERE initiated_by = ?"
                . ($sids !== [] ? " OR student_id IN (" . $int($sids) . ")" : "")
                . ($appIds !== [] ? " OR enrollment_application_id IN (" . $int($appIds) . ")" : ""),
                [$uid]
            );
            $chargeIds = $sids !== [] ? $col("SELECT id FROM student_charges WHERE student_id IN (" . $int($sids) . ")") : [];
            $clrIds = $sids !== [] ? $col("SELECT id FROM clearances WHERE student_id IN (" . $int($sids) . ")") : [];
            // Collect uploaded files BEFORE rows disappear (deleted from disk after commit).
            $files = [];
            try {
                if (!empty($u['avatar_path'])) $files[] = (string) $u['avatar_path'];
                if ($appIds !== [] && $has('enrollment_documents')) {
                    foreach (Database::all("SELECT stored_path FROM enrollment_documents WHERE application_id IN (" . $int($appIds) . ")") as $f) {
                        if (!empty($f['stored_path'])) $files[] = (string) $f['stored_path'];
                    }
                }
                if ($sids !== [] && $has('student_documents')) {
                    foreach (Database::all("SELECT stored_path FROM student_documents WHERE student_id IN (" . $int($sids) . ")") as $f) {
                        if (!empty($f['stored_path'])) $files[] = (string) $f['stored_path'];
                    }
                }
                if ($payIds !== [] && $has('receipts')) {
                    foreach (Database::all("SELECT pdf_path FROM receipts WHERE payment_id IN (" . $int($payIds) . ")") as $f) {
                        if (!empty($f['pdf_path'])) $files[] = (string) $f['pdf_path'];
                    }
                }
            } catch (Throwable $e) {}
            // Safety net FIRST: snapshot everything (DB rows). Files are
            // stashed (moved, not deleted) after the commit below.
            $backupId = 0;
            $backupAvailable = false;
            try {
                if (class_exists(\App\Core\AccountBackup::class)) {
                    try {
                        Database::value("SELECT id FROM account_backups LIMIT 1");
                        $backupAvailable = true;
                    } catch (Throwable $e) {
                        $backupAvailable = false;
                    }
                    if ($backupAvailable) {
                        $backupId = \App\Core\AccountBackup::snapshot($uid, 'remove', (int) Auth::id());
                    }
                }
            } catch (Throwable $e) {
            }
            if ($backupAvailable && $backupId <= 0) {
                set_flash('error', 'Safety backup failed — nothing was deleted. Try again or contact support.');
                redirect('/admin/users');
            }
            $run = static function (string $table, string $sql, array $bind = []) use ($has): void {
                if ($has($table)) Database::run($sql, $bind);
            };
            Database::transaction(function () use ($uid, $sids, $gids, $appIds, $payIds, $chargeIds, $clrIds, $int, $run) {
                // Children first (explicit deletes — never rely on live FKs existing).
                if ($sids !== []) {
                    $in = $int($sids);
                    $run('classroom_task_submissions', "DELETE FROM classroom_task_submissions WHERE student_id IN ($in)");
                    $run('assignment_submissions', "DELETE FROM assignment_submissions WHERE student_id IN ($in)");
                    $run('grade_sheet_rows', "DELETE FROM grade_sheet_rows WHERE student_id IN ($in)");
                    $run('classroom_members', "DELETE FROM classroom_members WHERE student_id IN ($in)");
                    $run('student_class_registrations', "DELETE FROM student_class_registrations WHERE student_id IN ($in)");
                    $run('teacher_evaluations', "DELETE FROM teacher_evaluations WHERE student_id IN ($in)");
                    $run('at_risk_flags', "DELETE FROM at_risk_flags WHERE student_id IN ($in)");
                    $run('student_charges', "DELETE FROM student_charges WHERE student_id IN ($in)");
                    $run('exam_permits', "DELETE FROM exam_permits WHERE student_id IN ($in)");
                    $run('academic_documents', "DELETE FROM academic_documents WHERE student_id IN ($in)");
                    $run('student_documents', "DELETE FROM student_documents WHERE student_id IN ($in)");
                }
                if ($chargeIds !== [] || $payIds !== []) {
                    $cond = [];
                    if ($chargeIds !== []) $cond[] = "charge_id IN (" . $int($chargeIds) . ")";
                    if ($payIds !== []) $cond[] = "payment_id IN (" . $int($payIds) . ")";
                    $run('payment_allocations', "DELETE FROM payment_allocations WHERE " . implode(' OR ', $cond));
                }
                if ($payIds !== []) {
                    $in = $int($payIds);
                    $run('receipts', "DELETE FROM receipts WHERE payment_id IN ($in)");
                    $run('payments', "DELETE FROM payments WHERE id IN ($in)");
                }
                if ($appIds !== []) {
                    $in = $int($appIds);
                    $run('enrollment_documents', "DELETE FROM enrollment_documents WHERE application_id IN ($in)");
                    $run('enrollment_applications', "DELETE FROM enrollment_applications WHERE id IN ($in)");
                }
                if ($clrIds !== []) {
                    $in = $int($clrIds);
                    $run('clearance_items', "DELETE FROM clearance_items WHERE clearance_id IN ($in)");
                    $run('clearance_status_logs', "DELETE FROM clearance_status_logs WHERE clearance_id IN ($in)");
                    $run('clearances', "DELETE FROM clearances WHERE id IN ($in)");
                }
                if ($sids !== [] || $gids !== []) {
                    $cond = [];
                    if ($sids !== []) $cond[] = "student_id IN (" . $int($sids) . ")";
                    if ($gids !== []) $cond[] = "guardian_id IN (" . $int($gids) . ")";
                    $run('guardian_students', "DELETE FROM guardian_students WHERE " . implode(' OR ', $cond));
                }
                $run('notifications', "DELETE FROM notifications WHERE user_id = ?", [$uid]);
                // Parents/root rows last. Audit/history tables (audit_logs,
                // login_attempts, email_log, approvals) are intentionally kept.
                $run('guardians', "DELETE FROM guardians WHERE user_id = ?", [$uid]);
                $run('teachers', "DELETE FROM teachers WHERE user_id = ?", [$uid]);
                $run('students', "DELETE FROM students WHERE user_id = ?", [$uid]);
                Database::run("DELETE FROM users WHERE id = ?", [$uid]);
            });
            // Disk handling only after the DB commit succeeded: MOVE uploads
            // into the backup stash (restorable) instead of deleting them.
            $removedFiles = 0;
            $stashedFiles = 0;
            if ($backupId > 0 && class_exists(\App\Core\AccountBackup::class)) {
                try {
                    [$stashedFiles, $removedFiles] = \App\Core\AccountBackup::stashFiles($backupId, $files);
                } catch (Throwable $e) {
                    $stashedFiles = 0;
                }
            }
            if ($stashedFiles === 0) {
                $base = realpath(ROOT_PATH . '/public_html/uploads');
                foreach ($files as $rel) {
                    $rel = ltrim(str_replace('\\', '/', (string) $rel), '/');
                    if ($rel === '' || str_contains($rel, '..')) continue;
                    $full = ROOT_PATH . '/public_html/' . $rel;
                    $real = realpath($full);
                    if ($real === false || $base === false || !str_starts_with($real, $base)) continue;
                    if (is_file($real) && @unlink($real)) $removedFiles++;
                }
            }
            Database::insert('audit_logs', ['actor_user_id' => (int) Auth::id(), 'action' => 'user.removed', 'entity_type' => 'users', 'entity_id' => $uid, 'new_values_json' => json_encode(['apps' => count($appIds), 'payments' => count($payIds), 'files' => $removedFiles, 'backup_id' => $backupId]), 'ip_address' => client_ip()]);
            set_flash('success', 'Account #' . $uid . ' (' . $u['email'] . ') permanently removed with ' . count($appIds) . ' enrollment(s), ' . count($payIds) . ' payment(s) and ' . $removedFiles . ' file(s).' . ($backupId > 0 ? ' Backup #' . $backupId . ' (' . $stashedFiles . ' file(s) stashed) captured — restore from Backups if this was a mistake.' : ' No orphan rows left behind.'));
        } catch (Throwable $e) { storage_log('user remove failed: ' . $e->getMessage()); set_flash('error', 'Remove failed: ' . $e->getMessage()); }
        redirect('/admin/users');
    }

    public function restore(array $params = []): void
    {
        Csrf::guard(); Auth::requireRole('admin');
        $id=(int)($params['id']??0);
        $u=Database::one("SELECT * FROM users WHERE id=? LIMIT 1",[$id]);
        if(!$u){ set_flash('error','Not found'); redirect('/admin/users'); }
        Database::run("UPDATE users SET status='active', is_email_verified=1, verified_at=COALESCE(verified_at,NOW()) WHERE id=?",[$id]);
        if ($u['role_id']==3) Database::run("UPDATE students SET status='enrolled' WHERE user_id=? AND status IN ('on_hold','dropped')",[$id]);
        Database::insert('audit_logs',['actor_user_id'=>(int)Auth::id(),'action'=>'user.restored','entity_type'=>'users','entity_id'=>$id,'ip_address'=>client_ip()]);
        set_flash('success','Account #'.$id.' restored to Active.');
        redirect('/admin/users');
    }

    /**
     * Reset an account to a safe default state (account recovery):
     * unlocks lockout, revokes all sessions + trusted devices, disables MFA
     * (secret + recovery codes wiped), and sets a one-time temporary
     * password shown once to the admin and emailed to the user.
     * Student records, grades, enrollments, and payments are NEVER touched.
     */
    public function resetAccount(array $params = []): void
    {
        Csrf::guard(); Auth::requireRole('admin');
        $u = $this->targetOrFail((int)($params['id'] ?? 0));
        $uid = (int) $u['id'];
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $temp = '';
        for ($i = 0; $i < 12; $i++) $temp .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        // Safety net first: snapshot access state for one-click restore.
        $backupId = 0;
        try {
            if (class_exists(\App\Core\AccountBackup::class)) {
                $backupId = \App\Core\AccountBackup::snapshot($uid, 'reset', (int) Auth::id());
            }
        } catch (Throwable $e) {
        }
        try {
            Database::transaction(function () use ($uid, $temp) {
                Database::run(
                    "UPDATE users SET password_hash = ?, failed_login_attempts = 0, locked_until = NULL, remember_token = NULL WHERE id = ?",
                    [password_hash($temp, PASSWORD_DEFAULT), $uid]
                );
                Database::run("DELETE FROM user_mfa WHERE user_id = ?", [$uid]);
                Database::run("DELETE FROM mfa_recovery_codes WHERE user_id = ?", [$uid]);
                Database::run("UPDATE trusted_devices SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL", [$uid]);
                Database::run("UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL", [$uid]);
                Database::run("UPDATE pending_logins SET used_at = NOW(), token_hash = NULL WHERE user_id = ? AND used_at IS NULL", [$uid]);
            });
            Database::insert('audit_logs', ['actor_user_id' => (int) Auth::id(), 'action' => 'user.reset_default', 'entity_type' => 'users', 'entity_id' => $uid, 'ip_address' => client_ip()]);
            try {
                $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                edu_mailer()->send(
                    (string) $u['email'], $name !== '' ? $name : (string) $u['email'],
                    'Your EduPortal account was reset by an administrator',
                    edu_email_shell('Account reset', '<p style="color:#334155;">Hi <strong>' . e($name !== '' ? $name : 'there') . '</strong>,</p>'
                        . '<p style="color:#334155;">An administrator reset your account to a safe default: lockouts cleared, all devices signed out, and MFA turned off.</p>'
                        . '<p style="color:#334155;">Your temporary password is: <strong style="font-size:16px">' . e($temp) . '</strong></p>'
                        . '<p style="color:#b91c1c;font-size:13px;">Sign in with it right away, then change it in Profile → Change Password and re-enable MFA in Security Settings.</p>'),
                    'security_admin_reset'
                );
            } catch (Throwable $e) {
            }
            set_flash('success', 'Account #' . $uid . ' reset to default (unlocked, sessions/devices revoked, MFA off). Temporary password: ' . $temp . ' — share it securely; it was also emailed to ' . $u['email'] . '.' . ($backupId > 0 ? ' Backup #' . $backupId . ' captured for one-click restore.' : ''));
        } catch (Throwable $e) {
            storage_log('admin reset failed #' . $uid . ': ' . $e->getMessage());
            set_flash('error', 'Reset failed: ' . $e->getMessage());
        }
        redirect('/admin/users');
    }

    /** Resend the email-verification link to an unverified account. */
    public function resendVerification(array $params = []): void
    {
        Csrf::guard(); Auth::requireRole('admin');
        $u = $this->targetOrFail((int)($params['id'] ?? 0));
        if ((int) $u['is_email_verified'] === 1) {
            set_flash('info', 'Account #' . $u['id'] . ' is already verified.');
            redirect('/admin/users');
        }
        try {
            $token = Auth::issueVerification((int) $u['id']);
            send_verification_link_email($u, $token);
            Database::insert('audit_logs', ['actor_user_id' => (int) Auth::id(), 'action' => 'user.verify_resent', 'entity_type' => 'users', 'entity_id' => (int) $u['id'], 'ip_address' => client_ip()]);
            set_flash('success', 'Verification email resent to ' . $u['email'] . '.');
        } catch (Throwable $e) {
            set_flash('error', 'Could not resend: ' . $e->getMessage());
        }
        redirect('/admin/users');
    }

    /**
     * Factory reset: wipe the account back to just-created state while KEEPING
     * the login (email + role). Deletes enrollments, payments, grades,
     * permits, clearances, documents, subjects memberships, own profile rows,
     * sessions, MFA, lockout — then sets a temporary password and puts the
     * account back to pending email verification. A full backup is captured
     * first (refused without it). Other people's rows are never touched:
     * linked children's own student records survive.
     */
    public function factoryReset(array $params = []): void
    {
        Csrf::guard(); Auth::requireRole('admin');
        $u = $this->targetOrFail((int)($params['id'] ?? 0));
        $uid = (int) $u['id'];
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $temp = '';
        for ($i = 0; $i < 12; $i++) $temp .= $alphabet[random_int(0, strlen($alphabet) - 1)];

        // Safety net first (full snapshot); refuse to wipe blind.
        $backupId = 0;
        try {
            if (class_exists(\App\Core\AccountBackup::class)) {
                try {
                    Database::value("SELECT id FROM account_backups LIMIT 1");
                    $backupId = \App\Core\AccountBackup::snapshot($uid, 'factory', (int) Auth::id());
                } catch (Throwable $e) {
                    $backupId = -1; // tables missing → legacy mode below still wipes? NO: refuse.
                }
            } else {
                $backupId = -1;
            }
        } catch (Throwable $e) {
            $backupId = -1;
        }
        if ($backupId <= 0) {
            set_flash('error', 'Safety backup failed — nothing was wiped. Import database/patch_account_backups.sql (+ factory patch) and try again.');
            redirect('/admin/users');
        }

        try {
            $int = static fn(array $ids): string => implode(',', array_map('intval', $ids));
            $col = static function (string $sql, array $bind = []): array {
                try {
                    return array_column(Database::all($sql, $bind), 'id');
                } catch (Throwable $e) { return []; }
            };
            $sids = $col("SELECT id FROM students WHERE user_id = ?", [$uid]);
            $gids = $col("SELECT id FROM guardians WHERE user_id = ?", [$uid]);
            $appIds = $col(
                "SELECT id FROM enrollment_applications WHERE user_id = ?" . ($sids !== [] ? " OR student_id IN (" . $int($sids) . ")" : ""),
                [$uid]
            );
            $payIds = $col(
                "SELECT id FROM payments WHERE initiated_by = ?"
                . ($sids !== [] ? " OR student_id IN (" . $int($sids) . ")" : "")
                . ($appIds !== [] ? " OR enrollment_application_id IN (" . $int($appIds) . ")" : ""),
                [$uid]
            );
            $chargeIds = $sids !== [] ? $col("SELECT id FROM student_charges WHERE student_id IN (" . $int($sids) . ")") : [];
            $clrIds = $sids !== [] ? $col("SELECT id FROM clearances WHERE student_id IN (" . $int($sids) . ")") : [];
            $files = [];
            try {
                if (!empty($u['avatar_path'])) $files[] = (string) $u['avatar_path'];
                foreach (Database::all("SELECT stored_path FROM enrollment_documents WHERE application_id IN (" . ($appIds !== [] ? $int($appIds) : '0') . ")") as $f) {
                    if (!empty($f['stored_path'])) $files[] = (string) $f['stored_path'];
                }
                foreach (Database::all("SELECT stored_path FROM student_documents WHERE student_id IN (" . ($sids !== [] ? $int($sids) : '0') . ")") as $f) {
                    if (!empty($f['stored_path'])) $files[] = (string) $f['stored_path'];
                }
                foreach (Database::all("SELECT pdf_path FROM receipts WHERE payment_id IN (" . ($payIds !== [] ? $int($payIds) : '0') . ")") as $f) {
                    if (!empty($f['pdf_path'])) $files[] = (string) $f['pdf_path'];
                }
            } catch (Throwable $e) {}
            $drop = static function (string $sql, array $bind = []): void {
                try { Database::run($sql, $bind); } catch (Throwable $e) {}
            };
            Database::transaction(function () use ($uid, $temp, $sids, $gids, $appIds, $payIds, $chargeIds, $clrIds, $int, $drop) {
                if ($sids !== []) {
                    $in = $int($sids);
                    $drop("DELETE FROM classroom_task_submissions WHERE student_id IN ($in)");
                    $drop("DELETE FROM assignment_submissions WHERE student_id IN ($in)");
                    $drop("DELETE FROM grade_sheet_rows WHERE student_id IN ($in)");
                    $drop("DELETE FROM classroom_members WHERE student_id IN ($in)");
                    $drop("DELETE FROM student_class_registrations WHERE student_id IN ($in)");
                    $drop("DELETE FROM teacher_evaluations WHERE student_id IN ($in)");
                    $drop("DELETE FROM at_risk_flags WHERE student_id IN ($in)");
                    $drop("DELETE FROM student_charges WHERE student_id IN ($in)");
                    $drop("DELETE FROM exam_permits WHERE student_id IN ($in)");
                    $drop("DELETE FROM academic_documents WHERE student_id IN ($in)");
                    $drop("DELETE FROM student_documents WHERE student_id IN ($in)");
                }
                if ($chargeIds !== [] || $payIds !== []) {
                    $cond = [];
                    if ($chargeIds !== []) $cond[] = "charge_id IN (" . $int($chargeIds) . ")";
                    if ($payIds !== []) $cond[] = "payment_id IN (" . $int($payIds) . ")";
                    $drop("DELETE FROM payment_allocations WHERE " . implode(' OR ', $cond));
                }
                if ($payIds !== []) {
                    $in = $int($payIds);
                    $drop("DELETE FROM receipts WHERE payment_id IN ($in)");
                    $drop("DELETE FROM payments WHERE id IN ($in)");
                }
                if ($appIds !== []) {
                    $in = $int($appIds);
                    $drop("DELETE FROM enrollment_documents WHERE application_id IN ($in)");
                    $drop("DELETE FROM enrollment_applications WHERE id IN ($in)");
                }
                if ($clrIds !== []) {
                    $in = $int($clrIds);
                    $drop("DELETE FROM clearance_items WHERE clearance_id IN ($in)");
                    $drop("DELETE FROM clearance_status_logs WHERE clearance_id IN ($in)");
                    $drop("DELETE FROM clearances WHERE id IN ($in)");
                }
                if ($sids !== [] || $gids !== []) {
                    $cond = [];
                    if ($sids !== []) $cond[] = "student_id IN (" . $int($sids) . ")";
                    if ($gids !== []) $cond[] = "guardian_id IN (" . $int($gids) . ")";
                    $drop("DELETE FROM guardian_students WHERE " . implode(' OR ', $cond));
                }
                $drop("DELETE FROM notifications WHERE user_id = ?", [$uid]);
                $drop("DELETE FROM chat_history WHERE user_id = ?", [$uid]);
                // Own profile rows only — linked children's own records survive.
                $drop("DELETE FROM guardians WHERE user_id = ?", [$uid]);
                $drop("DELETE FROM teachers WHERE user_id = ?", [$uid]);
                $drop("DELETE FROM students WHERE user_id = ?", [$uid]);
                // Access state wiped.
                $drop("DELETE FROM user_mfa WHERE user_id = ?", [$uid]);
                $drop("DELETE FROM mfa_recovery_codes WHERE user_id = ?", [$uid]);
                $drop("DELETE FROM trusted_devices WHERE user_id = ?", [$uid]);
                $drop("DELETE FROM user_sessions WHERE user_id = ?", [$uid]);
                $drop("UPDATE pending_logins SET used_at = NOW(), token_hash = NULL WHERE user_id = ? AND used_at IS NULL", [$uid]);
                // The login itself survives, reset to just-created state.
                // Direct run (NOT the swallowing $drop): if this fails the
                // whole transaction rolls back — never wipe data but keep
                // the old password.
                Database::run(
                    "UPDATE users SET password_hash = ?, status = 'pending_verification', is_active = 1,
                            is_email_verified = 0, verified_at = NULL, verification_token = NULL, token_expiry = NULL,
                            otp_code_hash = NULL, otp_expiry = NULL, otp_attempts = 0,
                            reset_token = NULL, reset_token_expiry = NULL, remember_token = NULL,
                            failed_login_attempts = 0, locked_until = NULL,
                            last_login_at = NULL, last_login_ip = NULL, avatar_path = NULL
                      WHERE id = ?",
                    [password_hash($temp, PASSWORD_DEFAULT), $uid]
                );
            });
            // Stash uploads (restorable) instead of deleting.
            $stashedFiles = 0;
            try {
                [$stashedFiles] = \App\Core\AccountBackup::stashFiles($backupId, $files);
            } catch (Throwable $e) {
                $stashedFiles = 0;
            }
            Database::insert('audit_logs', ['actor_user_id' => (int) Auth::id(), 'action' => 'user.factory_reset', 'entity_type' => 'users', 'entity_id' => $uid, 'new_values_json' => json_encode(['apps' => count($appIds), 'payments' => count($payIds), 'backup_id' => $backupId]), 'ip_address' => client_ip()]);
            try {
                $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                edu_mailer()->send(
                    (string) $u['email'], $name !== '' ? $name : (string) $u['email'],
                    'Your EduPortal account was reset to a fresh state',
                    edu_email_shell('Fresh start', '<p style="color:#334155;">Hi <strong>' . e($name !== '' ? $name : 'there') . '</strong>,</p>'
                        . '<p style="color:#334155;">An administrator reset your account to a fresh state (like a newly created account): all enrollments, payments, grades, permits, clearances, documents, and classes were cleared.</p>'
                        . '<p style="color:#334155;">Temporary password: <strong style="font-size:16px">' . e($temp) . '</strong><br>First verify your email, then sign in and change it in Profile → Change Password.</p>'),
                    'security_factory_reset'
                );
            } catch (Throwable $e) {
            }
            set_flash('success', 'Account #' . $uid . ' (' . $u['email'] . ') reset to fresh: ' . count($appIds) . ' enrollment(s), ' . count($payIds) . ' payment(s) cleared; login kept with temporary password ' . $temp . '. Backup #' . $backupId . ' (' . $stashedFiles . ' file(s) stashed) covers mistakes.');
        } catch (Throwable $e) {
            storage_log('factory reset failed #' . $uid . ': ' . $e->getMessage());
            set_flash('error', 'Factory reset failed: ' . $e->getMessage());
        }
        redirect('/admin/users');
    }

    /**
     * Admin security overview for one account: MFA status, trusted devices,
     * active sessions, recent security events. Read-only except revocation
     * actions below. NEVER exposes secrets, codes, or raw tokens.
     */
    public function security(array $params = []): void
    {
        Auth::requireRole('admin');
        $id = (int)($params['id'] ?? 0);
        $u = Database::one("SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? LIMIT 1", [$id]);
        if (!$u) { set_flash('error','Account not found.'); redirect('/admin/users'); }
        $mfa = null;
        try { $mfa = Database::one("SELECT is_enabled, verified_at, failed_attempts, locked_until FROM user_mfa WHERE user_id=? LIMIT 1", [$id]); } catch (Throwable $e) {}
        $devices = [];
        try { $devices = Database::all("SELECT id, device_name, ip_address, created_at, last_used_at, expires_at, revoked_at, mfa_exempt_until FROM trusted_devices WHERE user_id=? ORDER BY last_used_at DESC LIMIT 20", [$id]); } catch (Throwable $e) {}
        $sessions = [];
        try { $sessions = Database::all("SELECT id, ip_address, user_agent, created_at, last_activity, revoked_at FROM user_sessions WHERE user_id=? ORDER BY last_activity DESC LIMIT 20", [$id]); } catch (Throwable $e) {}
        $events = [];
        try {
            $events = Database::all(
                "SELECT action, created_at, ip_address FROM audit_logs WHERE actor_user_id=? AND (action LIKE 'mfa.%' OR action LIKE 'login%' OR action LIKE 'device.%' OR action LIKE 'session.%' OR action LIKE 'password.%' OR action='logout') ORDER BY id DESC LIMIT 30",
                [$id]
            );
        } catch (Throwable $e) {}
        $this->view('admin/users/security', ['title'=>'Account security','target'=>$u,'mfa'=>$mfa,'devices'=>$devices,'sessions'=>$sessions,'events'=>$events]);
    }

    /** Revoke ALL trusted devices of a user (user must re-approve). Logged. */
    public function revokeDevices(array $params = []): void
    {
        Csrf::guard(); Auth::requireRole('admin');
        $id = (int)($params['id'] ?? 0);
        if ($id === (int)Auth::id()) { set_flash('error','Use Security Settings for your own devices.'); redirect('/admin/users'); }
        try {
            Database::run("UPDATE trusted_devices SET revoked_at=NOW() WHERE user_id=? AND revoked_at IS NULL", [$id]);
            Database::insert('audit_logs',['actor_user_id'=>(int)Auth::id(),'action'=>'admin.device.revoke_all','entity_type'=>'users','entity_id'=>$id,'ip_address'=>client_ip()]);
            set_flash('success','All trusted devices revoked for account #'.$id.'.');
        } catch (Throwable $e) { set_flash('error','Revocation failed: '.$e->getMessage()); }
        redirect('/admin/users/'.$id.'/security');
    }

    /** Revoke ALL sessions of a user (kicks them out; they sign in again). Logged. */
    public function revokeSessions(array $params = []): void
    {
        Csrf::guard(); Auth::requireRole('admin');
        $id = (int)($params['id'] ?? 0);
        if ($id === (int)Auth::id()) { set_flash('error','Use Security Settings for your own sessions.'); redirect('/admin/users'); }
        try {
            Database::run("UPDATE user_sessions SET revoked_at=NOW() WHERE user_id=? AND revoked_at IS NULL", [$id]);
            Database::insert('audit_logs',['actor_user_id'=>(int)Auth::id(),'action'=>'admin.session.revoke_all','entity_type'=>'users','entity_id'=>$id,'ip_address'=>client_ip()]);
            set_flash('success','All sessions revoked for account #'.$id.'.');
        } catch (Throwable $e) { set_flash('error','Revocation failed: '.$e->getMessage()); }
        redirect('/admin/users/'.$id.'/security');
    }

    /**
     * Account safety backups: automatic snapshots taken before reset/remove.
     * Restore replays with id-remap; files stashed on remove come back too.
     */
    public function backups(array $params = []): void
    {
        Auth::requireRole('admin');
        $rows = [];
        try {
            $rows = Database::all(
                "SELECT b.*, CONCAT(u.first_name,' ',u.last_name) AS admin_name
                   FROM account_backups b LEFT JOIN users u ON u.id = b.created_by
                  ORDER BY b.id DESC LIMIT 100"
            );
        } catch (Throwable $e) {
            set_flash('error', 'Backup tables are missing — import database/patch_account_backups.sql first.');
        }
        $this->view('admin/backups/index', ['title' => 'Account Backups', 'rows' => $rows]);
    }

    public function restoreBackup(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');
        $id = (int) ($params['id'] ?? 0);
        if (!class_exists(\App\Core\AccountBackup::class)) {
            set_flash('error', 'Backup engine unavailable.');
            redirect('/admin/backups');
        }
        $result = \App\Core\AccountBackup::restore($id, (int) Auth::id());
        if (!empty($result['error'])) {
            set_flash('error', 'Restore failed: ' . $result['error']);
            redirect('/admin/backups');
        }
        $parts = [];
        foreach (($result['restored'] ?? []) as $t => $n) $parts[] = $t . '×' . $n;
        $msg = 'Backup #' . $id . ' restored' . ($parts !== [] ? ' (' . implode(', ', $parts) . ')' : '') . '.';
        if (!empty($result['skipped'])) {
            $sk = [];
            foreach ($result['skipped'] as $t => $n) $sk[] = $t . '×' . $n;
            $msg .= ' Skipped (already present or orphaned): ' . implode(', ', $sk) . '.';
        }
        $f = $result['files'] ?? ['moved' => 0, 'missing' => 0];
        $msg .= ' Files: ' . (int) $f['moved'] . ' restored' . ((int) $f['missing'] > 0 ? ', ' . (int) $f['missing'] . ' unavailable (already replaced or cleaned)' : '') . '.';
        if (!empty($result['user_id'])) $msg .= ' Account ID now #' . (int) $result['user_id'] . '.';
        set_flash('success', $msg);
        redirect('/admin/backups');
    }

    public function deleteBackup(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');
        $id = (int) ($params['id'] ?? 0);
        if (class_exists(\App\Core\AccountBackup::class) && \App\Core\AccountBackup::delete($id)) {
            Database::insert('audit_logs', ['actor_user_id' => (int) Auth::id(), 'action' => 'user.backup_deleted', 'entity_type' => 'account_backups', 'entity_id' => $id, 'ip_address' => client_ip()]);
            set_flash('success', 'Backup #' . $id . ' deleted (snapshot + stashed files).');
        } else {
            set_flash('error', 'Backup not found.');
        }
        redirect('/admin/backups');
    }

    /**
     * Authorized Student Number correction (admin/registrar only).
     * Students can NEVER reach this — routes enforce admin/registrar roles,
     * the value is validated server-side, and every change is audit-logged.
     */
    public function correctStudentNo(array $params = []): void
    {
        Csrf::guard(); Auth::requireRole('admin','registrar');
        $studentId=(int)($params['id']??0);
        $newNo=trim((string)($_POST['student_no'] ?? ''));
        $reason=trim((string)($_POST['reason'] ?? ''));
        if (!preg_match('/^\d{7,10}$/', $newNo)) { set_flash('error','Student Number must be 7-10 digits.'); redirect('/registrar/students/'.$studentId); }
        if (mb_strlen($reason) < 5) { set_flash('error','A correction reason (min 5 characters) is required — it is audit-logged.'); redirect('/registrar/students/'.$studentId); }
        $stu=Database::one("SELECT id, student_no FROM students WHERE id=? LIMIT 1",[$studentId]);
        if(!$stu){ set_flash('error','Student not found.'); redirect('/registrar/students'); }
        $oldNo=(string)($stu['student_no'] ?? '');
        if ($oldNo === $newNo) { set_flash('info','No change — number already '.$newNo.'.'); redirect('/registrar/students/'.$studentId); }
        $clash=Database::one("SELECT id FROM students WHERE student_no=? AND id<>? LIMIT 1",[$newNo,$studentId]);
        if($clash){ set_flash('error','Number '.$newNo.' is already assigned to another student.'); redirect('/registrar/students/'.$studentId); }
        try {
            Database::transaction(function() use ($studentId,$oldNo,$newNo,$reason) {
                Database::run("UPDATE students SET student_no=? WHERE id=?",[$newNo,$studentId]);
                try {
                    Database::insert('student_no_changes',[
                        'student_id'=>$studentId,'old_no'=>$oldNo !== '' ? $oldNo : null,
                        'new_no'=>$newNo,'changed_by'=>(int)Auth::id(),'reason'=>mb_substr($reason,0,500),
                    ]);
                } catch (Throwable $e) {}
                Database::insert('audit_logs',[
                    'actor_user_id'=>(int)Auth::id(),'action'=>'student_no.corrected',
                    'entity_type'=>'students','entity_id'=>$studentId,
                    'old_values_json'=>json_encode(['student_no'=>$oldNo]),
                    'new_values_json'=>json_encode(['student_no'=>$newNo,'reason'=>mb_substr($reason,0,200)]),
                    'ip_address'=>client_ip(),
                ]);
            });
            set_flash('success','Student Number corrected: '.($oldNo !== '' ? $oldNo.' → ' : '').$newNo.' (logged).');
        } catch (Throwable $e) { storage_log('student_no correct failed: '.$e->getMessage()); set_flash('error','Correction failed: '.$e->getMessage()); }
        redirect('/registrar/students/'.$studentId);
    }
}
