<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use Khatauat\Core\Database;

$pdo=Database::connection();
$active=(int)$pdo->query("SELECT COUNT(*) FROM source_registry WHERE status='active'")->fetchColumn();
$mapped=(int)$pdo->query("SELECT COUNT(DISTINCT m.source_id) FROM official_source_support m JOIN source_registry s ON s.id=m.source_id WHERE s.status='active'")->fetchColumn();

$counts=[];
foreach(['full_contact','support_page','official_site_only'] as $level){
    $st=$pdo->prepare("SELECT COUNT(DISTINCT m.source_id) FROM official_source_support m JOIN official_entity_contacts c ON c.id=m.contact_id JOIN source_registry s ON s.id=m.source_id WHERE s.status='active' AND c.verification_level=?");
    $st->execute([$level]);
    $counts[$level]=(int)$st->fetchColumn();
}
$unmapped=$pdo->query("SELECT s.id,s.name,s.domain FROM source_registry s LEFT JOIN official_source_support m ON m.source_id=s.id WHERE s.status='active' AND m.source_id IS NULL ORDER BY s.id")->fetchAll(PDO::FETCH_ASSOC);

$summary=[
    'active_sources'=>$active,
    'mapped_sources'=>$mapped,
    'coverage_percent'=>$active?round($mapped/$active*100,1):100,
    'full_contact'=>$counts['full_contact'],
    'support_page'=>$counts['support_page'],
    'official_site_only'=>$counts['official_site_only'],
    'total_classified'=>array_sum($counts),
    'unmapped'=>count($unmapped),
];

if (in_array('--json',$argv,true) || in_array('--summary',$argv,true)) {
    echo json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
    exit(0);
}

echo "Active sources: {$active}\n";
echo "Mapped sources: {$mapped}\n";
echo "Coverage: {$summary['coverage_percent']}%\n";
echo "full_contact: {$counts['full_contact']}\n";
echo "support_page: {$counts['support_page']}\n";
echo "official_site_only: {$counts['official_site_only']}\n";
echo "Total classified: {$summary['total_classified']}\n";
echo "Unmapped: ".count($unmapped)."\n\n";
foreach($unmapped as $r) echo "UNMAPPED #{$r['id']} | {$r['name']} | {$r['domain']}\n";

$rows=$pdo->query("SELECT s.id,s.name,s.domain,c.entity_name,c.verification_level,m.support_scope,c.phone,c.email,c.support_url,c.maps_enabled
FROM source_registry s
JOIN official_source_support m ON m.source_id=s.id
JOIN official_entity_contacts c ON c.id=m.contact_id
WHERE s.status='active' ORDER BY s.id")->fetchAll(PDO::FETCH_ASSOC);

echo "\n=== SOURCE SUPPORT MAP ===\n";
foreach($rows as $r){
    $channels=[];
    if(trim((string)$r['phone'])!=='')$channels[]='phone';
    if(trim((string)$r['email'])!=='')$channels[]='email';
    if(trim((string)$r['support_url'])!=='')$channels[]='url';
    if((int)$r['maps_enabled']===1)$channels[]='maps';
    echo '#'.$r['id'].' | '.$r['name'].' | '.$r['verification_level'].' | scope='.$r['support_scope'].' | '.implode(',',$channels).PHP_EOL;
}
