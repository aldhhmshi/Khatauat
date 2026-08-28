<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Khatauat\Core\Database;
use Khatauat\Core\Settings;

$projectRoot = dirname(__DIR__);
$domainRoot = dirname(dirname($projectRoot));
$publicRoot = $domainRoot . '/public_html';
$stamp = date('YmdHis');

function out141(string $message): void { echo $message . PHP_EOL; }
function copy141(string $src, string $dst, string $stamp): void {
    if (!is_file($src)) { out141('WARNING missing ' . $src); return; }
    @mkdir(dirname($dst), 0755, true);
    if (is_file($dst)) @copy($dst, $dst . '.pre-v1.4.1-' . $stamp . '.bak');
    if (@copy($src, $dst)) out141('COPIED ' . $dst); else out141('WARNING copy failed ' . $dst);
}

out141('Khatauat v1.4.1 approved UI installer starting...');

// Backup the live SQLite database before additive migration.
$dbPath = (string)config('db_path', '');
if ($dbPath !== '' && is_file($dbPath)) {
    $dbBackup = $dbPath . '.pre-v1.4.1-' . $stamp . '.bak';
    if (@copy($dbPath, $dbBackup)) out141('DB BACKUP ' . $dbBackup);
}

// Additive analytics table only. No DROP, DELETE, RESET or destructive migration.
Database::execute("CREATE TABLE IF NOT EXISTS traffic_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type TEXT NOT NULL DEFAULT 'pageview',
    page_path TEXT NOT NULL,
    referrer_host TEXT,
    source_category TEXT,
    device_type TEXT,
    session_hash TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
Database::execute('CREATE INDEX IF NOT EXISTS idx_traffic_events_created ON traffic_events(created_at)');
Database::execute('CREATE INDEX IF NOT EXISTS idx_traffic_events_path ON traffic_events(page_path, created_at)');
Database::execute('CREATE INDEX IF NOT EXISTS idx_traffic_events_source ON traffic_events(source_category, created_at)');
out141('OK additive analytics schema');

// Set defaults only when a key does not already exist. Existing settings are preserved.
$current = Settings::all();
$defaults = [
    'platform_url' => (string)config('base_url',''),
    'brand_primary' => '#0c2f55',
    'brand_secondary' => '#0e8b95',
    'brand_accent' => '#c8881a',
    'site_background' => '#f4f7f8',
    'font_family' => 'ibm-plex',
    'motion_level' => 'safe',
    'home_hero_enabled' => '1',
    'home_stats_enabled' => '1',
    'home_services_enabled' => '1',
    'home_feature_enabled' => '1',
    'home_updates_enabled' => '1',
    'home_articles_enabled' => '1',
    'home_calculators_enabled' => '1',
    'internal_analytics_enabled' => '1',
    'banner_sticky' => '1',
    'banner_media_type' => 'image',
];
foreach ($defaults as $key => $value) {
    if (!array_key_exists($key, $current)) Settings::set($key, $value);
}
out141('OK settings preserved; missing defaults added only');

// Ensure upload/assets directories exist and publish CSS/JS to the actual domain webroot.
@mkdir($projectRoot . '/public/assets/uploads', 0755, true);
@mkdir($publicRoot . '/assets/uploads', 0755, true);
copy141($projectRoot . '/public/assets/css/app.css', $publicRoot . '/assets/css/app.css', $stamp);
copy141($projectRoot . '/public/assets/js/app.js', $publicRoot . '/assets/js/app.js', $stamp);

// Patch only route declarations in public_html/index.php. Preserve its custom bootstrap require path.
$index = $publicRoot . '/index.php';
if (is_file($index)) {
    $source = (string)file_get_contents($index);
    $original = $source;
    $routeGroups = [
        [
            '$router->post(\'/ad/event\', [HomeController::class, \'adEvent\']);',
            ["$"."router->post('/analytics/event', [HomeController::class, 'analyticsEvent']);"]
        ],
        [
            '$router->post(\'/admin/ad/status\', [AdminController::class, \'adStatus\']);',
            [
                "$"."router->post('/admin/banner/save', [AdminController::class, 'bannerSave']);",
                "$"."router->post('/admin/banner/delete', [AdminController::class, 'bannerDelete']);",
                "$"."router->get('/admin/analytics', [AdminController::class, 'analytics']);",
                "$"."router->get('/admin/appearance', [AdminController::class, 'appearance']);",
                "$"."router->post('/admin/appearance', [AdminController::class, 'appearance']);",
                "$"."router->post('/admin/home-block/save', [AdminController::class, 'homepageBlockSave']);",
                "$"."router->post('/admin/home-block/delete', [AdminController::class, 'homepageBlockDelete']);",
            ]
        ],
    ];
    foreach ($routeGroups as [$anchor, $routes]) {
        $insert = '';
        foreach ($routes as $route) if (!str_contains($source, $route)) $insert .= PHP_EOL . $route;
        if ($insert !== '') {
            if (str_contains($source, $anchor)) $source = str_replace($anchor, $anchor . $insert, $source);
            else out141('WARNING route anchor not found: ' . $anchor);
        }
    }
    if ($source !== $original) {
        $backup = $index . '.pre-v1.4.1-' . $stamp . '.bak';
        @copy($index, $backup);
        file_put_contents($index, $source, LOCK_EX);
        out141('PATCHED ' . $index);
        out141('INDEX BACKUP ' . $backup);
    } else out141('OK routes already present');
} else out141('WARNING public_html/index.php not found');

// Marker checks without exec/shell_exec (Hostinger-safe).
$checks = [
    $projectRoot . '/resources/views/layout.php' => ['banner_media_type','data-internal-analytics'],
    $projectRoot . '/resources/views/admin/analytics.php' => ['تحليلات الإعلانات والزيارات'],
    $projectRoot . '/resources/views/admin/ads.php' => ['banner_media','مختبر الإعلانات'],
    $projectRoot . '/public/assets/css/app.css' => ['Khatauat v1.4.1 Approved UI'],
    $projectRoot . '/public/assets/js/app.js' => ['/analytics/event'],
];
foreach ($checks as $file => $markers) {
    $content = is_file($file) ? (string)file_get_contents($file) : '';
    $ok = $content !== '';
    foreach ($markers as $marker) $ok = $ok && str_contains($content, $marker);
    out141(($ok ? 'OK ' : 'WARNING ') . $file);
}

out141('Khatauat v1.4.1 approved UI + safe motion + banner media + ad lab + traffic analytics applied.');
out141('No .env reset, no AI settings reset, no destructive database migration.');
