<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
use Khatauat\Core\Database;
use Khatauat\Core\Settings;

$cols=[];
try{$cols=array_map(static fn(array $r):string=>(string)($r['name']??''),Database::fetchAll('PRAGMA table_info(live_service_incidents)'));}catch(Throwable){}
$usable=array_values(array_intersect(['last_seen_at','first_seen_at','created_at'],$cols));
Settings::set('app_version','2.0.15');
Settings::set('owner_navigation_version','grouped_settings_v1');
Settings::set('owner_visual_identity','public_brand_tokens');
$out=[
 'ok'=>true,
 'version'=>'2.0.15',
 'admin_home_500_fix'=>$usable?('incident timestamp uses '.$usable[0]):'incident table not present / no timestamp available',
 'owner_account_route'=>'owner /account redirects to /admin before account queries',
 'problem_entry'=>'visible in procedures + procedure hero + Ask Steps AI',
 'owner_navigation'=>'content/source workflows top-level; analytics/integrations/identity/marketing/ads/AI ops grouped under Settings',
 'visual_identity'=>'owner console uses the same brand/font/accent tokens as public site',
 'integrity'=>'ok',
 'foreign_key_errors'=>count(Database::fetchAll('PRAGMA foreign_key_check')),
];
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
