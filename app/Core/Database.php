<?php

declare(strict_types=1);

namespace Khatauat\Core;

use PDO;
use RuntimeException;
use Throwable;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo) return self::$pdo;
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('امتداد pdo_sqlite غير مفعّل على الخادم. فعّل PHP SQLite ثم أعد المحاولة.');
        }
        $path = (string) \config('db_path');
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0770, true);
        self::$pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$pdo->exec('PRAGMA foreign_keys = ON;');
        self::$pdo->exec('PRAGMA busy_timeout = 8000;');
        self::$pdo->exec('PRAGMA journal_mode = WAL;');
        self::$pdo->exec('PRAGMA synchronous = NORMAL;');
        self::$pdo->exec('PRAGMA temp_store = MEMORY;');
        return self::$pdo;
    }

    public static function fetch(string $sql, array $params = []): ?array
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function execute(string $sql, array $params = []): bool
    {
        $stmt = self::connection()->prepare($sql);
        return $stmt->execute($params);
    }

    public static function scalar(string $sql, array $params = []): mixed
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public static function lastInsertId(): int { return (int) self::connection()->lastInsertId(); }

    public static function tableExists(string $table): bool
    {
        return (bool)self::scalar("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1",[$table]);
    }

    public static function immediate(callable $callback): mixed
    {
        $pdo=self::connection();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $result=$callback();
            $pdo->exec('COMMIT');
            return $result;
        } catch (Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (Throwable) {}
            throw $e;
        }
    }
}
