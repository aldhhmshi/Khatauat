<?php

declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Khatauat\Core\Database;
$summary=[
 'active_sources'=>(int)(Database::fetch("SELECT COUNT(*) c FROM source_registry WHERE status='active'")['c']??0),
 'watch_targets'=>(int)(Database::fetch("SELECT COUNT(*) c FROM social_watch_targets WHERE active=1")['c']??0),
 'scanned_targets'=>(int)(Database::fetch("SELECT COUNT(*) c FROM social_watch_targets WHERE active=1 AND last_scanned_at IS NOT NULL")['c']??0),
 'confirmed_incidents'=>(int)(Database::fetch("SELECT COUNT(*) c FROM live_service_incidents WHERE status='confirmed' AND datetime(expires_at)>datetime('now')")['c']??0),
 'suspected_incidents'=>(int)(Database::fetch("SELECT COUNT(*) c FROM live_service_incidents WHERE status='suspected' AND datetime(expires_at)>datetime('now')")['c']??0),
 'official_guidance'=>(int)(Database::fetch("SELECT COUNT(*) c FROM social_solution_knowledge WHERE status='usable' AND datetime(valid_until)>datetime('now')")['c']??0),
];
echo json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
