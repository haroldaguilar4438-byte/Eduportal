<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * PDO singleton + tiny query toolkit.
 *
 * RULES OF THE HOUSE:
 *   - Every query goes through prepared statements with bound parameters.
 *     No string interpolation of user input, ever.
 *   - Table/column identifiers passed to insert()/update() must come from
 *     internal code (they are validated against a strict pattern anyway).
 */
final class Database
{
    private static ?PDO $pdo = null;

    /** Singleton: no direct instantiation. */
    private function __construct()
    {
    }

    private function __clone()
    {
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
            try {
                self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // Native prepares -> real parameter binding (SQLi-safe).
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                error_log('[DB] connection failed: ' . $e->getMessage());
                throw new RuntimeException('Database connection failed.', 0, $e);
            }
            // Keep MySQL session in Asia/Manila like PHP so TIMESTAMP reads
            // match time(). Hostinger MySQL defaults to UTC, which made
            // every notification look ~8h older than it was.
            // Pin the connection collation to the tables' collation so string
            // comparisons never throw 1267 "illegal mix of collations" when the
            // server default differs (live Hostinger default is general_ci).
            try {
                self::$pdo->exec("SET time_zone = '+08:00'");
                self::$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (Throwable $e) {
            }
        }
        return self::$pdo;
    }

    /* ------------------------------ query API ------------------------------ */

    /** Prepare + execute; returns the executed statement (for rowCount/fetch). */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** First row as associative array or null. */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** All rows. */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** Single scalar value (first column of first row). */
    public static function value(string $sql, array $params = []): mixed
    {
        $val = self::run($sql, $params)->fetchColumn();
        return $val === false ? null : $val;
    }

    public static function exists(string $sql, array $params = []): bool
    {
        return self::value($sql, $params) !== null;
    }

    public static function lastInsertId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Convenience INSERT from an assoc array (internal code only).
     * Returns the new auto-increment id.
     */
    public static function insert(string $table, array $data): int
    {
        self::assertIdentifier($table);
        $cols = array_keys($data);
        foreach ($cols as $c) {
            self::assertIdentifier((string) $c);
        }
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . $placeholders . ')';
        self::run($sql, array_values($data));
        return self::lastInsertId();
    }

    /**
     * Convenience UPDATE. $where is an assoc array of column => value (ANDed).
     * Returns affected row count.
     */
    public static function update(string $table, array $data, array $where): int
    {
        self::assertIdentifier($table);
        $set = [];
        foreach (array_keys($data) as $c) {
            self::assertIdentifier((string) $c);
            $set[] = '`' . $c . '` = ?';
        }
        $cond = [];
        $params = array_values($data);
        foreach ($where as $c => $v) {
            self::assertIdentifier((string) $c);
            $cond[] = '`' . $c . '` = ?';
            $params[] = $v;
        }
        $stmt = self::run(
            'UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . implode(' AND ', $cond),
            $params
        );
        return $stmt->rowCount();
    }

    /** Run $fn inside a transaction; commit on success, roll back on throw. */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /* ------------------------------ internals ------------------------------ */

    private static function assertIdentifier(string $id): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $id)) {
            throw new RuntimeException('Unsafe SQL identifier: ' . $id);
        }
    }
}
