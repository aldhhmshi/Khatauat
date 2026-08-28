<?php

declare(strict_types=1);

namespace Khatauat\Services;

use Khatauat\Core\Database;

final class CalculatorService
{
    public static function published(): array
    {
        try {
            return Database::fetchAll(
                "SELECT * FROM calculator_definitions WHERE status='published' ORDER BY sort_order ASC, name ASC"
            );
        } catch (\Throwable) {
            return [];
        }
    }

    public static function find(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') return null;
        try {
            $row = Database::fetch(
                "SELECT * FROM calculator_definitions WHERE slug=? AND status='published' LIMIT 1",
                [$slug]
            );
            return $row ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function categoryLabel(string $key): string
    {
        return match ($key) {
            'work' => 'العمل والرواتب',
            'tax' => 'الزكاة والضريبة',
            'finance' => 'التمويل والنسب',
            'dates' => 'التواريخ والمدة',
            'business' => 'الأعمال والتكاليف',
            'utilities' => 'الاستهلاك والتخطيط',
            default => 'أدوات عامة',
        };
    }
}
