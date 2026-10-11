<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * User model - persistence for the users table.
 * Authentication logic itself lives in App\Core\Auth.
 */
final class User
{
    public const ROLE_ADMIN   = 1;
    public const ROLE_TEACHER = 2;
    public const ROLE_STUDENT = 3;
    public const ROLE_PARENT  = 4;
    public const ROLE_REGISTRAR = 5;
    public const ROLE_ACCOUNTING = 6;

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);
    }

    public static function findByEmail(string $email): ?array
    {
        return Database::one(
            'SELECT * FROM users WHERE email = ? LIMIT 1',
            [mb_strtolower(trim($email))]
        );
    }

    /**
     * Create a portal account (self-registration: student or parent only;
     * teachers/admins are created from the admin panel).
     *
     * @param array{first_name:string,last_name:string,middle_name?:?string,
     *              suffix?:?string,email:string,phone?:?string,password:string,
     *              terms_accepted_at?:?string,
     *              role:'student'|'parent'} $d
     * @return int new user id
     */
    public static function register(array $d): int
    {
        $roleId = $d['role'] === 'parent' ? self::ROLE_PARENT : self::ROLE_STUDENT;

        $row = [
            'role_id'       => $roleId,
            'email'         => mb_strtolower($d['email']),
            'password_hash' => password_hash($d['password'], PASSWORD_DEFAULT),
            'first_name'    => $d['first_name'],
            'middle_name'   => $d['middle_name'] ?? null,
            'last_name'     => $d['last_name'],
            'suffix'        => $d['suffix'] ?? null,
            'phone'         => $d['phone'] ?? null,
            'status'        => 'pending_verification',
        ];
        if (!empty($d['terms_accepted_at'])) {
            $row['terms_accepted_at'] = $d['terms_accepted_at'];
        }
        try {
            return Database::insert('users', $row);
        } catch (\Throwable $e) {
            // Mixed deploy state: column arrives with the migration.
            if (!empty($row['terms_accepted_at']) && str_contains($e->getMessage(), 'terms_accepted_at')) {
                unset($row['terms_accepted_at']);
                return Database::insert('users', $row);
            }
            throw $e;
        }
    }
}