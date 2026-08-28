<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use Khatauat\Core\Settings;

$root = dirname(__DIR__);
$domainRoot = dirname(dirname($root));
$publicRoot = $domainRoot . '/public_html';
$stamp = date('YmdHis');
function out142(string $m): void { echo $m . PHP_EOL; }
function cp142(string $src,string $dst,string $stamp): void {
    if (!is_file($src)) { out142('MISSING ' . $src); exit(1); }
    @mkdir(dirname($dst),0755,true);
    if (is_file($dst)) @copy($dst,$dst.'.pre-v1.4.2-'.$stamp.'.bak');
    if (!@copy($src,$dst)) { out142('COPY FAILED ' . $dst); exit(1); }
    out142('COPIED ' . $dst);
}

out142('Khatauat v1.4.2 design fidelity fix starting...');

// Preserve previous visual choices under backup keys, then apply the approved preset only to visual settings.
$current = Settings::all();
$visual = [
  'brand_primary'=>'#0c2f55',
  'brand_secondary'=>'#0e8b95',
  'brand_accent'=>'#c8881a',
  'site_background'=>'#f4f7f8',
  'font_family'=>'ibm-plex',
  'motion_level'=>'safe',
  'home_hero_enabled'=>'1',
  'home_stats_enabled'=>'1',
  'home_services_enabled'=>'1',
  'home_feature_enabled'=>'1',
  'home_updates_enabled'=>'1',
  'home_articles_enabled'=>'1',
  'home_calculators_enabled'=>'1',
];
foreach ($visual as $key=>$value) {
    $backupKey = 'v142_previous_' . $key;
    if (!array_key_exists($backupKey,$current) && array_key_exists($key,$current)) Settings::set($backupKey,(string)$current[$key]);
    Settings::set($key,$value);
}
out142('OK approved visual preset applied; previous visual values preserved under v142_previous_*');

// Publish only CSS. No DB migration and no route changes.
cp142($root.'/public/assets/css/app.css',$publicRoot.'/assets/css/app.css',$stamp);

$checks = [
  $root.'/public/assets/css/app.css' => ['Khatauat v1.4.2','--container:1450px','analytics-kpis'],
  $root.'/resources/views/admin/ads.php' => ['class="banner-form"','مختبر الإعلانات'],
];
foreach($checks as $file=>$markers){
    $c=is_file($file)?(string)file_get_contents($file):'';
    $ok=$c!=='';
    foreach($markers as $m)$ok=$ok && str_contains($c,$m);
    out142(($ok?'OK ':'WARNING ').$file);
}
out142('Khatauat v1.4.2 applied: approved navy/teal/gold UI, 1450px layout, admin/form/analytics fixes, all homepage sections enabled.');
out142('No .env reset, no AI reset, no source/ad configuration reset, no database migration.');
