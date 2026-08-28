<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use Khatauat\Core\Database;
use Khatauat\Core\Settings;

$pdo=Database::connection();
$dbPath=(string)config('db_path');
$backup=preg_replace('/\.sqlite$/','.before-v2.0.13.'.date('Ymd_His').'.sqlite',$dbPath) ?: ($dbPath.'.before-v2.0.13');
if(is_file($dbPath) && !copy($dbPath,$backup)){
    fwrite(STDERR,"Failed to backup database\n"); exit(1);
}
$required=['source_registry','social_watch_targets','live_service_incidents','social_solution_knowledge'];
foreach($required as $table){
    $st=$pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");
    $st->execute([$table]);
    if(!$st->fetchColumn()){
        fwrite(STDERR,"Missing required table: {$table}. Apply v2.0.12 first.\n"); exit(1);
    }
}
$settings=[
    'service_pulse_enabled'=>'1',
    'service_pulse_public_cache_only'=>'1',
    'service_pulse_show_clear_state'=>'1',
    'service_pulse_disclaimer'=>'no_active_signal_is_not_uptime_guarantee',
];
foreach($settings as $k=>$v) Settings::set($k,$v);
$active=(int)$pdo->query("SELECT COUNT(*) FROM source_registry WHERE status='active'")->fetchColumn();
$targets=(int)$pdo->query("SELECT COUNT(*) FROM social_watch_targets WHERE active=1")->fetchColumn();
$confirmed=(int)$pdo->query("SELECT COUNT(*) FROM live_service_incidents WHERE status='confirmed' AND datetime(expires_at)>datetime('now')")->fetchColumn();
$suspected=(int)$pdo->query("SELECT COUNT(*) FROM live_service_incidents WHERE status='suspected' AND datetime(expires_at)>datetime('now')")->fetchColumn();
$solutions=(int)$pdo->query("SELECT COUNT(*) FROM social_solution_knowledge WHERE status='usable' AND datetime(valid_until)>datetime('now')")->fetchColumn();
$integrity=$pdo->query('PRAGMA integrity_check')->fetchColumn();
$fk=count($pdo->query('PRAGMA foreign_key_check')->fetchAll());
$ok=$integrity==='ok'&&$fk===0&&$active===$targets;
file_put_contents(dirname(__DIR__).'/VERSION',"2.0.13\n");
echo json_encode([
    'ok'=>$ok,'version'=>'2.0.13','db_backup'=>basename($backup),
    'active_sources'=>$active,'pulse_targets'=>$targets,'coverage_percent'=>$active>0?(int)round(($targets/$active)*100):0,
    'confirmed_incidents'=>$confirmed,'suspected_incidents'=>$suspected,'temporary_official_solutions'=>$solutions,
    'public_service_pages'=>'cached_pulse_no_exa_call','ask_ai_preflight'=>'enabled','ai_quota_policy'=>'service_status_and_official_guidance_do_not_consume_ai',
    'uptime_claim_policy'=>'no_active_signal_is_not_uptime_guarantee','integrity'=>$integrity,'foreign_key_errors'=>$fk,
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
