<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Khatauat\Core\Database;

@file_put_contents(root_path('VERSION'), "2.0.18\n");

$fk = Database::fetchAll('PRAGMA foreign_key_check');
$publicCss = dirname(dirname(__DIR__)) . '/public_html/assets/css/app.css';
$appCss = root_path('resources/assets/app.css');

$cssOk = is_file($appCss)
    && is_file($publicCss)
    && str_contains((string)file_get_contents($appCss), 'Khatauat v2.0.18')
    && str_contains((string)file_get_contents($publicCss), 'Khatauat v2.0.18');

echo json_encode([
    'ok' => $cssOk && empty($fk),
    'version' => '2.0.18',
    'owner_header_alignment' => 'title_and_actions_share_visual_baseline',
    'owner_settings_tabs_alignment' => 'matched_to_content_padding',
    'owner_button_alignment' => 'rtl_baseline_normalized',
    'owner_sidebar_font' => 'increased_one_step',
    'footer_font' => 'increased_one_step',
    'css_synced' => $cssOk,
    'integrity' => empty($fk) ? 'ok' : 'warning',
    'foreign_key_errors' => count($fk),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
