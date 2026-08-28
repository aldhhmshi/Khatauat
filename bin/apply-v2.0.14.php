<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use Khatauat\Core\Database;
use Khatauat\Core\Settings;

$pdo=Database::connection();
$dbPath=(string)config('db_path');
$backup=preg_replace('/\.sqlite$/','.before-v2.0.14.'.date('Ymd_His').'.sqlite',$dbPath) ?: ($dbPath.'.before-v2.0.14');
if(is_file($dbPath) && !copy($dbPath,$backup)){
    fwrite(STDERR,"Failed to backup database\n");
    exit(1);
}

$required=['users','services','source_registry'];
foreach($required as $table){
    $st=$pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");
    $st->execute([$table]);
    if(!$st->fetchColumn()){
        fwrite(STDERR,"Missing required table: {$table}\n");
        exit(1);
    }
}

// v2.0.14 also carries the public Service Pulse view from v2.0.13.
$optionalPulseTables=['social_watch_targets','live_service_incidents','social_solution_knowledge'];
$pulseReady=true;
foreach($optionalPulseTables as $table){
    $st=$pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");
    $st->execute([$table]);
    if(!$st->fetchColumn()){$pulseReady=false;break;}
}
if($pulseReady){
    Settings::set('service_pulse_enabled','1');
    Settings::set('service_pulse_public_cache_only','1');
    Settings::set('service_pulse_show_clear_state','1');
    Settings::set('service_pulse_disclaimer','no_active_signal_is_not_uptime_guarantee');
}

Settings::set('owner_console_version','2.0.14');
Settings::set('source_approval_mode','ajax_in_place_with_anchor_fallback');

$activeSources=(int)$pdo->query("SELECT COUNT(*) FROM source_registry WHERE status='active'")->fetchColumn();
$candidateSources=(int)$pdo->query("SELECT COUNT(*) FROM source_registry WHERE status='candidate'")->fetchColumn();
$owners=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='owner'")->fetchColumn();
$published=(int)$pdo->query("SELECT COUNT(*) FROM services WHERE status='published'")->fetchColumn();
$integrity=$pdo->query('PRAGMA integrity_check')->fetchColumn();
$fk=count($pdo->query('PRAGMA foreign_key_check')->fetchAll());
$ok=$integrity==='ok'&&$fk===0&&$owners>0;

file_put_contents(dirname(__DIR__).'/VERSION',"2.0.14\n");

echo json_encode([
    'ok'=>$ok,
    'version'=>'2.0.14',
    'db_backup'=>basename($backup),
    'owner_accounts'=>$owners,
    'published_procedures'=>$published,
    'active_sources'=>$activeSources,
    'candidate_sources'=>$candidateSources,
    'owner_account_route'=>'/account redirects owner to /admin',
    'public_owner_button'=>'home: حسابي -> /admin; other public pages: لوحة المالك -> /admin',
    'owner_console'=>'redesigned command center',
    'source_approval'=>'ajax_in_place_no_scroll_jump + anchored POST fallback',
    'public_problem_entry'=>'دليل الإجراءات cards + individual procedure page',
    'service_pulse'=>$pulseReady?'enabled':'kept disabled until v2.0.12 tables exist',
    'integrity'=>$integrity,
    'foreign_key_errors'=>$fk,
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
