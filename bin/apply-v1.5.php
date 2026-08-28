<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Khatauat\Core\Database;
use Khatauat\Core\Settings;

$dbPath = (string) config('db_path');
if (is_file($dbPath)) {
    $backup = $dbPath . '.pre-v1.5-' . date('YmdHis') . '.bak';
    if (copy($dbPath, $backup)) echo "BACKUP database {$backup}\n";
}

function addColumnIfMissingV15(string $table, string $column, string $definition): void
{
    $cols = Database::fetchAll('PRAGMA table_info(' . $table . ')');
    foreach ($cols as $col) {
        if (($col['name'] ?? '') === $column) {
            echo "OK {$table}.{$column}\n";
            return;
        }
    }
    Database::connection()->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    echo "ADDED {$table}.{$column}\n";
}

// Cumulative safety for the recent AI-draft workflow patches.
addColumnIfMissingV15('ai_drafts', 'structured_json', 'TEXT');
addColumnIfMissingV15('ai_drafts', 'provider', 'TEXT');
addColumnIfMissingV15('ai_drafts', 'error_detail', 'TEXT');
addColumnIfMissingV15('articles', 'source_urls', "TEXT NOT NULL DEFAULT ''");
addColumnIfMissingV15('articles', 'verification_notes', "TEXT NOT NULL DEFAULT ''");
addColumnIfMissingV15('articles', 'verified_at', 'TEXT');
addColumnIfMissingV15('articles', 'verified_by', 'TEXT');
addColumnIfMissingV15('articles', 'ai_draft_id', 'INTEGER');

$defaults = [
    'site_name' => (string) config('name', 'خطوات'),
    'brand_subtitle' => 'دليل إجرائي مترابط',
    'site_font' => 'ibm_plex',
    'theme_brand' => '#0d4b46',
    'theme_brand_2' => '#16766e',
    'theme_accent' => '#d7f46b',
    'theme_background' => '#fbfdfc',
    'theme_surface' => '#ffffff',
    'theme_text' => '#0d2020',
    'theme_muted' => '#627170',
    'site_notice_enabled' => '1',
    'top_banner_enabled' => '0',
];
foreach ($defaults as $key => $value) {
    if (Settings::get($key, null) === null) Settings::set($key, $value);
}

$projectRoot = dirname(__DIR__);
foreach ([$projectRoot . '/public/uploads', $projectRoot . '/public/uploads/branding', $projectRoot . '/public/uploads/banners'] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "ERROR cannot create {$dir}\n");
        exit(1);
    }
}

// The user's Hostinger layout keeps the application under /kh/khatauat_php
// while the public front controller/assets may live in /public_html.
$domainRoot = dirname(dirname($projectRoot));
$publicHtml = $domainRoot . '/public_html';
$deployedIndex = $publicHtml . '/index.php';

$routes = [
    "$" . "router->get('/admin/appearance', [AdminController::class, 'appearance']);",
    "$" . "router->post('/admin/appearance', [AdminController::class, 'appearance']);",
    "$" . "router->post('/admin/banner/save', [AdminController::class, 'bannerSave']);",
    "$" . "router->post('/admin/banner/delete', [AdminController::class, 'bannerDelete']);",
    "$" . "router->post('/admin/ai-draft/to-article', [AdminController::class, 'aiDraftToArticle']);",
    "$" . "router->post('/admin/ai-draft/publish', [AdminController::class, 'aiDraftPublish']);",
    "$" . "router->post('/admin/ai-draft/delete', [AdminController::class, 'aiDraftDelete']);",
    "$" . "router->post('/admin/article/status', [AdminController::class, 'articleStatus']);",
    "$" . "router->post('/admin/article/delete', [AdminController::class, 'articleDelete']);",
];
if (is_file($deployedIndex)) {
    $index = file_get_contents($deployedIndex);
    if ($index !== false) {
        $original = $index;
        $anchors = [
            '/admin/appearance' => "$" . "router->post('/admin/update/publish', [AdminController::class, 'updatePublish']);",
            '/admin/banner/save' => "$" . "router->post('/admin/ad/status', [AdminController::class, 'adStatus']);",
            '/admin/banner/delete' => "$" . "router->post('/admin/banner/save', [AdminController::class, 'bannerSave']);",
            '/admin/ai-draft/to-article' => "$" . "router->post('/admin/ai-generate', [AdminController::class, 'aiGenerate']);",
            '/admin/ai-draft/publish' => "$" . "router->post('/admin/ai-draft/to-article', [AdminController::class, 'aiDraftToArticle']);",
            '/admin/ai-draft/delete' => "$" . "router->post('/admin/ai-draft/publish', [AdminController::class, 'aiDraftPublish']);",
            '/admin/article/status' => "$" . "router->post('/admin/ai-draft/delete', [AdminController::class, 'aiDraftDelete']);",
            '/admin/article/delete' => "$" . "router->post('/admin/article/status', [AdminController::class, 'articleStatus']);",
        ];
        foreach ($routes as $route) {
            preg_match("#'(/admin/[^']+)'#", $route, $m);
            $path = $m[1] ?? '';
            if ($path === '' || str_contains($index, $path)) continue;
            $anchor = $anchors[$path] ?? '';
            if ($anchor !== '' && str_contains($index, $anchor)) {
                $index = str_replace($anchor, $anchor . PHP_EOL . $route, $index);
                echo "PATCHED route {$path}\n";
            } else {
                echo "WARNING route anchor not found for {$path}\n";
            }
        }
        if ($index !== $original) {
            $backup = $deployedIndex . '.pre-v1.5-' . date('YmdHis') . '.bak';
            copy($deployedIndex, $backup);
            file_put_contents($deployedIndex, $index, LOCK_EX);
            echo "BACKUP deployed index {$backup}\n";
        }
    }
}

// Sync only static assets when public_html exists. Never overwrite the custom front controller.
if (is_dir($publicHtml)) {
    $assetMap = [
        $projectRoot . '/public/assets/css/app.css' => $publicHtml . '/assets/css/app.css',
        $projectRoot . '/public/assets/js/app.js' => $publicHtml . '/assets/js/app.js',
    ];
    foreach ($assetMap as $src => $dst) {
        if (!is_file($src)) continue;
        if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0775, true);
        if (is_file($dst)) copy($dst, $dst . '.pre-v1.5-' . date('YmdHis') . '.bak');
        if (copy($src, $dst)) echo "SYNC asset {$dst}\n";
    }
    foreach (['branding','banners'] as $folder) {
        $dst = $publicHtml . '/uploads/' . $folder;
        if (!is_dir($dst)) mkdir($dst, 0775, true);
    }
    $htSrc = $projectRoot . '/public/uploads/.htaccess';
    $htDst = $publicHtml . '/uploads/.htaccess';
    if (is_file($htSrc)) copy($htSrc, $htDst);
}

echo "Khatauat v1.5 cumulative modern UI patch applied successfully.\n";
echo "Preserved: AI draft controls, bilingual output, article workflow, current SQLite data.\n";
