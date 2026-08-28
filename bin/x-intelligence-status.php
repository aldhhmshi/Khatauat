<?php

declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use Khatauat\Services\SocialIntelligenceService;
use Khatauat\Core\Database;

$s=(new SocialIntelligenceService())->statusSummary();
$s['handle_coverage']=Database::fetch(
    "SELECT
       (SELECT COUNT(*) FROM social_watch_targets WHERE active=1 AND network='x') total_targets,
       (SELECT COUNT(DISTINCT target_id) FROM official_x_accounts WHERE active=1 AND verification_status='verified') verified_targets,
       (SELECT COUNT(*) FROM official_x_accounts WHERE active=1 AND verification_status='verified') verified_accounts"
) ?: [];
$s['last_handle_discoveries']=Database::fetchAll(
    "SELECT w.entity_name,l.discovered_handle,l.status,l.official_page_url,l.created_at
     FROM x_handle_discovery_log l JOIN social_watch_targets w ON w.id=l.target_id
     ORDER BY l.id DESC LIMIT 12"
);
$s['temporary_official_solutions']=Database::fetchAll(
    "SELECT w.entity_name,k.issue_type,k.official_handle,k.evidence_url,k.last_verified_at,k.valid_until,k.confidence
     FROM social_solution_knowledge k JOIN social_watch_targets w ON w.id=k.target_id
     WHERE k.status='usable' AND datetime(k.valid_until)>datetime('now')
     ORDER BY k.confidence DESC,datetime(k.last_verified_at) DESC LIMIT 15"
);
$s['last_scans']=Database::fetchAll(
    "SELECT w.entity_name,w.official_handle,w.handle_status,w.last_scanned_at
     FROM social_watch_targets w WHERE w.active=1
     ORDER BY datetime(w.last_scanned_at) DESC,w.priority DESC LIMIT 12"
);
$s['active_incidents']=Database::fetchAll(
    "SELECT w.entity_name,i.status,i.issue_type,i.title,i.confidence,i.last_seen_at,i.expires_at,i.official_evidence_url
     FROM live_service_incidents i JOIN social_watch_targets w ON w.id=i.target_id
     WHERE i.status IN ('confirmed','suspected') AND datetime(i.expires_at)>datetime('now')
     ORDER BY CASE i.status WHEN 'confirmed' THEN 0 ELSE 1 END,i.confidence DESC,i.last_seen_at DESC LIMIT 20"
);
echo json_encode($s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
