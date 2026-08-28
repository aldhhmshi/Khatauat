<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use Khatauat\Core\Database;

$pdo = Database::connection();
$dbPath = (string)config('db_path');
$backup = preg_replace('/\.sqlite$/', '.before-v2.0.10.'.date('Ymd_His').'.sqlite', $dbPath) ?: ($dbPath.'.before-v2.0.10');
if (is_file($dbPath) && !copy($dbPath, $backup)) {
    fwrite(STDERR, "Failed to backup database\n");
    exit(1);
}

$requiredTables = ['source_registry','official_entity_contacts','official_source_support'];
foreach ($requiredTables as $table) {
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");
    $stmt->execute([$table]);
    if (!$stmt->fetchColumn()) {
        fwrite(STDERR, "Missing required table: {$table}. Apply v2.0.8+ first.\n");
        exit(1);
    }
}

// Wave 2: every remaining source receives an independently verified support profile.
// Empty phone/email fields are deliberate when the official source exposes only a support/contact page.
$profiles = [
    'tourism_ministry' => [
        'entity_name'=>'وزارة السياحة','aliases'=>'وزارة السياحة|Ministry of Tourism|mt.gov.sa|السياحة',
        'phone'=>'930','email'=>'','support_url'=>'https://mt.gov.sa/AboutSCTA/Pages/ContactUs.aspx','branches_url'=>'',
        'maps_query'=>'وزارة السياحة السعودية','source_url'=>'https://cdn.mt.gov.sa/mtportal/mt-fe-production/content/policies-regulations/documents/tourism-regulations/Tourism-Activities-Inspection-Regulation-Procedures-Guide-Ar-V01.pdf',
        'verification_level'=>'support_page','maps_enabled'=>1,'support_scope'=>'authority',
        'notes'=>'مركز العناية بالزائر 930 موثق في دليل إجراءات رسمي للوزارة، وصفحة التواصل على نطاق الوزارة الرسمي.','priority'=>120,
    ],
    'cma' => [
        'entity_name'=>'هيئة السوق المالية','aliases'=>'هيئة السوق المالية|CMA|cma.org.sa|السوق المالية',
        'phone'=>'','email'=>'','support_url'=>'https://cma.org.sa/ContactWithCMA/Pages/ContactUs.aspx','branches_url'=>'',
        'maps_query'=>'هيئة السوق المالية السعودية','source_url'=>'https://cma.org.sa/ContactWithCMA/Pages/ContactUs.aspx',
        'verification_level'=>'support_page','maps_enabled'=>1,'support_scope'=>'authority',
        'notes'=>'نموذج التواصل الرسمي موثق ومحدث على موقع الهيئة؛ لم نثبت هاتفًا أو بريدًا عامًا من الصفحة المقروءة.','priority'=>115,
    ],
    'nca' => [
        'entity_name'=>'الهيئة الوطنية للأمن السيبراني','aliases'=>'الهيئة الوطنية للأمن السيبراني|NCA|nca.gov.sa|الأمن السيبراني',
        'phone'=>'','email'=>'','support_url'=>'https://nca.gov.sa/ar/contact-us/','branches_url'=>'',
        'maps_query'=>'','source_url'=>'https://nca.gov.sa/ar/contact-us/',
        'verification_level'=>'support_page','maps_enabled'=>0,'support_scope'=>'authority',
        'notes'=>'صفحة تواصل رسمية للهيئة. قنوات حَصين الخاصة لا تُستخدم كقناة عامة للهيئة إلا للخدمات التي تخصها.','priority'=>120,
    ],
    'sdaia' => [
        'entity_name'=>'الهيئة السعودية للبيانات والذكاء الاصطناعي — سدايا','aliases'=>'سدايا|SDAIA|الهيئة السعودية للبيانات والذكاء الاصطناعي|sdaia.gov.sa',
        'phone'=>'','email'=>'','support_url'=>'https://sdaia.gov.sa/ar/Contact/Pages/ContactUs.aspx','branches_url'=>'',
        'maps_query'=>'','source_url'=>'https://sdaia.gov.sa/ar/Contact/Pages/ContactUs.aspx',
        'verification_level'=>'support_page','maps_enabled'=>0,'support_scope'=>'authority',
        'notes'=>'نموذج التواصل الرسمي وقنوات الاتصال موثقة من صفحة سدايا الرسمية.','priority'=>120,
    ],
    'gastat' => [
        'entity_name'=>'الهيئة العامة للإحصاء','aliases'=>'الهيئة العامة للإحصاء|GASTAT|الإحصاء|stats.gov.sa',
        'phone'=>'199009','email'=>'','support_url'=>'https://stats.gov.sa/inner-contact-us','branches_url'=>'',
        'maps_query'=>'الهيئة العامة للإحصاء السعودية','source_url'=>'https://stats.gov.sa/inner-contact-us',
        'verification_level'=>'support_page','maps_enabled'=>1,'support_scope'=>'authority',
        'notes'=>'الرقم الموحد 199009 موثق في صفحة تواصل معنا الرسمية.','priority'=>115,
    ],
    'boe' => [
        'entity_name'=>'هيئة الخبراء بمجلس الوزراء','aliases'=>'هيئة الخبراء|هيئة الخبراء بمجلس الوزراء|BOE|laws.boe.gov.sa',
        'phone'=>'00966114882430','email'=>'','support_url'=>'https://my.gov.sa/ar/agencies/17573','branches_url'=>'',
        'maps_query'=>'هيئة الخبراء بمجلس الوزراء الرياض','source_url'=>'https://my.gov.sa/ar/agencies/17573',
        'verification_level'=>'support_page','maps_enabled'=>1,'support_scope'=>'authority',
        'notes'=>'رقم الجهة موثق في سجل الجهة على المنصة الوطنية GOV.SA.','priority'=>110,
    ],
    'uqn' => [
        'entity_name'=>'جريدة أم القرى','aliases'=>'جريدة أم القرى|أم القرى|Umm Al-Qura|uqn.gov.sa|الجريدة الرسمية',
        'phone'=>'0125202902','email'=>'uqn.care@uqn.gov.sa','support_url'=>'https://uqn.gov.sa/contactUS','branches_url'=>'',
        'maps_query'=>'جريدة أم القرى مكة المكرمة','source_url'=>'https://uqn.gov.sa/contactUS',
        'verification_level'=>'full_contact','maps_enabled'=>1,'support_scope'=>'authority',
        'notes'=>'العناية بالمستفيدين وأرقام التواصل منشورة في صفحة اتصل بنا الرسمية. يوجد رقم إضافي 0125204344.','priority'=>125,
    ],
    'etimad' => [
        'entity_name'=>'منصة اعتماد','aliases'=>'اعتماد|منصة اعتماد|Etimad|portal.etimad.sa',
        'phone'=>'19990','email'=>'ecare@etimad.sa','support_url'=>'https://portal.etimad.sa/ar-sa/aboutetimad/contactusindex','branches_url'=>'',
        'maps_query'=>'','source_url'=>'https://portal.etimad.sa/ar-sa/aboutetimad/contactusindex',
        'verification_level'=>'full_contact','maps_enabled'=>0,'support_scope'=>'platform',
        'notes'=>'مركز خدمات المستفيدين 19990 والبريد ecare@etimad.sa موثقان من صفحة التواصل الرسمية.','priority'=>135,
    ],
    'monshaat' => [
        'entity_name'=>'الهيئة العامة للمنشآت الصغيرة والمتوسطة — منشآت','aliases'=>'منشآت|Monsha’at|Monshaat|المنشآت الصغيرة والمتوسطة|monshaat.gov.sa',
        'phone'=>'8003018888','email'=>'Info@monshaat.gov.sa','support_url'=>'https://monshaat.gov.sa/ar/support','branches_url'=>'https://monshaat.gov.sa/ar/ssc',
        'maps_query'=>'مركز دعم المنشآت','source_url'=>'https://monshaat.gov.sa/ar/support',
        'verification_level'=>'full_contact','maps_enabled'=>1,'support_scope'=>'authority',
        'notes'=>'الرقم والبريد ومراكز دعم المنشآت موثقة من موقع منشآت الرسمي.','priority'=>130,
    ],
    'sgs' => [
        'entity_name'=>'هيئة المساحة الجيولوجية السعودية','aliases'=>'المساحة الجيولوجية السعودية|هيئة المساحة الجيولوجية|SGS|sgs.gov.sa',
        'phone'=>'','email'=>'','support_url'=>'https://sgs.gov.sa/contactus','branches_url'=>'',
        'maps_query'=>'هيئة المساحة الجيولوجية السعودية','source_url'=>'https://sgs.gov.sa/',
        'verification_level'=>'support_page','maps_enabled'=>1,'support_scope'=>'authority',
        'notes'=>'الموقع الرسمي يوفر نموذج تواصل؛ لم نثبت رقمًا أو بريدًا عامًا من صفحة رسمية مقروءة.','priority'=>105,
    ],
    'tvtc' => [
        'entity_name'=>'المؤسسة العامة للتدريب التقني والمهني','aliases'=>'التدريب التقني والمهني|TVTC|tvtc.gov.sa|المؤسسة العامة للتدريب التقني والمهني',
        'phone'=>'','email'=>'cso@tvtc.gov.sa','support_url'=>'https://tvtc.gov.sa/ar/ContactUS/Pages/ContactUs.aspx','branches_url'=>'https://tvtc.gov.sa/ar/ContactUS/Pages/default.aspx',
        'maps_query'=>'المؤسسة العامة للتدريب التقني والمهني','source_url'=>'https://tvtc.gov.sa/ar/ContactUS/FAQs/Pages/q3.aspx',
        'verification_level'=>'support_page','maps_enabled'=>1,'support_scope'=>'authority',
        'notes'=>'صفحة التواصل وبريد مكتب علاقات العملاء cso@tvtc.gov.sa موثقان من موقع المؤسسة الرسمي.','priority'=>115,
    ],
    'mofa' => [
        'entity_name'=>'وزارة الخارجية','aliases'=>'وزارة الخارجية|MOFA|mofa.gov.sa|الخارجية',
        'phone'=>'','email'=>'','support_url'=>'https://www.mofa.gov.sa/ar/Pages/contacts.aspx','branches_url'=>'',
        'maps_query'=>'وزارة الخارجية السعودية','source_url'=>'https://www.mofa.gov.sa/ar/Pages/contacts.aspx',
        'verification_level'=>'support_page','maps_enabled'=>1,'support_scope'=>'authority',
        'notes'=>'صفحة تواصل رسمية للوزارة؛ القنوات التفصيلية لم تُثبت من النص المتاح، لذا لا يُعرض رقم أو بريد عام بالتخمين.','priority'=>110,
    ],
    'mof' => [
        'entity_name'=>'وزارة المالية','aliases'=>'وزارة المالية|MOF|mof.gov.sa|المالية',
        'phone'=>'19990','email'=>'ccc@mof.gov.sa','support_url'=>'https://mof.gov.sa/Pages/MOFContactUs.aspx','branches_url'=>'',
        'maps_query'=>'وزارة المالية السعودية','source_url'=>'https://www.mof.gov.sa/eservices/Pages/HelpAndSupport.aspx',
        'verification_level'=>'full_contact','maps_enabled'=>1,'support_scope'=>'authority',
        'notes'=>'19990 والبريد ccc@mof.gov.sa موثقان في صفحات الدعم الرسمية.','priority'=>125,
    ],
    'mawani' => [
        'entity_name'=>'الهيئة العامة للموانئ — موانئ','aliases'=>'موانئ|الهيئة العامة للموانئ|Mawani|mawani.gov.sa',
        'phone'=>'','email'=>'','support_url'=>'https://mawani.gov.sa/contact','branches_url'=>'',
        'maps_query'=>'الهيئة العامة للموانئ موانئ','source_url'=>'https://mawani.gov.sa/contact',
        'verification_level'=>'support_page','maps_enabled'=>1,'support_scope'=>'authority',
        'notes'=>'صفحة تواصل رسمية متاحة على نطاق موانئ. لم نثبت هاتفًا أو بريدًا من المحتوى الرسمي المقروء.','priority'=>105,
    ],
    'saso' => [
        'entity_name'=>'الهيئة السعودية للمواصفات والمقاييس والجودة','aliases'=>'المواصفات والمقاييس والجودة|SASO|saso.gov.sa|المواصفات السعودية',
        'phone'=>'8001160000','email'=>'info@saso.gov.sa','support_url'=>'https://www.saso.gov.sa/ar/contactus/Pages/landingTicket.aspx','branches_url'=>'https://www.saso.gov.sa/ar/contactus/Pages/landingTicket.aspx',
        'maps_query'=>'الهيئة السعودية للمواصفات والمقاييس والجودة','source_url'=>'https://www.saso.gov.sa/ar/contactus/Pages/CreateTicket.aspx?guid=568ce742-e27f-ef11-9bde-005056a8daa0',
        'verification_level'=>'full_contact','maps_enabled'=>1,'support_scope'=>'authority',
        'notes'=>'الرقم والبريد وصفحة الفروع موثقة من موقع الهيئة الرسمي.','priority'=>125,
    ],
    'sbc' => [
        'entity_name'=>'المركز السعودي لكود البناء','aliases'=>'كود البناء السعودي|المركز السعودي لكود البناء|SBC|sbc.gov.sa',
        'phone'=>'','email'=>'','support_url'=>'https://sbc.gov.sa/En/ContactUS/Pages/Faq.aspx','branches_url'=>'',
        'maps_query'=>'المركز السعودي لكود البناء','source_url'=>'https://sbc.gov.sa/En/ContactUS/Pages/Faq.aspx',
        'verification_level'=>'support_page','maps_enabled'=>1,'support_scope'=>'authority',
        'notes'=>'صفحة الاتصال والأسئلة الشائعة الرسمية متاحة؛ لم نثبت رقمًا أو بريدًا عامًا من صفحة رسمية مقروءة.','priority'=>105,
    ],
    'ncnp' => [
        'entity_name'=>'المركز الوطني لتنمية القطاع غير الربحي','aliases'=>'المركز الوطني لتنمية القطاع غير الربحي|NCNP|ncnp.gov.sa|القطاع غير الربحي',
        'phone'=>'19918','email'=>'mc@ncnp.gov.sa','support_url'=>'https://ncnp.gov.sa/ar/contacts-us','branches_url'=>'',
        'maps_query'=>'المركز الوطني لتنمية القطاع غير الربحي','source_url'=>'https://ncnp.gov.sa/ar/services/%D8%B7%D9%84%D8%A8-%D8%B4%D8%B1%D8%A7%D9%83%D8%A9-%D8%A7%D8%B3%D8%AA%D8%B1%D8%A7%D8%AA%D9%8A%D8%AC%D9%8A%D8%A9',
        'verification_level'=>'full_contact','maps_enabled'=>1,'support_scope'=>'authority',
        'notes'=>'خدمة العملاء 19918 والبريد mc@ncnp.gov.sa منشوران في صفحة خدمة رسمية، مع صفحة تواصل رسمية للمركز.','priority'=>120,
    ],
    'ncec' => [
        'entity_name'=>'المركز الوطني للرقابة على الالتزام البيئي','aliases'=>'المركز الوطني للرقابة على الالتزام البيئي|NCEC|ncec.gov.sa|الالتزام البيئي',
        'phone'=>'920014961','email'=>'Info@ncec.gov.sa','support_url'=>'https://ncec.gov.sa/ar/HowCanWeHelp/ContactUs/Pages/default.aspx','branches_url'=>'',
        'maps_query'=>'المركز الوطني للرقابة على الالتزام البيئي','source_url'=>'https://ncec.gov.sa/ar/HowCanWeHelp/ContactUs/Pages/default.aspx',
        'verification_level'=>'full_contact','maps_enabled'=>1,'support_scope'=>'authority',
        'notes'=>'رقم الشكاوى والاستفسارات والبريد موثقان من صفحة التواصل الرسمية.','priority'=>120,
    ],
    'culture' => [
        'entity_name'=>'وزارة الثقافة','aliases'=>'وزارة الثقافة|Ministry of Culture|moc.gov.sa|الثقافة',
        'phone'=>'8001189999','email'=>'info@moc.gov.sa','support_url'=>'https://www.moc.gov.sa/','branches_url'=>'',
        'maps_query'=>'وزارة الثقافة السعودية','source_url'=>'https://engage.moc.gov.sa/tpa/terms-of-use/',
        'verification_level'=>'full_contact','maps_enabled'=>1,'support_scope'=>'authority',
        'notes'=>'رقم 8001189999 والبريد info@moc.gov.sa منشوران في نطاقات رسمية تابعة للوزارة.','priority'=>115,
    ],
    'sports' => [
        'entity_name'=>'وزارة الرياضة','aliases'=>'وزارة الرياضة|Ministry of Sport|mos.gov.sa|الرياضة',
        'phone'=>'920011344','email'=>'customer-care@mos.gov.sa','support_url'=>'https://www.mos.gov.sa/ar/about-us/contact-us','branches_url'=>'',
        'maps_query'=>'وزارة الرياضة السعودية','source_url'=>'https://www.mos.gov.sa/ar/about-us/contact-us',
        'verification_level'=>'full_contact','maps_enabled'=>1,'support_scope'=>'authority',
        'notes'=>'مركز الاتصال والبريد موثقان من صفحة تواصل معنا الرسمية.','priority'=>120,
    ],
    'media' => [
        'entity_name'=>'وزارة الإعلام','aliases'=>'وزارة الإعلام|Ministry of Media|media.gov.sa|الإعلام',
        'phone'=>'0112974700','email'=>'info@media.gov.sa','support_url'=>'https://media.gov.sa/','branches_url'=>'',
        'maps_query'=>'وزارة الإعلام السعودية','source_url'=>'https://media.gov.sa/ar/news/2599',
        'verification_level'=>'full_contact','maps_enabled'=>1,'support_scope'=>'authority',
        'notes'=>'مركز الاتصال والبريد منشوران في تذييل صفحات رسمية لوزارة الإعلام.','priority'=>115,
    ],
    'awqaf' => [
        'entity_name'=>'الهيئة العامة للأوقاف','aliases'=>'الهيئة العامة للأوقاف|Awqaf|awqaf.gov.sa|الأوقاف',
        'phone'=>'8003030066','email'=>'care@awqaf.gov.sa','support_url'=>'https://www.awqaf.gov.sa/en/help/contact-us','branches_url'=>'',
        'maps_query'=>'الهيئة العامة للأوقاف السعودية','source_url'=>'https://web.awqaf.gov.sa/ar/services/mubean',
        'verification_level'=>'full_contact','maps_enabled'=>1,'support_scope'=>'authority',
        'notes'=>'رقم العناية والبريد موثقان في صفحة خدمة رسمية للهيئة، وصفحة التواصل الرسمية متاحة.','priority'=>120,
    ],
    'lcgpa' => [
        'entity_name'=>'هيئة المحتوى المحلي والمشتريات الحكومية','aliases'=>'هيئة المحتوى المحلي والمشتريات الحكومية|LCGPA|lcgpa.gov.sa|المحتوى المحلي',
        'phone'=>'','email'=>'','support_url'=>'https://my.gov.sa/ar/agencies/17825','branches_url'=>'',
        'maps_query'=>'هيئة المحتوى المحلي والمشتريات الحكومية','source_url'=>'https://my.gov.sa/ar/agencies/17825',
        'verification_level'=>'support_page','maps_enabled'=>1,'support_scope'=>'authority',
        'notes'=>'سجل الجهة الرسمي على GOV.SA يثبت قنوات التواصل؛ لم نعتمد أرقامًا أو بريدًا من مصادر غير رسمية.','priority'=>110,
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
    'وزارة السياحة'=>'tourism_ministry',
    'هيئة السوق المالية'=>'cma',
    'الهيئة الوطنية للأمن السيبراني'=>'nca',
    'سدايا'=>'sdaia',
    'الهيئة العامة للإحصاء'=>'gastat',
    'هيئة الخبراء - الأنظمة السعودية'=>'boe',
    'جريدة أم القرى'=>'uqn',
    'منصة اعتماد'=>'etimad',
    'منشآت'=>'monshaat',
    'المساحة الجيولوجية السعودية'=>'sgs',
    'المؤسسة العامة للتدريب التقني والمهني'=>'tvtc',
    'وزارة الخارجية'=>'mofa',
    'وزارة المالية'=>'mof',
    'الهيئة العامة للموانئ — موانئ'=>'mawani',
    'الهيئة السعودية للمواصفات والمقاييس والجودة'=>'saso',
    'كود البناء السعودي'=>'sbc',
    'المركز الوطني لتنمية القطاع غير الربحي'=>'ncnp',
    'المركز الوطني للرقابة على الالتزام البيئي'=>'ncec',
    'وزارة الثقافة'=>'culture',
    'وزارة الرياضة'=>'sports',
    'وزارة الإعلام'=>'media',
    'الهيئة العامة للأوقاف'=>'awqaf',
    'هيئة المحتوى المحلي والمشتريات الحكومية'=>'lcgpa',
];

$getSource = $pdo->prepare("SELECT id FROM source_registry WHERE status='active' AND name=? LIMIT 1");
$getContact = $pdo->prepare("SELECT id FROM official_entity_contacts WHERE entity_key=? LIMIT 1");
$map = $pdo->prepare("INSERT INTO official_source_support(source_id,contact_id,support_scope,verified_at,notes,updated_at)
VALUES(?,?,?,'2026-08-26',?,CURRENT_TIMESTAMP)
ON CONFLICT(source_id) DO UPDATE SET contact_id=excluded.contact_id,support_scope=excluded.support_scope,verified_at=excluded.verified_at,notes=excluded.notes,updated_at=CURRENT_TIMESTAMP");

$updated = 0;
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
    $map->execute([(int)$sourceId,(int)$contactId,$profiles[$contactKey]['support_scope'],'تم إثراء قناة التصعيد من مصدر رسمي موثق في v2.0.10.']);
    $updated++;
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

$ok = $integrity==='ok' && $fk===0 && $mapped===$active && count($missing)===0 && $counts['official_site_only']===0;

echo json_encode([
    'ok'=>$ok,
    'version'=>'2.0.10',
    'db_backup'=>basename($backup),
    'active_sources'=>$active,
    'mapped_sources'=>$mapped,
    'coverage_percent'=>$active>0?round(($mapped/$active)*100,1):100,
    'full_contact'=>$counts['full_contact'],
    'support_page'=>$counts['support_page'],
    'official_site_only'=>$counts['official_site_only'],
    'total_classified'=>array_sum($counts),
    'enriched_this_release'=>$updated,
    'missing_sources'=>$missing,
    'integrity'=>$integrity,
    'foreign_key_errors'=>$fk,
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
