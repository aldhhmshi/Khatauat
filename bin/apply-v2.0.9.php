<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use Khatauat\Core\Database;

$pdo = Database::connection();
$dbPath = (string)config('db_path');
$backup = preg_replace('/\.sqlite$/', '.before-v2.0.9.'.date('Ymd_His').'.sqlite', $dbPath) ?: ($dbPath.'.before-v2.0.9');
if (is_file($dbPath) && !copy($dbPath, $backup)) {
    fwrite(STDERR, "Failed to backup database\n");
    exit(1);
}

// This patch assumes v2.0.8 schema. Fail safely if the national directory is missing.
$requiredTables = ['source_registry','official_entity_contacts','official_source_support'];
foreach ($requiredTables as $table) {
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");
    $stmt->execute([$table]);
    if (!$stmt->fetchColumn()) {
        fwrite(STDERR, "Missing required table: {$table}. Apply v2.0.8 first.\n");
        exit(1);
    }
}

$profiles = [
    'civil_defense' => [
        'entity_name' => 'الدفاع المدني السعودي',
        'aliases' => 'الدفاع المدني|998|المديرية العامة للدفاع المدني|Civil Defense',
        'phone' => '998',
        'email' => '998@cd.gov.sa',
        'support_url' => 'https://998.gov.sa/Ar/Page/contact/',
        'branches_url' => '',
        'maps_query' => 'الدفاع المدني السعودي',
        'source_url' => 'https://998.gov.sa/Ar/Page/contact/',
        'verification_level' => 'full_contact',
        'maps_enabled' => 1,
        'support_scope' => 'authority',
        'notes' => 'قنوات الاتصال موثقة من صفحة اتصل بنا الرسمية للدفاع المدني. رقم الطوارئ 998؛ قد تعمل 911 في المناطق المدعومة.',
        'priority' => 130,
    ],
    'salamah' => [
        'entity_name' => 'منصة سلامة',
        'aliases' => 'سلامة|بوابة سلامة|Salamah|salamah.998.gov.sa',
        'phone' => '920000356',
        'email' => 'Hd@elm.sa',
        'support_url' => 'https://998.gov.sa/Ar/Page/Services/1/',
        'branches_url' => '',
        'maps_query' => '',
        'source_url' => 'https://998.gov.sa/Ar/Page/Services/1/',
        'verification_level' => 'full_contact',
        'maps_enabled' => 0,
        'support_scope' => 'platform',
        'notes' => 'بيانات دعم منصة سلامة منشورة في صفحة الخدمة الرسمية لدى الدفاع المدني.',
        'priority' => 130,
    ],
    'etec' => [
        'entity_name' => 'هيئة تقويم التعليم والتدريب',
        'aliases' => 'هيئة تقويم التعليم والتدريب|ETEC|قياس|Qiyas|etec.gov.sa',
        'phone' => '19925',
        'email' => '',
        'support_url' => 'https://etec.gov.sa/ar/support',
        'branches_url' => '',
        'maps_query' => '',
        'source_url' => 'https://etec.gov.sa/ar/support',
        'verification_level' => 'support_page',
        'maps_enabled' => 0,
        'support_scope' => 'authority',
        'notes' => 'رقم التواصل ومنصة الدعم موثقان من صفحة الدعم والمساعدة الرسمية.',
        'priority' => 120,
    ],
    'nelc' => [
        'entity_name' => 'المركز الوطني للتعليم الإلكتروني',
        'aliases' => 'المركز الوطني للتعليم الإلكتروني|NELC|nelc.gov.sa',
        'phone' => '920015991',
        'email' => '',
        'support_url' => 'https://nelc.gov.sa/en/contact-us',
        'branches_url' => '',
        'maps_query' => '',
        'source_url' => 'https://nelc.gov.sa/en/contact-us',
        'verification_level' => 'support_page',
        'maps_enabled' => 0,
        'support_scope' => 'authority',
        'notes' => 'مركز الاتصال الموحد موثق من صفحة التواصل الرسمية.',
        'priority' => 115,
    ],
    'modon' => [
        'entity_name' => 'الهيئة السعودية للمدن الصناعية ومناطق التقنية — مدن',
        'aliases' => 'مدن|MODON|الهيئة السعودية للمدن الصناعية ومناطق التقنية|modon.gov.sa',
        'phone' => '8002499944',
        'email' => 'info@modon.gov.sa',
        'support_url' => 'https://modon.gov.sa/ar/ContactUs/Pages/ContactUs.aspx',
        'branches_url' => '',
        'maps_query' => 'مدن الهيئة السعودية للمدن الصناعية ومناطق التقنية',
        'source_url' => 'https://modon.gov.sa/ar/ContactUs/Pages/ContactUs.aspx',
        'verification_level' => 'full_contact',
        'maps_enabled' => 1,
        'support_scope' => 'authority',
        'notes' => 'الرقم الموحد والبريد موثقان من صفحة اتصل بنا الرسمية.',
        'priority' => 120,
    ],
    'mewa' => [
        'entity_name' => 'وزارة البيئة والمياه والزراعة',
        'aliases' => 'وزارة البيئة والمياه والزراعة|MEWA|نما|mewa.gov.sa',
        'phone' => '939',
        'email' => 'info@mewa.gov.sa',
        'support_url' => 'https://www.mewa.gov.sa/ar/Ministry/AboutMinistry/ContactUs/Pages/default.aspx',
        'branches_url' => 'https://www.mewa.gov.sa/ar/HowWeCanHelp/MinistryContactInfo/Branches/Pages/default.aspx',
        'maps_query' => 'وزارة البيئة والمياه والزراعة',
        'source_url' => 'https://www.mewa.gov.sa/ar/HowWeCanHelp/MinistryContactInfo/MinistryLocations/Pages/default.aspx',
        'verification_level' => 'full_contact',
        'maps_enabled' => 1,
        'support_scope' => 'authority',
        'notes' => 'مركز الاتصال 939 والبريد وصفحة الفروع موثقة من موقع الوزارة الرسمي.',
        'priority' => 125,
    ],
    'energy' => [
        'entity_name' => 'وزارة الطاقة',
        'aliases' => 'وزارة الطاقة|Ministry of Energy|moenergy.gov.sa',
        'phone' => '1914',
        'email' => 'care@moenergy.gov.sa',
        'support_url' => 'https://www.moenergy.gov.sa/ar/contact-us/about',
        'branches_url' => '',
        'maps_query' => 'وزارة الطاقة السعودية',
        'source_url' => 'https://www.moenergy.gov.sa/ar/contact-us/about',
        'verification_level' => 'full_contact',
        'maps_enabled' => 1,
        'support_scope' => 'authority',
        'notes' => 'مركز الاتصال الموحد 1914 والبريد موثقان من صفحة التواصل الرسمية.',
        'priority' => 120,
    ],
    'mot' => [
        'entity_name' => 'وزارة النقل والخدمات اللوجستية',
        'aliases' => 'وزارة النقل والخدمات اللوجستية|MOTLS|وزارة النقل|mot.gov.sa',
        'phone' => '19955',
        'email' => '19955@mot.gov.sa',
        'support_url' => 'https://mot.gov.sa/ar/Help/RoadComunicationCenter/Pages/default.aspx',
        'branches_url' => 'https://mot.gov.sa/ar/ministry-branches',
        'maps_query' => 'وزارة النقل والخدمات اللوجستية',
        'source_url' => 'https://mot.gov.sa/ar/Help/RoadComunicationCenter/Pages/default.aspx',
        'verification_level' => 'full_contact',
        'maps_enabled' => 1,
        'support_scope' => 'authority',
        'notes' => 'مركز خدمة المستفيدين 19955 والبريد والفروع موثقة من موقع الوزارة الرسمي.',
        'priority' => 125,
    ],
    'ksa_visa' => [
        'entity_name' => 'منصة تأشيرة السعودية KSA Visa',
        'aliases' => 'KSA Visa|تأشيرة السعودية|منصة التأشيرات|ksavisa.sa',
        'phone' => '920011114',
        'email' => 'customercare@mofa.gov.sa',
        'support_url' => 'https://ksavisa.sa/contact-us',
        'branches_url' => '',
        'maps_query' => '',
        'source_url' => 'https://ksavisa.sa/contact-us',
        'verification_level' => 'full_contact',
        'maps_enabled' => 0,
        'support_scope' => 'platform',
        'notes' => 'مركز الاتصال والبريد موثقان من صفحة التواصل الرسمية لمنصة KSA Visa.',
        'priority' => 130,
    ],
    'insurance_authority' => [
        'entity_name' => 'هيئة التأمين',
        'aliases' => 'هيئة التأمين|Insurance Authority|IA|ia.gov.sa',
        'phone' => '8001240551',
        'email' => 'Info@ia.gov.sa',
        'support_url' => 'https://www.ia.gov.sa/ar/contact',
        'branches_url' => '',
        'maps_query' => 'هيئة التأمين السعودية',
        'source_url' => 'https://www.ia.gov.sa/',
        'verification_level' => 'full_contact',
        'maps_enabled' => 1,
        'support_scope' => 'authority',
        'notes' => 'الرقم والبريد منشوران في الموقع الرسمي لهيئة التأمين.',
        'priority' => 120,
    ],
];

$upsertContact = $pdo->prepare("INSERT INTO official_entity_contacts(entity_key,entity_name,aliases,phone,email,support_url,branches_url,maps_query,source_url,trust_status,verified_at,priority,verification_level,maps_enabled,support_scope,notes,updated_at)
VALUES(?,?,?,?,?,?,?,?,?,'verified','2026-08-26',?,?,?,?,?,CURRENT_TIMESTAMP)
ON CONFLICT(entity_key) DO UPDATE SET
entity_name=excluded.entity_name,aliases=excluded.aliases,phone=excluded.phone,email=excluded.email,
support_url=excluded.support_url,branches_url=excluded.branches_url,maps_query=excluded.maps_query,source_url=excluded.source_url,
trust_status='verified',verified_at='2026-08-26',priority=excluded.priority,verification_level=excluded.verification_level,
maps_enabled=excluded.maps_enabled,support_scope=excluded.support_scope,notes=excluded.notes,updated_at=CURRENT_TIMESTAMP");

foreach ($profiles as $key => $p) {
    $upsertContact->execute([
        $key,$p['entity_name'],$p['aliases'],$p['phone'],$p['email'],$p['support_url'],$p['branches_url'],$p['maps_query'],$p['source_url'],
        $p['priority'],$p['verification_level'],$p['maps_enabled'],$p['support_scope'],$p['notes']
    ]);
}

$sourceMap = [
    'الدفاع المدني السعودي' => 'civil_defense',
    'منصة سلامة' => 'salamah',
    'هيئة تقويم التعليم والتدريب' => 'etec',
    'المركز الوطني للتعليم الإلكتروني' => 'nelc',
    'مدن' => 'modon',
    'وزارة البيئة والمياه والزراعة' => 'mewa',
    'وزارة الطاقة' => 'energy',
    'وزارة النقل والخدمات اللوجستية' => 'mot',
    'KSA Visa' => 'ksa_visa',
    'هيئة التأمين' => 'insurance_authority',
];

$getSource = $pdo->prepare("SELECT id FROM source_registry WHERE status='active' AND name=? LIMIT 1");
$getContact = $pdo->prepare("SELECT id FROM official_entity_contacts WHERE entity_key=? LIMIT 1");
$map = $pdo->prepare("INSERT INTO official_source_support(source_id,contact_id,support_scope,verified_at,notes,updated_at)
VALUES(?,?,?,'2026-08-26',?,CURRENT_TIMESTAMP)
ON CONFLICT(source_id) DO UPDATE SET contact_id=excluded.contact_id,support_scope=excluded.support_scope,verified_at=excluded.verified_at,notes=excluded.notes,updated_at=CURRENT_TIMESTAMP");

$updated = [];
$missing = [];
foreach ($sourceMap as $sourceName => $contactKey) {
    $getSource->execute([$sourceName]);
    $sourceId = $getSource->fetchColumn();
    $getContact->execute([$contactKey]);
    $contactId = $getContact->fetchColumn();
    if ($sourceId === false || $contactId === false) {
        $missing[] = $sourceName;
        continue;
    }
    $scope = $profiles[$contactKey]['support_scope'];
    $map->execute([(int)$sourceId,(int)$contactId,$scope,'تم إثراء قناة التصعيد من مصدر رسمي موثق في v2.0.9.']);
    $updated[] = $sourceName;
}

$active = (int)$pdo->query("SELECT COUNT(*) FROM source_registry WHERE status='active'")->fetchColumn();
$mapped = (int)$pdo->query("SELECT COUNT(DISTINCT m.source_id) FROM official_source_support m JOIN source_registry s ON s.id=m.source_id WHERE s.status='active'")->fetchColumn();
$counts = [];
foreach (['full_contact','support_page','official_site_only'] as $level) {
    $stmt=$pdo->prepare("SELECT COUNT(DISTINCT m.source_id) FROM official_source_support m JOIN official_entity_contacts c ON c.id=m.contact_id JOIN source_registry s ON s.id=m.source_id WHERE s.status='active' AND c.verification_level=?");
    $stmt->execute([$level]);
    $counts[$level]=(int)$stmt->fetchColumn();
}
$integrity=$pdo->query('PRAGMA integrity_check')->fetchColumn();
$fk=count($pdo->query('PRAGMA foreign_key_check')->fetchAll());

echo json_encode([
    'ok'=>$integrity==='ok' && $fk===0 && $mapped===$active && count($missing)===0,
    'version'=>'2.0.9',
    'db_backup'=>basename($backup),
    'active_sources'=>$active,
    'mapped_sources'=>$mapped,
    'full_contact'=>$counts['full_contact'],
    'support_page'=>$counts['support_page'],
    'official_site_only'=>$counts['official_site_only'],
    'total_classified'=>array_sum($counts),
    'enriched_this_release'=>count($updated),
    'enriched_sources'=>$updated,
    'missing_sources'=>$missing,
    'integrity'=>$integrity,
    'foreign_key_errors'=>$fk,
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
