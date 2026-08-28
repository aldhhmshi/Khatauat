<?php

declare(strict_types=1);

namespace Khatauat\Core;

final class Settings
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache !== null) return self::$cache;
        $rows = Database::fetchAll('SELECT key, value FROM settings');
        self::$cache = [];
        foreach ($rows as $row) self::$cache[$row['key']] = $row['value'];
        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function set(string $key, string $value): void
    {
        Database::execute('INSERT INTO settings(key,value,updated_at) VALUES(?,?,CURRENT_TIMESTAMP) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=CURRENT_TIMESTAMP', [$key, $value]);
        self::$cache = null;
    }
}
