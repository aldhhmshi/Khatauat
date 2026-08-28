<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use Khatauat\Core\Database;
use Khatauat\Core\Settings;

$pdo=Database::connection();
$dbPath=(string)config('db_path');
$backup=preg_replace('/\.sqlite$/','.before-v2.0.12.'.date('Ymd_His').'.sqlite',$dbPath) ?: ($dbPath.'.before-v2.0.12');
if(is_file($dbPath) && !copy($dbPath,$backup)){
    fwrite(STDERR,"Failed to backup database\n");
    exit(1);
}

$required=['source_registry','social_watch_targets','social_signals','live_service_incidents','official_source_support','official_entity_contacts'];
foreach($required as $table){
    $st=$pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");
    $st->execute([$table]);
    if(!$st->fetchColumn()){
        fwrite(STDERR,"Missing required table: {$table}. Apply v2.0.11 first.\n");
        exit(1);
    }
}

$pdo->exec("CREATE TABLE IF NOT EXISTS official_x_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    target_id INTEGER NOT NULL,
    source_id INTEGER,
    handle TEXT NOT NULL,
    x_url TEXT NOT NULL,
    account_role TEXT NOT NULL DEFAULT 'general' CHECK(account_role IN ('support','general','service')),
    verification_status TEXT NOT NULL DEFAULT 'verified' CHECK(verification_status IN ('verified','needs_review','rejected')),
    verified_from_url TEXT NOT NULL,
    verification_method TEXT NOT NULL DEFAULT 'official_html_link',
    verified_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(target_id,handle),
    FOREIGN KEY(target_id) REFERENCES social_watch_targets(id) ON DELETE CASCADE,
    FOREIGN KEY(source_id) REFERENCES source_registry(id) ON DELETE SET NULL
)");
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_official_x_target ON official_x_accounts(target_id,verification_status,active)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_official_x_handle ON official_x_accounts(handle,verification_status)');

$pdo->exec("CREATE TABLE IF NOT EXISTS x_handle_discovery_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    target_id INTEGER NOT NULL,
    discovered_handle TEXT,
    official_page_url TEXT,
    status TEXT NOT NULL,
    note TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(target_id) REFERENCES social_watch_targets(id) ON DELETE CASCADE
)");
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_x_discovery_target_time ON x_handle_discovery_log(target_id,created_at)');

$pdo->exec("CREATE TABLE IF NOT EXISTS social_solution_knowledge (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    target_id INTEGER NOT NULL,
    source_id INTEGER,
    issue_type TEXT NOT NULL,
    problem_excerpt TEXT,
    solution_text TEXT NOT NULL,
    evidence_url TEXT NOT NULL UNIQUE,
    official_handle TEXT NOT NULL,
    first_seen_at TEXT NOT NULL,
    last_verified_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    valid_until TEXT NOT NULL,
    confidence INTEGER NOT NULL DEFAULT 90,
    status TEXT NOT NULL DEFAULT 'usable' CHECK(status IN ('usable','expired','dismissed')),
    content_hash TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(target_id) REFERENCES social_watch_targets(id) ON DELETE CASCADE,
    FOREIGN KEY(source_id) REFERENCES source_registry(id) ON DELETE SET NULL
)");
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_social_solution_target ON social_solution_knowledge(target_id,issue_type,status,valid_until)');

// Migrate already verified v2.0.11 handles into the multi-account registry.
$rows=$pdo->query("SELECT id,source_id,official_handle,official_x_url,handle_source_url FROM social_watch_targets WHERE active=1 AND network='x' AND handle_status='verified' AND COALESCE(official_handle,'')<>''")->fetchAll(PDO::FETCH_ASSOC);
$migrate=$pdo->prepare("INSERT INTO official_x_accounts(target_id,source_id,handle,x_url,account_role,verification_status,verified_from_url,verification_method,verified_at,active,created_at,updated_at)
VALUES(?,?,?,?,?,'verified',?,'v2.0.11_seed',CURRENT_TIMESTAMP,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
ON CONFLICT(target_id,handle) DO UPDATE SET x_url=excluded.x_url,verification_status='verified',verified_from_url=excluded.verified_from_url,active=1,updated_at=CURRENT_TIMESTAMP");
$migrated=0;
foreach($rows as $r){
    $h=ltrim(trim((string)$r['official_handle']),'@');
    if($h==='')continue;
    $role=preg_match('/(care|support|help|ecare|_cs|cs_)/i',$h)?'support':'general';
    $x=trim((string)$r['official_x_url']); if($x==='')$x='https://x.com/'.$h;
    $src=trim((string)$r['handle_source_url']); if($src==='')$src=(string)$x;
    $migrate->execute([(int)$r['id'],$r['source_id']!==null?(int)$r['source_id']:null,$h,$x,$role,$src]);
    $migrated++;
}

$settings=[
    'x_handle_discovery_enabled'=>'1',
    'x_handle_discovery_daily_limit'=>'20',
    'social_solution_valid_days'=>'7',
    'social_solution_policy'=>'temporary_official_evidence_only',
    'social_store_public_questions'=>'0',
    'social_user_posts_policy'=>'signals_only_not_authoritative',
    'social_cyberattack_policy'=>'official_confirmation_required',
];
foreach($settings as $k=>$v)Settings::set($k,$v);

$active=(int)$pdo->query("SELECT COUNT(*) FROM source_registry WHERE status='active'")->fetchColumn();
$targets=(int)$pdo->query("SELECT COUNT(*) FROM social_watch_targets WHERE active=1 AND network='x'")->fetchColumn();
$accounts=(int)$pdo->query("SELECT COUNT(*) FROM official_x_accounts WHERE active=1 AND verification_status='verified'")->fetchColumn();
$covered=(int)$pdo->query("SELECT COUNT(DISTINCT target_id) FROM official_x_accounts WHERE active=1 AND verification_status='verified'")->fetchColumn();
$integrity=$pdo->query('PRAGMA integrity_check')->fetchColumn();
$fk=count($pdo->query('PRAGMA foreign_key_check')->fetchAll());
$ok=$integrity==='ok'&&$fk===0&&$targets===$active;

file_put_contents(dirname(__DIR__).'/VERSION',"2.0.12\n");

echo json_encode([
    'ok'=>$ok,
    'version'=>'2.0.12',
    'db_backup'=>basename($backup),
    'active_sources'=>$active,
    'x_watch_targets'=>$targets,
    'verified_x_accounts_migrated'=>$migrated,
    'verified_x_accounts_total'=>$accounts,
    'targets_with_verified_x'=>$covered,
    'handle_discovery'=>'official_site_html_only_no_exa_cost',
    'daily_handle_discovery_limit'=>(int)Settings::get('x_handle_discovery_daily_limit','20'),
    'official_x_solutions'=>'temporary_7_day_evidence',
    'public_question_storage'=>'disabled',
    'user_posts'=>'signals_only_not_authoritative',
    'cyberattack_policy'=>'official_confirmation_required',
    'integrity'=>$integrity,
    'foreign_key_errors'=>$fk,
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
