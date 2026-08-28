<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use Khatauat\Core\Database;
use Khatauat\Core\Settings;

$pdo = Database::connection();
$dbPath = (string)config('db_path');
$backup = preg_replace('/\.sqlite$/', '.before-v2.0.8.'.date('Ymd_His').'.sqlite', $dbPath) ?: ($dbPath.'.before-v2.0.8');
if (is_file($dbPath) && !copy($dbPath, $backup)) {
    fwrite(STDERR, "Failed to backup database\n");
    exit(1);
}

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    $cols = $pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        if ((string)$col['name'] === $column) return;
    }
    $pdo->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.$definition);
}

// Cumulative base: quota + diagnostic knowledge + official contacts.
$pdo->exec("CREATE TABLE IF NOT EXISTS user_ai_daily_usage (
    user_id INTEGER NOT NULL,
    usage_date TEXT NOT NULL,
    request_count INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(user_id,usage_date),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
)");
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_user_ai_usage_date ON user_ai_daily_usage(usage_date,user_id)');

$pdo->exec("CREATE TABLE IF NOT EXISTS service_problem_knowledge (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    knowledge_key TEXT NOT NULL UNIQUE,
    service_id INTEGER,
    title TEXT NOT NULL,
    trigger_terms TEXT NOT NULL,
    verified_facts_json TEXT NOT NULL DEFAULT '[]',
    diagnostic_questions_json TEXT NOT NULL DEFAULT '[]',
    source_title TEXT NOT NULL,
    source_url TEXT NOT NULL,
    trust_status TEXT NOT NULL DEFAULT 'needs_review' CHECK(trust_status IN ('verified','needs_review')),
    verified_at TEXT,
    priority INTEGER NOT NULL DEFAULT 50,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE SET NULL
)");
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_problem_knowledge_service ON service_problem_knowledge(service_id,trust_status,priority)');

$pdo->exec("CREATE TABLE IF NOT EXISTS official_entity_contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_key TEXT NOT NULL UNIQUE,
    entity_name TEXT NOT NULL,
    aliases TEXT,
    phone TEXT,
    email TEXT,
    support_url TEXT,
    branches_url TEXT,
    maps_query TEXT,
    source_url TEXT NOT NULL,
    trust_status TEXT NOT NULL DEFAULT 'needs_review' CHECK(trust_status IN ('verified','needs_review')),
    verified_at TEXT,
    priority INTEGER NOT NULL DEFAULT 50,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
ensureColumn($pdo, 'official_entity_contacts', 'verification_level', "TEXT NOT NULL DEFAULT 'official_site_only'");
ensureColumn($pdo, 'official_entity_contacts', 'maps_enabled', 'INTEGER NOT NULL DEFAULT 0');
ensureColumn($pdo, 'official_entity_contacts', 'support_scope', "TEXT NOT NULL DEFAULT 'authority'");
ensureColumn($pdo, 'official_entity_contacts', 'notes', 'TEXT');

$pdo->exec("CREATE TABLE IF NOT EXISTS official_service_centers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    contact_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    region TEXT,
    city TEXT,
    address TEXT,
    latitude REAL,
    longitude REAL,
    google_maps_url TEXT,
    source_url TEXT,
    verified_at TEXT,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(contact_id) REFERENCES official_entity_contacts(id) ON DELETE CASCADE
)");
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_service_centers_contact ON official_service_centers(contact_id,active,city)');

$pdo->exec("CREATE TABLE IF NOT EXISTS official_source_support (
    source_id INTEGER PRIMARY KEY,
    contact_id INTEGER NOT NULL,
    support_scope TEXT NOT NULL DEFAULT 'official_site',
    verified_at TEXT,
    notes TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(source_id) REFERENCES source_registry(id) ON DELETE CASCADE,
    FOREIGN KEY(contact_id) REFERENCES official_entity_contacts(id) ON DELETE CASCADE
)");
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_source_support_contact ON official_source_support(contact_id,source_id)');

// Keep the two first verified operational knowledge cards from v2.0.7.
$serviceId = $pdo->query("SELECT id FROM services WHERE slug='commercial-registration-sole-proprietorship' LIMIT 1")->fetchColumn();
$serviceId = $serviceId !== false ? (int)$serviceId : null;
$knowledge = [
    [
        'commercial_registration_transfer_followups', null,
        'ما بعد نقل ملكية السجل التجاري لمؤسسة فردية',
        'نقل السجل|نقل ملكية السجل|نقلت السجل|تنازل عن المؤسسة|المالك السابق|المالك الجديد|بعد نقل السجل|ما زال باسم المالك السابق',
        json_encode([
            'قبل نقل الملكية توصي وزارة التجارة بالتحقق من التزامات السجل وخلوه من المخالفات أو الغرامات أو الرسوم المتأخرة وعدم وجود دعاوى قضائية.',
            'بعد نقل ملكية سجل المؤسسة يجب استكمال الإجراءات لدى الجهات ذات العلاقة كلٌ وفق إجراءاته، ومن أبرزها وزارة الموارد البشرية والتنمية الاجتماعية، وزارة البلديات والإسكان، هيئة الزكاة والضريبة والجمارك، التأمينات الاجتماعية، الغرف التجارية، والبنوك.',
            'وجود نقل للسجل لا يعني أن جميع الخدمات التابعة لدى الجهات الأخرى قد انتقلت أو حدثت تلقائيًا.'
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        json_encode([
            ['question'=>'هل اكتملت عملية نقل ملكية السجل وأصبحت بيانات المالك الجديد ظاهرة في السجل التجاري نفسه؟','purpose'=>'إذا لم يكتمل النقل في السجل نفسه فالمشكلة في الإجراء الأساسي، أما إذا اكتمل فنتجه للجهة التابعة.','options'=>['نعم، النقل مكتمل','لا، النقل غير مكتمل','غير متأكد']],
            ['question'=>'أي جهة ما زالت تعرض بيانات المالك السابق أو لم تتحدث؟','purpose'=>'كل جهة مرتبطة لها إجراء مختلف؛ تحديد الجهة يمنع إعطاء خطوات غير مناسبة.','options'=>['قوى / الموارد البشرية','التأمينات الاجتماعية','الزكاة والضريبة والجمارك','الغرفة التجارية','بلدي / الرخص البلدية','البنك','جهة أخرى']],
            ['question'=>'ما الذي يحدث تحديدًا لدى الجهة التابعة؟','purpose'=>'عدم تحديث البيانات يختلف عن تعذر الدخول أو ظهور رسالة خطأ تقنية.','options'=>['البيانات لم تتحدث','تظهر رسالة خطأ','لا أستطيع الدخول','الخدمة غير ظاهرة','تم رفض الطلب','مشكلة أخرى']]
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        'وزارة التجارة — إجراءات نقل ملكية السجل التجاري لمؤسسة',
        'https://mc.gov.sa/ar/mediacenter/News/Pages/14-08-25-01.aspx',
        '2026-08-26', 100
    ],
    [
        'new_sole_proprietorship_auto_registrations', $serviceId,
        'التسجيلات التابعة بعد إصدار سجل مؤسسة فردية',
        'فتحت مؤسسة|مؤسسة جديدة|سجل جديد|بعد اصدار السجل|بعد إصدار السجل|الغرفة التجارية|تفعيل الغرفة|التسجيل التلقائي|لم يظهر في التأمينات|لم يظهر في الزكاة',
        json_encode([
            'بعد إصدار سجل المؤسسة الفردية يتم التسجيل تلقائيًا لدى وزارة الموارد البشرية، هيئة الزكاة والضريبة والجمارك، التأمينات الاجتماعية، البريد السعودي (العنوان الوطني)، والغرفة التجارية وفق صفحة الخدمة الرسمية.',
            'إذا لم يظهر التسجيل لدى جهة مرتبطة، يجب أولًا تحديد الجهة ونوع المشكلة قبل اقتراح أي إجراء إضافي.',
            'المصدر الرسمي يثبت التسجيل التلقائي في الغرفة التجارية، لكنه لا يكفي وحده لإثبات أي ادعاء إضافي عن مجانية خدمة أو إجراء غير مذكور صراحة.'
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        json_encode([
            ['question'=>'هل تم إصدار السجل التجاري رسميًا وأصبحت حالته نشطة؟','purpose'=>'التسجيلات التابعة تبدأ بعد إصدار السجل، لذلك هذه المعلومة تحسم نقطة البداية.','options'=>['نعم، السجل صادر ونشط','صدر لكنه ليس نشطًا','الطلب لم يكتمل بعد']],
            ['question'=>'أي جهة لم يظهر فيها التسجيل أو البيانات بعد إصدار السجل؟','purpose'=>'نحتاج تحديد الجهة لأن حل الغرفة يختلف عن التأمينات أو الزكاة أو الموارد البشرية.','options'=>['الموارد البشرية / قوى','الزكاة والضريبة والجمارك','التأمينات الاجتماعية','العنوان الوطني','الغرفة التجارية','جهة أخرى']],
            ['question'=>'هل المشكلة عدم ظهور المنشأة أم عدم القدرة على الدخول أو التفعيل؟','purpose'=>'هذا يفرق بين تأخر مزامنة البيانات وبين مشكلة تقنية في الحساب.','options'=>['المنشأة لا تظهر','البيانات قديمة','تعذر الدخول','تظهر رسالة خطأ','لا أعرف']]
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        'المنصة الوطنية — قيد سجل تجاري لمؤسسة فردية',
        'https://my.gov.sa/ar/services/240897',
        '2026-08-26', 95
    ],
];
$ks = $pdo->prepare("INSERT INTO service_problem_knowledge(knowledge_key,service_id,title,trigger_terms,verified_facts_json,diagnostic_questions_json,source_title,source_url,trust_status,verified_at,priority,updated_at)
VALUES(?,?,?,?,?,?,?,?,'verified',?,?,CURRENT_TIMESTAMP)
ON CONFLICT(knowledge_key) DO UPDATE SET service_id=excluded.service_id,title=excluded.title,trigger_terms=excluded.trigger_terms,verified_facts_json=excluded.verified_facts_json,diagnostic_questions_json=excluded.diagnostic_questions_json,source_title=excluded.source_title,source_url=excluded.source_url,trust_status='verified',verified_at=excluded.verified_at,priority=excluded.priority,updated_at=CURRENT_TIMESTAMP");
foreach ($knowledge as $k) $ks->execute($k);

// Verified contact profiles. Empty fields intentionally remain empty when the official source does not expose them clearly.
$profiles = [
    'dga'=>['هيئة الحكومة الرقمية / آمر','هيئة الحكومة الرقمية|DGA|آمر|المنصة الوطنية|GOV.SA|رَقمي|البيانات المفتوحة','199099','ecare@dga.gov.sa','https://dga.gov.sa/ar/contact','','هيئة الحكومة الرقمية','https://dga.gov.sa/ar/contact','full_contact',1,'authority','قنوات رسمية؛ تستخدم كمرجع تصعيد للمنصات الوطنية التي تديرها/تنظمها الهيئة.',120],
    'moi'=>['وزارة الداخلية','وزارة الداخلية|الجوازات|المديرية العامة للجوازات','0114011111','','https://www.moi.gov.sa/','','وزارة الداخلية السعودية','https://www.moi.gov.sa/wps/portal/Home/Home/dp-home/','support_page',1,'authority','رقم مقر الوزارة من موقع وزارة الداخلية؛ خدمات أبشر لها ملف دعم مستقل.',90],
    'absher'=>['منصة أبشر','أبشر|Absher|أبشر أفراد','920020405','HD@absher.sa','https://www.absher.sa/wps/portal/individuals/static/footer/contact-us-content/','','','https://www.absher.sa/wps/portal/individuals/static/footer/contact-us-content/','full_contact',0,'platform','دعم فني رسمي لأبشر 24/7 بحسب صفحة الاتصال.',140],
    'moj'=>['وزارة العدل / ناجز','وزارة العدل|ناجز|Najiz|التواصل العدلي','1950','1950@moj.gov.sa','https://www.moj.gov.sa/ar/Ministry/Pages/ContactUs.aspx','','مركز ناجز للخدمات العدلية','https://www.moj.gov.sa/ar/Ministry/Pages/ContactUs.aspx','full_contact',1,'authority','قنوات التواصل العدلي الرسمية وتستخدم لناجز عند الحاجة.',130],
    'commerce'=>['وزارة التجارة / المركز السعودي للأعمال','وزارة التجارة|المركز السعودي للأعمال|السجل التجاري|التجارة|الغرفة التجارية|business.sa','1900','CS@mc.gov.sa','https://mc.gov.sa/ar/contactus/pages/default.aspx','https://mc.gov.sa/ar/branches','وزارة التجارة السعودية','https://mc.gov.sa/ar/contactus/pages/default.aspx','full_contact',1,'authority','قنوات الوزارة الرسمية؛ المركز السعودي للأعمال منصة تنفيذ مرتبطة بالوزارة.',140],
    'balady'=>['منصة بلدي / وزارة البلديات والإسكان','بلدي|وزارة البلديات والإسكان|الرخص البلدية|رخصة بناء|رخصة تجارية','199040','infocs@momah.gov.sa','https://balady.gov.sa/ar/help-and-support/contact-us','','مركز خدمة بلدي','https://balady.gov.sa/ar/help-and-support/contact-us','full_contact',1,'platform','قنوات دعم بلدي الرسمية.',130],
    'zatca'=>['هيئة الزكاة والضريبة والجمارك','هيئة الزكاة والضريبة والجمارك|زاتكا|ZATCA|الزكاة|الضريبة|الجمارك','19993','info@zatca.gov.sa','https://zatca.gov.sa/ar/ContactUs/ContactRequests/Pages/default.aspx','https://zatca.gov.sa/ar/ContactUs/Pages/geo-branches.aspx','هيئة الزكاة والضريبة والجمارك','https://zatca.gov.sa/ar/eServices/Pages/eServices_260.aspx','full_contact',1,'authority','رقم 19993 والبريد منشوران في صفحات خدمات رسمية للهيئة؛ الفروع من دليل الهيئة.',135],
    'hrsd'=>['وزارة الموارد البشرية والتنمية الاجتماعية','وزارة الموارد البشرية|الموارد البشرية|HRSD|قوى|Qiwa|مسار','19911','','https://www.hrsd.gov.sa/contact-us','','وزارة الموارد البشرية والتنمية الاجتماعية','https://www.hrsd.gov.sa/contact-us','support_page',1,'authority','قناة الوزارة الرسمية. قوى ومسار يرثان هذا الملف عند عدم وجود دعم منصة موثق داخل السجل.',125],
    'musaned'=>['منصة مساند','مساند|Musaned|العمالة المنزلية','920002866','care.e@musaned.gov.sa','https://musaned.com.sa/ar/contact-us','','','https://musaned.com.sa/ar/contact-us','full_contact',0,'platform','دعم مساند الرسمي 24/7.',135],
    'hrdf'=>['صندوق تنمية الموارد البشرية — هدف','هدف|HRDF|صندوق تنمية الموارد البشرية|جدارات','8001222030','','https://www.hrdf.org.sa/help-support/','https://www.hrdf.org.sa/help-support/','صندوق تنمية الموارد البشرية هدف','https://www.hrdf.org.sa/help-support/','support_page',1,'authority','الرقم المجاني والفروع/المراكز موثقة من صفحة الدعم الرسمية.',120],
    'gosi'=>['المؤسسة العامة للتأمينات الاجتماعية','التأمينات|التأمينات الاجتماعية|GOSI|ساند','199044','INFO1@GOSI.GOV.SA','https://www.gosi.gov.sa/ar/ContactUs','','المؤسسة العامة للتأمينات الاجتماعية','https://www.gosi.gov.sa/ar/ContactUs','full_contact',1,'authority','قنوات التواصل الرسمية.',135],
    'sdb'=>['بنك التنمية الاجتماعية','بنك التنمية الاجتماعية|SDB|بنك التنمية','920008002','care@sdb.gov.sa','https://www.sdb.gov.sa/ar/%D8%AA%D9%88%D8%A7%D8%B5%D9%84-%D9%85%D8%B9%D9%86%D8%A7','https://www.sdb.gov.sa/ar/%D8%AA%D9%88%D8%A7%D8%B5%D9%84-%D9%85%D8%B9%D9%86%D8%A7','بنك التنمية الاجتماعية','https://ncnp.gov.sa/ar/taxonomy/term/119','full_contact',1,'authority','الرقم والبريد منشوران في صفحة حكومية رسمية للمركز الوطني لتنمية القطاع غير الربحي؛ رابط الدعم مباشر للبنك.',110],
    'moh'=>['وزارة الصحة','وزارة الصحة|MOH|صحتي|937','937','','https://www.moh.gov.sa/937/pages/default.aspx','','وزارة الصحة السعودية','https://www.moh.gov.sa/937/pages/default.aspx','support_page',1,'authority','937 مركز خدمة وزارة الصحة على مدار الساعة.',125],
    'chi'=>['مجلس الضمان الصحي','مجلس الضمان الصحي|الضمان الصحي|CHI','19977','info@chi.gov.sa','https://www.chi.gov.sa/Help/Pages/Contact.aspx','','','https://www.chi.gov.sa/Help/Pages/Contact.aspx','full_contact',0,'authority','قنوات رسمية للمجلس.',120],
    'sfda'=>['الهيئة العامة للغذاء والدواء','الغذاء والدواء|SFDA|الهيئة العامة للغذاء والدواء','19999','','https://www.sfda.gov.sa/ar/contact-us','','الهيئة العامة للغذاء والدواء','https://www.sfda.gov.sa/ar/contact-us','support_page',1,'authority','رقم الاستفسارات الموحد منشور في صفحة التواصل الرسمية.',120],
    'moe'=>['وزارة التعليم','وزارة التعليم|MOE|نظام نور|نور|ادرس في السعودية|Study in Saudi','19996','','https://www.moe.gov.sa/ar/contactus/Pages/default.aspx','','إدارة التعليم','https://www.moe.gov.sa/ar/contactus/Pages/default.aspx','support_page',1,'authority','مركز رعاية المستفيدين 19996؛ يستخدم لنور عند تعثر الدعم الفني.',125],
    'misa'=>['وزارة الاستثمار / استثمر في السعودية','وزارة الاستثمار|MISA|استثمر في السعودية|Invest Saudi','8002449990','InvestorCare@misa.gov.sa','https://investsaudi.sa/contact-us','','وزارة الاستثمار السعودية','https://investsaudi.sa/contact-us','full_contact',1,'authority','قنوات رعاية المستثمر الرسمية.',120],
    'mim'=>['وزارة الصناعة والثروة المعدنية','وزارة الصناعة|وزارة الصناعة والثروة المعدنية|MIM|الصناعة|التعدين','','','https://www.mim.gov.sa/ar/contact-us/contact-ministry','','وزارة الصناعة والثروة المعدنية','https://www.mim.gov.sa/ar/contact-us/contact-ministry','support_page',1,'authority','رابط تواصل رسمي؛ لا يتم عرض رقم أو بريد ما لم يكن موثقًا بوضوح.',95],
    'rega'=>['الهيئة العامة للعقار','الهيئة العامة للعقار|REGA|إيجار|Ejar|السجل العقاري','','','https://rega.gov.sa/contact-us/','','الهيئة العامة للعقار','https://rega.gov.sa/contact-us/','support_page',1,'authority','قنوات الهيئة الرسمية؛ إيجار يرث الملف عند عدم توفر ملف دعم منفصل موثق.',110],
    'tga'=>['الهيئة العامة للنقل','الهيئة العامة للنقل|TGA|النقل','19929','19929@tga.gov.sa','https://www.tga.gov.sa/','','الهيئة العامة للنقل','http://www.tga.gov.sa/ar/ActivitiesServices/TGAServices/Service/1','full_contact',1,'authority','الرقم والبريد منشوران في صفحات الخدمات الرسمية.',115],
    'haj'=>['وزارة الحج والعمرة / نسك','وزارة الحج والعمرة|الحج|العمرة|نسك|Nusuk|نسك حج','','','https://haj.gov.sa/ar/Contact-us','','وزارة الحج والعمرة','https://haj.gov.sa/ar/Contact-us','support_page',1,'authority','رابط التواصل الرسمي؛ لا يتم تثبيت رقم أو بريد ما لم يظهر في المصدر الرسمي المقروء.',105],
    'sama'=>['البنك المركزي السعودي','البنك المركزي السعودي|ساما|SAMA|ساما تهتم','8001256666','CPDC@SAMA.GOV.SA','https://www.sama.gov.sa/ar-sa/PortalServices/pages/contactus.aspx','https://www.sama.gov.sa/ar-sa/PortalServices/pages/contactus.aspx','البنك المركزي السعودي','https://www.sama.gov.sa/ar-sa/PortalServices/pages/contactus.aspx','full_contact',1,'authority','مركز التواصل والشكاوى الرسمي على مدار الساعة.',125],
    'cst'=>['هيئة الاتصالات والفضاء والتقنية','هيئة الاتصالات|CST|متصل|الاتصالات والفضاء والتقنية','19966','info@cst.gov.sa','https://mutasil.cst.gov.sa/SupportAndAssistance','','','https://mutasil.cst.gov.sa/SupportAndAssistance','full_contact',0,'platform','قنوات الدعم الرسمية عبر منصة متصل.',125],
    'gaca'=>['الهيئة العامة للطيران المدني','الهيئة العامة للطيران المدني|GACA|الطيران المدني','1929','1929@gaca.gov.sa','https://gaca.gov.sa/ar/Helping-And-Support/Contact-Us','','الهيئة العامة للطيران المدني','https://gaca.gov.sa/ar/Helping-And-Support/Contact-Us','full_contact',1,'authority','قنوات مركز العناية بالمستفيدين الرسمية 24/7.',120],
    'saip'=>['الهيئة السعودية للملكية الفكرية','الهيئة السعودية للملكية الفكرية|SAIP|الملكية الفكرية','920021421','Saip@saip.gov.sa','https://saip.gov.sa/ar/contact-us/contact-and-support','','الهيئة السعودية للملكية الفكرية','https://mc.gov.sa/ar/mediacenter/News/Pages/20-07-22-01.aspx','full_contact',1,'authority','قنوات الاتصال منشورة في مصدر حكومي رسمي، والدعم عبر موقع الهيئة الرسمي.',115],
];

$upsertContact = $pdo->prepare("INSERT INTO official_entity_contacts(entity_key,entity_name,aliases,phone,email,support_url,branches_url,maps_query,source_url,trust_status,verified_at,priority,verification_level,maps_enabled,support_scope,notes,updated_at)
VALUES(?,?,?,?,?,?,?,?,?,'verified','2026-08-26',?,?,?,?,?,CURRENT_TIMESTAMP)
ON CONFLICT(entity_key) DO UPDATE SET entity_name=excluded.entity_name,aliases=excluded.aliases,phone=excluded.phone,email=excluded.email,support_url=excluded.support_url,branches_url=excluded.branches_url,maps_query=excluded.maps_query,source_url=excluded.source_url,trust_status='verified',verified_at='2026-08-26',priority=excluded.priority,verification_level=excluded.verification_level,maps_enabled=excluded.maps_enabled,support_scope=excluded.support_scope,notes=excluded.notes,updated_at=CURRENT_TIMESTAMP");
foreach ($profiles as $key=>$p) {
    [$entityName,$aliases,$phone,$email,$supportUrl,$branchesUrl,$mapsQuery,$sourceUrl,$level,$mapsEnabled,$scope,$notes,$priority]=$p;
    $upsertContact->execute([$key,$entityName,$aliases,$phone,$email,$supportUrl,$branchesUrl,$mapsQuery,$sourceUrl,$priority,$level,$mapsEnabled,$scope,$notes]);
}

// Source -> support mapping. Every active source receives a record.
$explicitMap = [
    'المنصة الوطنية GOV.SA'=>'dga','دليل الجهات الحكومية GOV.SA'=>'dga','دليل الخدمات الحكومية GOV.SA'=>'dga','هيئة الحكومة الرقمية'=>'dga','رَقمي — سجل المنصات الحكومية'=>'dga','منصة البيانات المفتوحة'=>'dga',
    'وزارة الداخلية'=>'moi','المديرية العامة للجوازات'=>'moi','أبشر أفراد'=>'absher',
    'وزارة العدل'=>'moj','ناجز'=>'moj',
    'وزارة التجارة'=>'commerce','المركز السعودي للأعمال'=>'commerce',
    'منصة بلدي'=>'balady','وزارة البلديات والإسكان'=>'balady',
    'هيئة الزكاة والضريبة والجمارك'=>'zatca',
    'وزارة الموارد البشرية والتنمية الاجتماعية'=>'hrsd','قوى'=>'hrsd','منصة مسار'=>'hrsd',
    'مساند'=>'musaned','صندوق تنمية الموارد البشرية هدف'=>'hrdf','التأمينات الاجتماعية'=>'gosi','بنك التنمية الاجتماعية'=>'sdb',
    'وزارة الصحة'=>'moh','مجلس الضمان الصحي'=>'chi','الهيئة العامة للغذاء والدواء'=>'sfda',
    'وزارة التعليم'=>'moe','نظام نور'=>'moe','ادرس في السعودية'=>'moe',
    'وزارة الاستثمار'=>'misa','وزارة الصناعة والثروة المعدنية'=>'mim',
    'الهيئة العامة للعقار'=>'rega','إيجار'=>'rega',
    'الهيئة العامة للنقل'=>'tga',
    'وزارة الحج والعمرة'=>'haj','نسك'=>'haj','نسك حج'=>'haj',
    'البنك المركزي السعودي'=>'sama','هيئة الاتصالات والفضاء والتقنية'=>'cst',
    'الهيئة العامة للطيران المدني'=>'gaca','الهيئة السعودية للملكية الفكرية'=>'saip',
];

$physicalSourceNames = [
    'الدفاع المدني السعودي','وزارة البلديات والإسكان','وزارة البيئة والمياه والزراعة','وزارة الطاقة','وزارة النقل والخدمات اللوجستية','وزارة السياحة','وزارة الخارجية','وزارة المالية','الهيئة العامة للموانئ — موانئ','الهيئة السعودية للمواصفات والمقاييس والجودة','المؤسسة العامة للتدريب التقني والمهني','المساحة الجيولوجية السعودية','الهيئة العامة للإحصاء','وزارة الثقافة','وزارة الرياضة','وزارة الإعلام','الهيئة العامة للأوقاف','هيئة المحتوى المحلي والمشتريات الحكومية','المركز الوطني لتنمية القطاع غير الربحي','المركز الوطني للرقابة على الالتزام البيئي','وزارة الصناعة والثروة المعدنية','مدن','وزارة الاستثمار'
];
$physicalLookup=array_fill_keys($physicalSourceNames,true);

$getContactId = $pdo->prepare('SELECT id FROM official_entity_contacts WHERE entity_key=? LIMIT 1');
$mapStmt = $pdo->prepare("INSERT INTO official_source_support(source_id,contact_id,support_scope,verified_at,notes,updated_at)
VALUES(?,?,?,'2026-08-26',?,CURRENT_TIMESTAMP)
ON CONFLICT(source_id) DO UPDATE SET contact_id=excluded.contact_id,support_scope=excluded.support_scope,verified_at=excluded.verified_at,notes=excluded.notes,updated_at=CURRENT_TIMESTAMP");

$sources = $pdo->query("SELECT id,name,entity,domain,url,source_role,status FROM source_registry WHERE status='active' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$fallbackCount=0;
foreach ($sources as $src) {
    $name=(string)$src['name'];
    $key=$explicitMap[$name] ?? '';
    $scope='official_site';
    $notes='ملف تصعيد احتياطي إلى الموقع الرسمي حتى يتم توثيق قنوات دعم أكثر تفصيلاً.';
    if ($key!=='') {
        $getContactId->execute([$key]);
        $contactId=$getContactId->fetchColumn();
        if ($contactId!==false) {
            $scope=in_array($name,['أبشر أفراد','منصة بلدي','مساند','هيئة الاتصالات والفضاء والتقنية'],true)?'platform':'authority';
            $mapStmt->execute([(int)$src['id'],(int)$contactId,$scope,'يرث قناة الدعم الموثقة للمنصة أو الجهة المالكة.']);
            continue;
        }
    }

    // Guaranteed safe fallback: official source URL only. No phone/email invented.
    $fallbackKey='source_'.(int)$src['id'];
    $mapsEnabled=isset($physicalLookup[$name])?1:0;
    $mapsQuery=$mapsEnabled ? ($name.' السعودية') : '';
    $fallbackAliases=implode('|',array_values(array_unique(array_filter([$name,(string)$src['entity'],(string)$src['domain']]))));
    $upsertContact->execute([
        $fallbackKey,
        $name,
        $fallbackAliases,
        '',
        '',
        (string)$src['url'],
        '',
        $mapsQuery,
        (string)$src['url'],
        20,
        'official_site_only',
        $mapsEnabled,
        'official_site',
        'تم التحقق من المصدر كسجل معتمد في خطوات. لم يتم إدخال هاتف أو بريد لعدم توفر دليل رسمي كافٍ داخل قاعدة الاتصال الحالية.'
    ]);
    $getContactId->execute([$fallbackKey]);
    $contactId=$getContactId->fetchColumn();
    if($contactId===false) throw new RuntimeException('Failed fallback contact for source '.$src['id']);
    $mapStmt->execute([(int)$src['id'],(int)$contactId,'official_site',$notes]);
    $fallbackCount++;
}

// Add source names/domains to mapped contact aliases to improve deterministic matching.
$mapped = $pdo->query("SELECT c.id,c.aliases,s.name,s.entity,s.domain FROM official_source_support m JOIN official_entity_contacts c ON c.id=m.contact_id JOIN source_registry s ON s.id=m.source_id WHERE s.status='active'")->fetchAll(PDO::FETCH_ASSOC);
$aliasUpdate=$pdo->prepare('UPDATE official_entity_contacts SET aliases=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
foreach($mapped as $row){
    $parts=array_filter(array_map('trim',explode('|',(string)$row['aliases'])));
    foreach([(string)$row['name'],(string)$row['entity'],(string)$row['domain']] as $v){if($v!=='')$parts[]=$v;}
    $parts=array_values(array_unique($parts));
    $aliasUpdate->execute([implode('|',$parts),(int)$row['id']]);
}

Settings::set('public_ai_enabled','1');
Settings::set('free_ai_daily_limit','3');
Settings::set('problem_solver_enabled','1');
Settings::set('geolocation_branch_helper_enabled','1');
Settings::set('problem_solver_question_policy','decision_relevant_only');
Settings::set('national_escalation_directory_enabled','1');
Settings::set('geolocation_storage','none');

$activeSources=(int)$pdo->query("SELECT COUNT(*) FROM source_registry WHERE status='active'")->fetchColumn();
$mappedSources=(int)$pdo->query("SELECT COUNT(*) FROM official_source_support m JOIN source_registry s ON s.id=m.source_id WHERE s.status='active'")->fetchColumn();
$full=(int)$pdo->query("SELECT COUNT(DISTINCT m.source_id) FROM official_source_support m JOIN official_entity_contacts c ON c.id=m.contact_id JOIN source_registry s ON s.id=m.source_id WHERE s.status='active' AND c.verification_level='full_contact'")->fetchColumn();
$support=(int)$pdo->query("SELECT COUNT(DISTINCT m.source_id) FROM official_source_support m JOIN official_entity_contacts c ON c.id=m.contact_id JOIN source_registry s ON s.id=m.source_id WHERE s.status='active' AND c.verification_level='support_page'")->fetchColumn();
$siteOnly=(int)$pdo->query("SELECT COUNT(DISTINCT m.source_id) FROM official_source_support m JOIN official_entity_contacts c ON c.id=m.contact_id JOIN source_registry s ON s.id=m.source_id WHERE s.status='active' AND c.verification_level='official_site_only'")->fetchColumn();
$unmapped=max(0,$activeSources-$mappedSources);
$integrity=$pdo->query('PRAGMA integrity_check')->fetchColumn();
$fk=count($pdo->query('PRAGMA foreign_key_check')->fetchAll());

echo json_encode([
    'ok'=>$integrity==='ok' && $fk===0 && $unmapped===0,
    'version'=>'2.0.8',
    'db_backup'=>basename($backup),
    'active_sources'=>$activeSources,
    'mapped_sources'=>$mappedSources,
    'coverage_percent'=>$activeSources>0?round(($mappedSources/$activeSources)*100,1):100,
    'full_contact_sources'=>$full,
    'support_page_sources'=>$support,
    'official_site_only_sources'=>$siteOnly,
    'fallback_profiles_created'=>$fallbackCount,
    'unmapped_sources'=>$unmapped,
    'question_policy'=>'decision_relevant_only',
    'geolocation_storage'=>'none',
    'appointment_booking'=>'disabled',
    'integrity'=>$integrity,
    'foreign_key_errors'=>$fk,
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
