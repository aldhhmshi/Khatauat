<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use Khatauat\Core\Database;
use Khatauat\Core\Settings;

$dbPath=(string)config('db_path');
$backup=preg_replace('/\.sqlite$/','.before-v2.0.7.'.date('Ymd_His').'.sqlite',$dbPath) ?: ($dbPath.'.before-v2.0.7');
if (is_file($dbPath) && !copy($dbPath,$backup)) {
    fwrite(STDERR,"Failed to backup database\n"); exit(1);
}
$pdo=Database::connection();

// v2.0.7 is cumulative: ensure the public AI quota table exists even if v2.0.6 was not applied separately.
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

$serviceId=$pdo->query("SELECT id FROM services WHERE slug='commercial-registration-sole-proprietorship' LIMIT 1")->fetchColumn();
$serviceId=$serviceId!==false?(int)$serviceId:null;

$knowledge=[
[
 'knowledge_key'=>'commercial_registration_transfer_followups',
 'service_id'=>null,
 'title'=>'ما بعد نقل ملكية السجل التجاري لمؤسسة فردية',
 'trigger_terms'=>'نقل السجل|نقل ملكية السجل|نقلت السجل|تنازل عن المؤسسة|المالك السابق|المالك الجديد|بعد نقل السجل|ما زال باسم المالك السابق',
 'verified_facts_json'=>json_encode([
   'قبل نقل الملكية توصي وزارة التجارة بالتحقق من التزامات السجل وخلوه من المخالفات أو الغرامات أو الرسوم المتأخرة وعدم وجود دعاوى قضائية.',
   'بعد نقل ملكية سجل المؤسسة يجب استكمال الإجراءات لدى الجهات ذات العلاقة كلٌ وفق إجراءاته، ومن أبرزها وزارة الموارد البشرية والتنمية الاجتماعية، وزارة البلديات والإسكان، هيئة الزكاة والضريبة والجمارك، التأمينات الاجتماعية، الغرف التجارية، والبنوك.',
   'وجود نقل للسجل لا يعني أن جميع الخدمات التابعة لدى الجهات الأخرى قد انتقلت أو حدثت تلقائيًا.'
 ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
 'diagnostic_questions_json'=>json_encode([
   ['question'=>'هل اكتملت عملية نقل ملكية السجل وأصبحت بيانات المالك الجديد ظاهرة في السجل التجاري نفسه؟','purpose'=>'إذا لم يكتمل النقل في السجل نفسه فالمشكلة في الإجراء الأساسي، أما إذا اكتمل فنتجه للجهة التابعة.','options'=>['نعم، النقل مكتمل','لا، النقل غير مكتمل','غير متأكد']],
   ['question'=>'أي جهة ما زالت تعرض بيانات المالك السابق أو لم تتحدث؟','purpose'=>'كل جهة مرتبطة لها إجراء مختلف؛ تحديد الجهة يمنع إعطاء خطوات غير مناسبة.','options'=>['قوى / الموارد البشرية','التأمينات الاجتماعية','الزكاة والضريبة والجمارك','الغرفة التجارية','بلدي / الرخص البلدية','البنك','جهة أخرى']],
   ['question'=>'ما الذي يحدث تحديدًا لدى الجهة التابعة؟','purpose'=>'عدم تحديث البيانات يختلف عن تعذر الدخول أو ظهور رسالة خطأ تقنية.','options'=>['البيانات لم تتحدث','تظهر رسالة خطأ','لا أستطيع الدخول','الخدمة غير ظاهرة','تم رفض الطلب','مشكلة أخرى']]
 ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
 'source_title'=>'وزارة التجارة — إجراءات نقل ملكية السجل التجاري لمؤسسة',
 'source_url'=>'https://mc.gov.sa/ar/mediacenter/News/Pages/14-08-25-01.aspx',
 'verified_at'=>'2026-08-26','priority'=>100
],
[
 'knowledge_key'=>'new_sole_proprietorship_auto_registrations',
 'service_id'=>$serviceId,
 'title'=>'التسجيلات التابعة بعد إصدار سجل مؤسسة فردية',
 'trigger_terms'=>'فتحت مؤسسة|مؤسسة جديدة|سجل جديد|بعد اصدار السجل|بعد إصدار السجل|الغرفة التجارية|تفعيل الغرفة|التسجيل التلقائي|لم يظهر في التأمينات|لم يظهر في الزكاة',
 'verified_facts_json'=>json_encode([
   'بعد إصدار سجل المؤسسة الفردية يتم التسجيل تلقائيًا لدى وزارة الموارد البشرية، هيئة الزكاة والضريبة والجمارك، التأمينات الاجتماعية، البريد السعودي (العنوان الوطني)، والغرفة التجارية وفق صفحة الخدمة الرسمية.',
   'إذا لم يظهر التسجيل لدى جهة مرتبطة، يجب أولًا تحديد الجهة ونوع المشكلة قبل اقتراح أي إجراء إضافي.',
   'المصدر الرسمي يثبت التسجيل التلقائي في الغرفة التجارية، لكنه لا يكفي وحده لإثبات أي ادعاء إضافي عن مجانية خدمة أو إجراء غير مذكور صراحة.'
 ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
 'diagnostic_questions_json'=>json_encode([
   ['question'=>'هل تم إصدار السجل التجاري رسميًا وأصبحت حالته نشطة؟','purpose'=>'التسجيلات التابعة تبدأ بعد إصدار السجل، لذلك هذه المعلومة تحسم نقطة البداية.','options'=>['نعم، السجل صادر ونشط','صدر لكنه ليس نشطًا','الطلب لم يكتمل بعد']],
   ['question'=>'أي جهة لم يظهر فيها التسجيل أو البيانات بعد إصدار السجل؟','purpose'=>'نحتاج تحديد الجهة لأن حل الغرفة يختلف عن التأمينات أو الزكاة أو الموارد البشرية.','options'=>['الموارد البشرية / قوى','الزكاة والضريبة والجمارك','التأمينات الاجتماعية','العنوان الوطني','الغرفة التجارية','جهة أخرى']],
   ['question'=>'هل المشكلة عدم ظهور المنشأة أم عدم القدرة على الدخول أو التفعيل؟','purpose'=>'هذا يفرق بين تأخر مزامنة البيانات وبين مشكلة تقنية في الحساب.','options'=>['المنشأة لا تظهر','البيانات قديمة','تعذر الدخول','تظهر رسالة خطأ','لا أعرف']]
 ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
 'source_title'=>'المنصة الوطنية — قيد سجل تجاري لمؤسسة فردية',
 'source_url'=>'https://my.gov.sa/ar/services/240897',
 'verified_at'=>'2026-08-26','priority'=>95
]
];

$ks=$pdo->prepare("INSERT INTO service_problem_knowledge(knowledge_key,service_id,title,trigger_terms,verified_facts_json,diagnostic_questions_json,source_title,source_url,trust_status,verified_at,priority,updated_at)
VALUES(?,?,?,?,?,?,?,?, 'verified', ?, ?, CURRENT_TIMESTAMP)
ON CONFLICT(knowledge_key) DO UPDATE SET service_id=excluded.service_id,title=excluded.title,trigger_terms=excluded.trigger_terms,verified_facts_json=excluded.verified_facts_json,diagnostic_questions_json=excluded.diagnostic_questions_json,source_title=excluded.source_title,source_url=excluded.source_url,trust_status='verified',verified_at=excluded.verified_at,priority=excluded.priority,updated_at=CURRENT_TIMESTAMP");
foreach($knowledge as $k){$ks->execute([$k['knowledge_key'],$k['service_id'],$k['title'],$k['trigger_terms'],$k['verified_facts_json'],$k['diagnostic_questions_json'],$k['source_title'],$k['source_url'],$k['verified_at'],$k['priority']]);}

$contacts=[
 ['commerce','وزارة التجارة','وزارة التجارة|المركز السعودي للأعمال|السجل التجاري|التجارة|الغرفة التجارية','1900','CS@mc.gov.sa','https://mc.gov.sa/ar/contactus/pages/default.aspx','https://mc.gov.sa/ar/branches','وزارة التجارة السعودية','https://mc.gov.sa/ar/contactus/pages/default.aspx','2026-08-26',100],
 ['gosi','المؤسسة العامة للتأمينات الاجتماعية','التأمينات|التأمينات الاجتماعية|GOSI','199044','INFO1@GOSI.GOV.SA','https://www.gosi.gov.sa/ar/ContactUs','','المؤسسة العامة للتأمينات الاجتماعية','https://www.gosi.gov.sa/ar/ContactUs','2026-08-26',110],
 ['balady','منصة بلدي / وزارة البلديات والإسكان','بلدي|وزارة البلديات والإسكان|الرخصة البلدية|الرخص البلدية|رخصة بناء|رخصة تجارية','199040','infocs@momah.gov.sa','https://balady.gov.sa/ar/help-and-support/contact-us','','','https://balady.gov.sa/ar/help-and-support/contact-us','2026-08-26',100],
 ['hrsd','وزارة الموارد البشرية والتنمية الاجتماعية','الموارد البشرية|وزارة الموارد البشرية|قوى|Qiwa','19911','','https://www.hrsd.gov.sa/contact-us','','وزارة الموارد البشرية والتنمية الاجتماعية','https://www.hrsd.gov.sa/contact-us','2026-08-26',105]
];
$cs=$pdo->prepare("INSERT INTO official_entity_contacts(entity_key,entity_name,aliases,phone,email,support_url,branches_url,maps_query,source_url,trust_status,verified_at,priority,updated_at)
VALUES(?,?,?,?,?,?,?,?,?,'verified',?,?,CURRENT_TIMESTAMP)
ON CONFLICT(entity_key) DO UPDATE SET entity_name=excluded.entity_name,aliases=excluded.aliases,phone=excluded.phone,email=excluded.email,support_url=excluded.support_url,branches_url=excluded.branches_url,maps_query=excluded.maps_query,source_url=excluded.source_url,trust_status='verified',verified_at=excluded.verified_at,priority=excluded.priority,updated_at=CURRENT_TIMESTAMP");
foreach($contacts as $c){$cs->execute($c);}

Settings::set('public_ai_enabled','1');
Settings::set('free_ai_daily_limit','3');
Settings::set('problem_solver_enabled','1');
Settings::set('geolocation_branch_helper_enabled','1');
Settings::set('problem_solver_question_policy','decision_relevant_only');

$integrity=$pdo->query('PRAGMA integrity_check')->fetchColumn();
$fk=count($pdo->query('PRAGMA foreign_key_check')->fetchAll());

echo json_encode([
 'ok'=>$integrity==='ok' && $fk===0,
 'version'=>'2.0.7',
 'db_backup'=>basename($backup),
 'problem_knowledge'=>(int)$pdo->query('SELECT COUNT(*) FROM service_problem_knowledge')->fetchColumn(),
 'verified_contacts'=>(int)$pdo->query("SELECT COUNT(*) FROM official_entity_contacts WHERE trust_status='verified'")->fetchColumn(),
 'service_centers'=>(int)$pdo->query('SELECT COUNT(*) FROM official_service_centers WHERE active=1')->fetchColumn(),
 'question_policy'=>'decision_relevant_only',
 'geolocation_storage'=>'none',
 'integrity'=>$integrity,
 'foreign_key_errors'=>$fk,
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
