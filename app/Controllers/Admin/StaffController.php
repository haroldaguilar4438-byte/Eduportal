<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use App\Models\User;
use Throwable;

/**
 * Admin staff provisioning for Registrar + Accounting (no self-registration).
 */
final class StaffController extends Controller
{
    public function index(array $params = []): void
    {
        Auth::requireRole('admin');
        $staff = Database::all(
            "SELECT u.id, u.email, u.first_name, u.last_name, u.status, u.is_email_verified, u.created_at, r.name AS role_name
               FROM users u JOIN roles r ON r.id = u.role_id
              WHERE r.name IN ('registrar','accounting')
              ORDER BY u.id DESC LIMIT 100"
        );
        $this->view('admin/staff_index', ['title' => 'Registrar & Accounting Accounts', 'staff' => $staff]);
    }

    public function create(array $params = []): void
    {
        Csrf::guard();
        Auth::requireRole('admin');
        $role = trim((string) ($_POST['role'] ?? ''));
        $first = trim((string) ($_POST['first_name'] ?? ''));
        $last = trim((string) ($_POST['last_name'] ?? ''));
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');

        if (!in_array($role, ['registrar', 'accounting'], true)) {
            set_flash('error', 'Role must be registrar or accounting.');
            redirect('/admin/staff');
        }
        if ($first === '' || $last === '') {
            set_flash('error', 'First and last name are required.');
            redirect('/admin/staff');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            set_flash('error', 'Valid email is required.');
            redirect('/admin/staff');
        }
        $pwErrors = Auth::assertPasswordStrength($password);
        if ($pwErrors !== []) {
            set_flash('error', 'Password needs: ' . implode(', ', $pwErrors) . '.');
            redirect('/admin/staff');
        }
        if (User::findByEmail($email) !== null) {
            set_flash('error', 'Email already exists.');
            redirect('/admin/staff');
        }
        try {
            $row = Database::one("SELECT id FROM roles WHERE name = ? LIMIT 1", [$role]);
            if ($row === null) {
                Database::run(
                    "INSERT IGNORE INTO roles (name, description) VALUES (?, ?)",
                    [$role, $role === 'registrar' ? 'Registrar - academic records' : 'Accounting - finance']
                );
                $row = Database::one("SELECT id FROM roles WHERE name = ? LIMIT 1", [$role]);
            }
            if ($row === null) {
                set_flash('error', 'Role not found. Import patch_registrar_accounting.sql first.');
                redirect('/admin/staff');
            }
            $roleId = (int) $row['id'];
            $uid = Database::insert('users', [
                'role_id' => $roleId, 'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'first_name' => $first, 'last_name' => $last,
                'is_email_verified' => 0, 'verified_at' => null,
                'status' => 'pending_verification',
            ]);
            try {
                $token = Auth::issueVerification($uid);
                send_verification_link_email(
                    ['email' => $email, 'first_name' => $first, 'last_name' => $last, 'role_id' => $roleId, 'role_name' => $role, 'id' => $uid],
                    $token
                );
            } catch (Throwable $e) {
                storage_log('staff verify email failed ' . $uid . ': ' . $e->getMessage());
            }
            Database::insert('audit_logs', [
                'actor_user_id' => (int) Auth::id(), 'action' => 'staff.created_' . $role,
                'entity_type' => 'users', 'entity_id' => $uid, 'ip_address' => client_ip(),
            ]);
            set_flash('success', ucfirst($role) . ' account created: ' . $email . ' — verification email sent. They must verify before signing in.');
        } catch (Throwable $e) {
            storage_log('staff create failed: ' . $e->getMessage());
            set_flash('error', 'Failed: ' . $e->getMessage());
        }
        redirect('/admin/staff');
    }
}
