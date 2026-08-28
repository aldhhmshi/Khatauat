<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use Khatauat\Core\Database;
use Khatauat\Core\Settings;

$pdo=Database::connection();
$dbPath=(string)config('db_path');
$backup=preg_replace('/\.sqlite$/','.before-v2.0.11.'.date('Ymd_His').'.sqlite',$dbPath) ?: ($dbPath.'.before-v2.0.11');
if(is_file($dbPath) && !copy($dbPath,$backup)){
    fwrite(STDERR,"Failed to backup database\n");
    exit(1);
}

$pdo->exec("CREATE TABLE IF NOT EXISTS social_watch_targets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    target_key TEXT NOT NULL UNIQUE,
    source_id INTEGER,
    network TEXT NOT NULL DEFAULT 'x',
    entity_name TEXT NOT NULL,
    aliases TEXT,
    sector TEXT,
    official_handle TEXT,
    official_x_url TEXT,
    handle_status TEXT NOT NULL DEFAULT 'needs_review' CHECK(handle_status IN ('verified','needs_review','unknown')),
    handle_source_url TEXT,
    priority INTEGER NOT NULL DEFAULT 50,
    active INTEGER NOT NULL DEFAULT 1,
    last_scanned_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(source_id) REFERENCES source_registry(id) ON DELETE CASCADE
)");
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_social_watch_active ON social_watch_targets(active,priority,id)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_social_watch_source ON social_watch_targets(source_id,network)');

$pdo->exec("CREATE TABLE IF NOT EXISTS social_intelligence_scans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    target_id INTEGER NOT NULL,
    provider TEXT NOT NULL DEFAULT 'exa',
    mode TEXT NOT NULL DEFAULT 'daily_batch',
    query_text TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'running' CHECK(status IN ('running','ok','failed')),
    result_count INTEGER NOT NULL DEFAULT 0,
    error_message TEXT,
    started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at TEXT,
    FOREIGN KEY(target_id) REFERENCES social_watch_targets(id) ON DELETE CASCADE
)");
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_social_scan_target_time ON social_intelligence_scans(target_id,started_at)');

$pdo->exec("CREATE TABLE IF NOT EXISTS social_signals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    target_id INTEGER NOT NULL,
    source_id INTEGER,
    network TEXT NOT NULL DEFAULT 'x',
    post_url TEXT NOT NULL UNIQUE,
    author_handle TEXT,
    title TEXT,
    excerpt TEXT,
    published_at TEXT,
    observed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    evidence_type TEXT NOT NULL DEFAULT 'user_report' CHECK(evidence_type IN ('official_content','user_report')),
    issue_type TEXT NOT NULL,
    solution_text TEXT,
    confidence INTEGER NOT NULL DEFAULT 0,
    official_confirmed INTEGER NOT NULL DEFAULT 0,
    review_status TEXT NOT NULL DEFAULT 'candidate' CHECK(review_status IN ('candidate','verified','dismissed','expired')),
    expires_at TEXT NOT NULL,
    content_hash TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(target_id) REFERENCES social_watch_targets(id) ON DELETE CASCADE,
    FOREIGN KEY(source_id) REFERENCES source_registry(id) ON DELETE SET NULL
)");
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_social_signals_target_time ON social_signals(target_id,published_at,expires_at)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_social_signals_issue ON social_signals(issue_type,evidence_type,official_confirmed)');

$pdo->exec("CREATE TABLE IF NOT EXISTS live_service_incidents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    target_id INTEGER NOT NULL,
    source_id INTEGER,
    issue_type TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'suspected' CHECK(status IN ('suspected','confirmed','resolved')),
    title TEXT NOT NULL,
    summary TEXT NOT NULL,
    workaround TEXT,
    first_seen_at TEXT,
    last_seen_at TEXT,
    expires_at TEXT NOT NULL,
    evidence_count INTEGER NOT NULL DEFAULT 0,
    user_report_count INTEGER NOT NULL DEFAULT 0,
    official_evidence_count INTEGER NOT NULL DEFAULT 0,
    official_evidence_url TEXT,
    confidence INTEGER NOT NULL DEFAULT 0,
    resolved_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(target_id,issue_type),
    FOREIGN KEY(target_id) REFERENCES social_watch_targets(id) ON DELETE CASCADE,
    FOREIGN KEY(source_id) REFERENCES source_registry(id) ON DELETE SET NULL
)");
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_live_incident_status ON live_service_incidents(status,expires_at,target_id)');

// Every active official source gets a daily X watch target. This does not claim
// that the source has a verified X account; handles are verified separately.
$rows=$pdo->query("SELECT id,name,domain,sector,source_role FROM source_registry WHERE status='active' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$upsert=$pdo->prepare("INSERT INTO social_watch_targets(target_key,source_id,network,entity_name,aliases,sector,priority,active,updated_at)
VALUES(?,?,'x',?,?,?,?,1,CURRENT_TIMESTAMP)
ON CONFLICT(target_key) DO UPDATE SET source_id=excluded.source_id,entity_name=excluded.entity_name,aliases=excluded.aliases,sector=excluded.sector,priority=excluded.priority,active=1,updated_at=CURRENT_TIMESTAMP");
foreach($rows as $r){
    $role=(string)($r['source_role']??'');
    $priority=match($role){
        'execution'=>110,
        'service'=>90,
        'reference'=>75,
        'regulation'=>65,
        'verification'=>60,
        'data'=>50,
        'directory'=>45,
        default=>55,
    };
    $aliases=implode('|',array_values(array_unique(array_filter([
        (string)$r['name'],
        (string)$r['domain'],
        preg_replace('/^www\./','',(string)$r['domain']),
        (string)$r['sector'],
    ]))));
    $upsert->execute(['source-'.(int)$r['id'].'-x',(int)$r['id'],(string)$r['name'],$aliases,(string)$r['sector'],$priority]);
}

// X handles below were verified from pages on the corresponding official
// government/platform domains. Other targets remain needs_review and can still
// contribute non-authoritative user signals, but never official confirmations.
$handles=[
    'هيئة الحكومة الرقمية'=>['DGAecare','https://x.com/DGAecare','https://dga.gov.sa/ar/programs/amer/social-channels'],
    'أبشر أفراد'=>['Absher','https://x.com/Absher','https://www.absher.sa/wps/portal/business/static/contact/'],
    'وزارة التجارة'=>['mcgovsa_care','https://x.com/mcgovsa_care','https://mc.gov.sa/ar/Pages/mcgovsa_care.aspx'],
    'المركز السعودي للأعمال'=>['BCgov_Care','https://x.com/BCgov_Care','https://business.sa/'],
    'منصة بلدي'=>['Balady_CS','https://x.com/Balady_CS','https://balady.gov.sa/ar/help-and-support/social-media'],
    'هيئة الزكاة والضريبة والجمارك'=>['Zatca_care','https://x.com/Zatca_care','https://zatca.gov.sa/ar/eServices/Pages/eServices_260.aspx'],
    'مساند'=>['Musaned_DL','https://x.com/Musaned_DL','https://musaned.com.sa/ar/contact-us'],
    'التأمينات الاجتماعية'=>['GosiCare','https://x.com/GosiCare','https://www.gosi.gov.sa/GOSIOnline/news144'],
    'وزارة النقل والخدمات اللوجستية'=>['motls19955','https://x.com/motls19955','https://mot.gov.sa/ar/Help/RoadComunicationCenter/Pages/default.aspx'],
    'منصة اعتماد'=>['etimadsa','https://x.com/etimadsa','https://portal.etimad.sa/ar-sa/aboutetimad/contactusindex'],
];
$findSource=$pdo->prepare("SELECT id FROM source_registry WHERE status='active' AND name=? LIMIT 1");
$setHandle=$pdo->prepare("UPDATE social_watch_targets SET official_handle=?,official_x_url=?,handle_status='verified',handle_source_url=?,updated_at=CURRENT_TIMESTAMP WHERE source_id=? AND network='x'");
$verified=[];$missing=[];
foreach($handles as $name=>$h){
    $findSource->execute([$name]);$sid=$findSource->fetchColumn();
    if($sid===false){$missing[]=$name;continue;}
    $setHandle->execute([$h[0],$h[1],$h[2],(int)$sid]);
    $verified[]=$name;
}

$settings=[
    'social_intelligence_enabled'=>'1',
    'social_daily_batch_size'=>'6',
    'social_daily_max_batches'=>'20',
    'social_live_check_enabled'=>'1',
    'social_live_check_cache_minutes'=>'60',
    'social_signal_max_age_hours'=>'72',
    'social_user_report_threshold'=>'3',
    'social_incident_ttl_hours'=>'24',
    'social_official_signal_ttl_hours'=>'72',
    'social_user_signal_ttl_hours'=>'36',
    'social_publish_policy'=>'owner_review_only',
    'social_security_claim_policy'=>'official_confirmation_required',
];
foreach($settings as $k=>$v) Settings::set($k,$v);

$active=(int)$pdo->query("SELECT COUNT(*) FROM source_registry WHERE status='active'")->fetchColumn();
$targets=(int)$pdo->query("SELECT COUNT(*) FROM social_watch_targets WHERE active=1 AND network='x'")->fetchColumn();
$verifiedCount=(int)$pdo->query("SELECT COUNT(*) FROM social_watch_targets WHERE active=1 AND network='x' AND handle_status='verified' AND official_handle<>''")->fetchColumn();
$integrity=$pdo->query('PRAGMA integrity_check')->fetchColumn();
$fk=count($pdo->query('PRAGMA foreign_key_check')->fetchAll());
$ok=$integrity==='ok'&&$fk===0&&$targets===$active&&count($missing)===0;

file_put_contents(dirname(__DIR__).'/VERSION',"2.0.11\n");

echo json_encode([
    'ok'=>$ok,
    'version'=>'2.0.11',
    'db_backup'=>basename($backup),
    'active_sources'=>$active,
    'x_watch_targets'=>$targets,
    'verified_x_accounts'=>$verifiedCount,
    'verified_handles_seeded'=>$verified,
    'missing_handle_sources'=>$missing,
    'daily_strategy'=>'all_74_in_batched_exa_x_searches',
    'live_technical_check'=>'shared_cache_60_minutes',
    'user_posts'=>'signals_only_not_authoritative',
    'official_x_replies'=>'temporary_current_evidence_not_permanent_auto_publish',
    'cyberattack_policy'=>'never infer; requires official confirmation',
    'publish_policy'=>'owner_review_only',
    'integrity'=>$integrity,
    'foreign_key_errors'=>$fk,
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
