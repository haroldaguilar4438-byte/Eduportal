<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Account safety backups: automatic JSON snapshots taken BEFORE an admin
 * reset (access state) or hard remove (access + academic/finance records),
 * plus stashed upload files. Restore replays with id-remap and reports
 * what came back vs. what was skipped.
 *
 * Scope mirrors Admin\UserController::remove() coverage plus the live
 * access tables. Append-only logs (audit_logs, login_attempts, email_log,
 * notifications, payment_webhook_logs) are never deleted by remove(), so
 * they are never snapshotted either.
 */
final class AccountBackup
{
    /** Tables snapshotted on RESET (access state only). */
    private const TIER_RESET = ['users', 'user_mfa', 'mfa_recovery_codes', 'trusted_devices'];

    /** Extra tables snapshotted on REMOVE (mirrors remove() deletes). */
    private const TIER_REMOVE_EXTRA = [
        'students', 'teachers', 'guardians', 'guardian_students',
        'classroom_members', 'student_class_registrations',
        'enrollment_applications', 'enrollment_documents', 'student_documents',
        'payments', 'receipts', 'student_charges', 'payment_allocations',
        'exam_permits', 'academic_documents', 'clearances', 'clearance_items',
        'clearance_status_logs', 'classroom_task_submissions',
        'assignment_submissions', 'grade_sheet_rows', 'teacher_evaluations',
        'at_risk_flags',
    ];

    /** Restore order (parents before children). */
    private const RESTORE_ORDER = [
        'users', 'students', 'teachers', 'guardians', 'guardian_students',
        'classroom_members', 'student_class_registrations',
        'enrollment_applications', 'student_documents', 'enrollment_documents',
        'payments', 'receipts', 'student_charges', 'payment_allocations',
        'exam_permits', 'academic_documents', 'clearances', 'clearance_items',
        'clearance_status_logs', 'classroom_task_submissions',
        'assignment_submissions', 'grade_sheet_rows', 'teacher_evaluations',
        'at_risk_flags', 'user_mfa', 'mfa_recovery_codes', 'trusted_devices',
    ];

    /**
     * FK remap: table => [column => referenced snapshot table].
     * 'users?' = keep id when that user still exists, else NULL.
     */
    private const REFS = [
        'students' => ['user_id' => 'users?'],
        'teachers' => ['user_id' => 'users?'],
        'guardians' => ['user_id' => 'users?'],
        'guardian_students' => ['guardian_id' => 'guardians', 'student_id' => 'students'],
        'classroom_members' => ['student_id' => 'students'],
        'student_class_registrations' => ['student_id' => 'students'],
        'enrollment_applications' => ['user_id' => 'users?', 'student_id' => 'students'],
        'student_documents' => ['student_id' => 'students', 'application_id' => 'enrollment_applications'],
        'enrollment_documents' => ['application_id' => 'enrollment_applications'],
        'payments' => ['student_id' => 'students', 'enrollment_application_id' => 'enrollment_applications', 'initiated_by' => 'users?'],
        'receipts' => ['payment_id' => 'payments'],
        'student_charges' => ['student_id' => 'students', 'posted_by' => 'users?'],
        'payment_allocations' => ['payment_id' => 'payments', 'charge_id' => 'student_charges'],
        'exam_permits' => ['student_id' => 'students'],
        'academic_documents' => ['student_id' => 'students', 'requested_by' => 'users?', 'processed_by' => 'users?'],
        'clearances' => ['student_id' => 'students'],
        'clearance_items' => ['clearance_id' => 'clearances', 'teacher_id' => 'teachers?'],
        'clearance_status_logs' => ['clearance_id' => 'clearances'],
        'classroom_task_submissions' => ['student_id' => 'students', 'graded_by' => 'users?', 'reviewed_by' => 'users?'],
        'assignment_submissions' => ['student_id' => 'students', 'graded_by' => 'users?'],
        'grade_sheet_rows' => ['student_id' => 'students'],
        'teacher_evaluations' => ['student_id' => 'students'],
        'at_risk_flags' => ['student_id' => 'students'],
        'user_mfa' => [],
        'mfa_recovery_codes' => [],
        'trusted_devices' => [],
    ];

    /* ================================ snapshot ============================= */

    /** Take a snapshot. Returns backup id (0 on failure). */
    public static function snapshot(int $userId, string $action, int $actorId): int
    {
        if (!in_array($action, ['reset', 'remove', 'factory'], true)) return 0;
        try {
            $u = Database::one(
                "SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1",
                [$userId]
            );
            if ($u === null) return 0;
            $tables = $action === 'reset'
                ? self::TIER_RESET
                : array_merge(self::TIER_RESET, self::TIER_REMOVE_EXTRA);
            $data = [];
            $summary = [];
            foreach ($tables as $t) {
                $rows = self::rowsFor($t, $userId);
                $data[$t] = $rows;
                $summary[$t] = count($rows);
            }
            $name = trim(trim((string) ($u['first_name'] ?? '')) . ' ' . trim((string) ($u['last_name'] ?? '')));
            return Database::insert('account_backups', [
                'user_id' => $userId,
                'email' => mb_substr((string) ($u['email'] ?? ''), 0, 191),
                'display_name' => mb_substr($name !== '' ? $name : ('User #' . $userId), 0, 210),
                'role_name' => mb_substr((string) ($u['role_name'] ?? ''), 0, 30),
                'action' => $action,
                'summary_json' => json_encode($summary),
                'snapshot_json' => json_encode($data),
                'created_by' => $actorId > 0 ? $actorId : null,
            ]);
        } catch (Throwable $e) {
            storage_log('backup snapshot failed u=' . $userId . ': ' . $e->getMessage());
            return 0;
        }
    }

    /** Collect rows of one table belonging to the account. */
    private static function rowsFor(string $table, int $userId): array
    {
        $studentIds = null; // lazy
        $studentsOf = static function () use ($userId, &$studentIds): array {
            if ($studentIds === null) {
                try {
                    $studentIds = array_map(
                        static fn($r) => (int) $r['id'],
                        Database::all("SELECT id FROM students WHERE user_id = ? UNION SELECT student_id AS id FROM guardian_students gs JOIN guardians g ON g.id = gs.guardian_id WHERE g.user_id = ?", [$userId, $userId])
                    );
                } catch (Throwable $e) {
                    $studentIds = [];
                }
            }
            return $studentIds;
        };
        $in = static function (array $ids): string {
            return implode(',', array_map('intval', $ids));
        };
        try {
            switch ($table) {
                case 'users':
                    $r = Database::one("SELECT * FROM users WHERE id = ? LIMIT 1", [$userId]);
                    return $r !== null ? [$r] : [];
                case 'user_mfa':
                    $r = Database::one("SELECT * FROM user_mfa WHERE user_id = ? LIMIT 1", [$userId]);
                    return $r !== null ? [$r] : [];
                case 'mfa_recovery_codes':
                    return Database::all("SELECT * FROM mfa_recovery_codes WHERE user_id = ?", [$userId]);
                case 'trusted_devices':
                    return Database::all("SELECT * FROM trusted_devices WHERE user_id = ?", [$userId]);
                case 'students':
                    return Database::all("SELECT * FROM students WHERE user_id = ?", [$userId]);
                case 'teachers':
                    return Database::all("SELECT * FROM teachers WHERE user_id = ?", [$userId]);
                case 'guardians':
                    return Database::all("SELECT * FROM guardians WHERE user_id = ?", [$userId]);
                case 'guardian_students': {
                    $g = Database::one("SELECT id FROM guardians WHERE user_id = ? LIMIT 1", [$userId]);
                    if ($g === null) return [];
                    return Database::all("SELECT * FROM guardian_students WHERE guardian_id = ?", [(int) $g['id']]);
                }
                case 'enrollment_applications':
                    return Database::all("SELECT * FROM enrollment_applications WHERE user_id = ?", [$userId]);
                case 'payments': {
                    $sids = $studentsOf();
                    $appIds = array_map(
                        static fn($r) => (int) $r['id'],
                        Database::all("SELECT id FROM enrollment_applications WHERE user_id = ?", [$userId])
                    );
                    $cond = [];
                    if ($sids !== []) $cond[] = "student_id IN (" . $in($sids) . ")";
                    if ($appIds !== []) $cond[] = "enrollment_application_id IN (" . $in($appIds) . ")";
                    if ($cond === []) return [];
                    return Database::all("SELECT * FROM payments WHERE " . implode(' OR ', $cond));
                }
                default: {
                    // Student-linked tables (classroom_members, grades rows,
                    // permits, clearances, documents, evaluations, flags...).
                    $sids = $studentsOf();
                    if ($sids === []) return [];
                    $col = $table === 'guardian_students' ? 'guardian_id' : 'student_id';
                    if ($table === 'clearance_items' || $table === 'clearance_status_logs') {
                        $clr = Database::all("SELECT id FROM clearances WHERE student_id IN (" . $in($sids) . ")", []);
                        $cids = array_map(static fn($r) => (int) $r['id'], $clr);
                        if ($cids === []) return [];
                        $fk = $table === 'clearance_items' ? 'clearance_id' : 'clearance_id';
                        return Database::all("SELECT * FROM `{$table}` WHERE `{$fk}` IN (" . $in($cids) . ")");
                    }
                    if ($table === 'enrollment_documents') {
                        $appIds = array_map(
                            static fn($r) => (int) $r['id'],
                            Database::all("SELECT id FROM enrollment_applications WHERE user_id = ?", [$userId])
                        );
                        if ($appIds === []) return [];
                        return Database::all("SELECT * FROM enrollment_documents WHERE application_id IN (" . $in($appIds) . ")");
                    }
                    if ($table === 'receipts') {
                        return [];
                    }
                    if ($table === 'payment_allocations') {
                        return [];
                    }
                    return Database::all("SELECT * FROM `{$table}` WHERE `{$col}` IN (" . $in($sids) . ")");
                }
            }
        } catch (Throwable $e) {
            return [];
        }
    }

    /* ============================== file stash ============================= */

    public static function stashDir(int $backupId): string
    {
        return rtrim(STORAGE_PATH, '/\\') . DIRECTORY_SEPARATOR . 'account_backups' . DIRECTORY_SEPARATOR . $backupId;
    }

    /**
     * Move upload files into the backup stash (instead of deleting).
     * Returns [moved, missing]. Paths are jailed to the uploads dir.
     */
    public static function stashFiles(int $backupId, array $rels): array
    {
        $moved = 0;
        $missing = 0;
        $dest = self::stashDir($backupId);
        if (!is_dir($dest)) @mkdir($dest, 0775, true);
        $base = realpath(ROOT_PATH . '/public_html/uploads');
        foreach ($rels as $rel) {
            $rel = ltrim(str_replace('\\', '/', (string) $rel), '/');
            if ($rel === '' || str_contains($rel, '..')) {
                continue;
            }
            $full = ROOT_PATH . '/public_html/' . $rel;
            $real = realpath($full);
            if ($real === false || $base === false || !str_starts_with($real, $base) || !is_file($real)) {
                $missing++;
                continue;
            }
            $target = $dest . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            @mkdir(dirname($target), 0775, true);
            if (@rename($real, $target)) {
                $moved++;
            } else {
                $missing++;
            }
        }
        return [$moved, $missing];
    }

    /* ================================ restore ============================== */

    /**
     * Replay a snapshot. Returns ['restored'=>[table=>n],'skipped'=>[table=>n],
     * 'files'=>['moved'=>x,'missing'=>y],'user_id'=>newOrSameId,'error'=>?].
     */
    public static function restore(int $backupId, int $actorId): array
    {
        $out = ['restored' => [], 'skipped' => [], 'files' => ['moved' => 0, 'missing' => 0], 'user_id' => 0, 'error' => null];
        try {
            $b = Database::one("SELECT * FROM account_backups WHERE id = ? LIMIT 1", [$backupId]);
        } catch (Throwable $e) {
            $out['error'] = 'Backup not found.';
            return $out;
        }
        if ($b === null) {
            $out['error'] = 'Backup not found.';
            return $out;
        }
        $data = json_decode((string) $b['snapshot_json'], true);
        if (!is_array($data)) {
            $out['error'] = 'Backup data is corrupted.';
            return $out;
        }
        $map = [];
        try {
            Database::transaction(function () use ($data, &$map, &$out, $b) {
                // Pre-check: email collision with a DIFFERENT live account.
                $uRows = $data['users'] ?? [];
                if ($uRows !== []) {
                    $live = Database::one("SELECT id FROM users WHERE email = ? LIMIT 1", [(string) ($uRows[0]['email'] ?? '')]);
                    if ($live !== null && (int) $live['id'] !== (int) $uRows[0]['id']) {
                        throw new \RuntimeException('Email ' . ($uRows[0]['email'] ?? '') . ' now belongs to account #' . $live['id'] . ' — restore aborted, nothing changed.');
                    }
                }
                foreach (self::RESTORE_ORDER as $table) {
                    $rows = $data[$table] ?? null;
                    if (!is_array($rows) || $rows === []) continue;
                    foreach ($rows as $row) {
                        if (self::insertRow($table, (array) $row, $map)) {
                            $out['restored'][$table] = ($out['restored'][$table] ?? 0) + 1;
                        } else {
                            $out['skipped'][$table] = ($out['skipped'][$table] ?? 0) + 1;
                        }
                    }
                }
                // Deferred links that point forward in restore order.
                self::relinkApplications($map, is_array($data) ? $data : []);
                if (isset($map['users'])) {
                    $first = reset($map['users']);
                    $out['user_id'] = (int) $first;
                } else {
                    $out['user_id'] = (int) ($b['user_id'] ?? 0);
                }
            });
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
            return $out;
        }
        // Files back (outside the DB transaction).
        try {
            $out['files'] = self::unstashFiles($backupId);
        } catch (Throwable $e) {
        }
        try {
            Database::insert('audit_logs', [
                'actor_user_id' => $actorId > 0 ? $actorId : null, 'action' => 'user.backup_restored',
                'entity_type' => 'account_backups', 'entity_id' => $backupId,
                'new_values_json' => json_encode(['restored' => $out['restored'], 'skipped' => $out['skipped']]),
                'ip_address' => client_ip(),
            ]);
        } catch (Throwable $e) {
        }
        return $out;
    }

    /** Insert one snapshot row with id-remap. Returns bool success. */
    private static function insertRow(string $table, array $row, array &$map): bool
    {
        try {
            $refs = self::REFS[$table] ?? [];
            $new = $row;
            // Access tables bind strictly to the remapped user (never a live
            // stranger reusing the old id).
            if (in_array($table, ['mfa_recovery_codes', 'trusted_devices'], true)) {
                $oldUid = (int) ($row['user_id'] ?? 0);
                if (!isset($map['users'][$oldUid])) return false;
                $new['user_id'] = $map['users'][$oldUid];
            }
            foreach ($refs as $col => $target) {
                if (!array_key_exists($col, $new)) continue;
                $optionalUser = str_ends_with($target, '?');
                $target = rtrim($target, '?');
                $old = $new[$col];
                if ($old === null || $old === '') {
                    $new[$col] = null;
                    continue;
                }
                if (isset($map[$target][(int) $old])) {
                    $new[$col] = $map[$target][(int) $old];
                } elseif ($target === 'users') {
                    // External user ref: keep when alive, else NULL (FK-safe).
                    $alive = Database::one("SELECT id FROM users WHERE id = ? LIMIT 1", [(int) $old]);
                    $new[$col] = $alive !== null ? (int) $old : null;
                } elseif ($optionalUser) {
                    $new[$col] = null;
                } else {
                    return false; // required parent not in snapshot — skip row
                }
            }
            // Forward references (restored later in dependency order):
            // rebuilt in relinkApplications() from the snapshot.
            if ($table === 'enrollment_applications') {
                $new['payment_id'] = null;
                $new['clearance_id'] = null;
            }
            // user_mfa's PK is user_id: must resolve through the user remap
            // (never the stale id — it could belong to someone else now).
            if ($table === 'user_mfa') {
                $oldUid = (int) ($row['user_id'] ?? 0);
                if (!isset($map['users'][$oldUid])) return false;
                $uid = (int) $map['users'][$oldUid];
                $exists = Database::one("SELECT user_id FROM user_mfa WHERE user_id = ? LIMIT 1", [$uid]);
                if ($exists !== null) {
                    Database::run(
                        "UPDATE user_mfa SET secret = ?, is_enabled = ?, failed_attempts = ?, locked_until = ?, verified_at = ? WHERE user_id = ?",
                        [$new['secret'] ?? '', (int) ($new['is_enabled'] ?? 0), 0, null, $new['verified_at'] ?? null, $uid]
                    );
                } else {
                    Database::insert('user_mfa', [
                        'user_id' => $uid, 'secret' => $new['secret'] ?? '',
                        'is_enabled' => (int) ($new['is_enabled'] ?? 0),
                        'verified_at' => $new['verified_at'] ?? null,
                    ]);
                }
                return true;
            }
            $oldId = isset($new['id']) ? (int) $new['id'] : 0;
            if ($table === 'users') {
                unset($new['role_name']); // joined alias, not a column
                // Never resurrect over a different live account (checked above);
                // same-id slot free → keep id (links stay identical).
                if ($oldId > 0) {
                    $taken = Database::one("SELECT id FROM users WHERE id = ? LIMIT 1", [$oldId]);
                    if ($taken === null) {
                        $new['id'] = $oldId;
                        $new['remember_token'] = null;
                        Database::insert('users', $new);
                        $map['users'][$oldId] = $oldId;
                        return true;
                    }
                }
                // Slot taken by the SAME email (reset-then-restore): reuse it.
                $same = Database::one("SELECT id FROM users WHERE email = ? LIMIT 1", [(string) ($new['email'] ?? '')]);
                if ($same !== null) {
                    $map['users'][$oldId] = (int) $same['id'];
                    // Restore the backed-up credentials onto the live row.
                    Database::run(
                        "UPDATE users SET password_hash = ?, status = ?, is_active = ?, is_email_verified = ?, failed_login_attempts = 0, locked_until = NULL, remember_token = NULL WHERE id = ?",
                        [(string) ($new['password_hash'] ?? ''), (string) ($new['status'] ?? 'active'), (int) ($new['is_active'] ?? 1), (int) ($new['is_email_verified'] ?? 0), (int) $same['id']]
                    );
                    return true;
                }
                unset($new['id']);
                $new['remember_token'] = null;
                $nid = Database::insert('users', $new);
                $map['users'][$oldId] = $nid;
                return true;
            }
            if ($oldId > 0) {
                // Keep original ids when free (stable cross-links). When the
                // slot is taken, adopt it ONLY if it is byte-identical to
                // what we would insert (second restore of the same backup) —
                // otherwise skip instead of duplicating or clobbering.
                try {
                    $taken = Database::one("SELECT * FROM `{$table}` WHERE `id` = ? LIMIT 1", [$oldId]);
                } catch (Throwable $e) {
                    return false;
                }
                if ($taken === null) {
                    $new['id'] = $oldId;
                    try {
                        Database::insert($table, $new);
                    } catch (Throwable $e) {
                        return false;
                    }
                    $map[$table][$oldId] = $oldId;
                    return true;
                }
                $ignore = ['id' => 1, 'created_at' => 1, 'updated_at' => 1];
                $same = true;
                foreach ($new as $k => $v) {
                    if (isset($ignore[$k]) || !array_key_exists($k, $taken)) continue;
                    if ((string) ($taken[$k] ?? '') !== (string) ($v ?? '')) {
                        $same = false;
                        break;
                    }
                }
                if ($same) {
                    $map[$table][$oldId] = $oldId;
                    return true;
                }
                return false;
            }
            unset($new['id']);
            try {
                $nid = Database::insert($table, $new);
            } catch (Throwable $e) {
                return false;
            }
            $map[$table][$oldId] = $nid;
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Second pass: applications.payment_id / clearance_id point forward to
     * remapped rows (resolved from the snapshot, not the nulled inserts).
     */
    private static function relinkApplications(array $map, array $data): void
    {
        if (empty($map['enrollment_applications'])) return;
        try {
            $byOldApp = [];
            foreach (($data['enrollment_applications'] ?? []) as $r) {
                $byOldApp[(int) ($r['id'] ?? 0)] = $r;
            }
            // payments → applications (payments restored with remapped app id already via REFS).
            // applications.payment_id: point at the remapped payment that references the app.
            foreach ($map['enrollment_applications'] as $oldApp => $newApp) {
                try {
                    $p = Database::one(
                        "SELECT id FROM payments WHERE enrollment_application_id = ? ORDER BY id LIMIT 1",
                        [$newApp]
                    );
                    if ($p !== null) {
                        Database::run("UPDATE enrollment_applications SET payment_id = ? WHERE id = ? AND payment_id IS NULL", [(int) $p['id'], $newApp]);
                    }
                } catch (Throwable $e) {
                }
                try {
                    $oldClr = (int) (($byOldApp[$oldApp]['clearance_id'] ?? null) ?? 0);
                    if ($oldClr > 0 && isset($map['clearances'][$oldClr])) {
                        Database::run("UPDATE enrollment_applications SET clearance_id = ? WHERE id = ?", [(int) $map['clearances'][$oldClr], $newApp]);
                    }
                } catch (Throwable $e) {
                }
            }
        } catch (Throwable $e) {
        }
    }

    /** Move stashed files back to public uploads. */
    private static function unstashFiles(int $backupId): array
    {
        $moved = 0;
        $missing = 0;
        $dest = self::stashDir($backupId);
        if (!is_dir($dest)) return ['moved' => 0, 'missing' => 0];
        $base = realpath(ROOT_PATH . '/public_html/uploads');
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dest, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $rel = substr($file->getPathname(), strlen($dest) + 1);
            $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
            if ($rel === '' || str_contains($rel, '..')) {
                $missing++;
                continue;
            }
            $target = ROOT_PATH . '/public_html/uploads/' . $rel;
            @mkdir(dirname($target), 0775, true);
            if (is_file($target)) {
                $missing++;
                continue; // never overwrite live files
            }
            if (@rename($file->getPathname(), $target)) {
                $moved++;
            } else {
                $missing++;
            }
        }
        return ['moved' => $moved, 'missing' => $missing];
    }

    public static function summary(int $backupId): ?array
    {
        try {
            $b = Database::one("SELECT *, (SELECT CONCAT(first_name,' ',last_name) FROM users WHERE id = account_backups.created_by LIMIT 1) AS admin_name FROM account_backups WHERE id = ? LIMIT 1", [$backupId]);
            return $b;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function delete(int $backupId): bool
    {
        try {
            $b = Database::one("SELECT * FROM account_backups WHERE id = ? LIMIT 1", [$backupId]);
            if ($b === null) return false;
            // Remove stashed files with the record.
            $dir = self::stashDir($backupId);
            if (is_dir($dir)) {
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($it as $f) {
                    if ($f->isDir()) @rmdir($f->getPathname());
                    else @unlink($f->getPathname());
                }
                @rmdir($dir);
            }
            Database::run("DELETE FROM account_backups WHERE id = ?", [$backupId]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
