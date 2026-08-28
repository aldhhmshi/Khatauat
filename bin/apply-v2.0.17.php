<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Khatauat\Core\Database;

$dbPath = (string)config('db_path', root_path('storage/database/khatauat.sqlite'));
$stamp = date('Ymd_His');
$backup = root_path('storage/database/khatauat.before-v2.0.17.' . $stamp . '.sqlite');
if (is_file($dbPath)) @copy($dbPath, $backup);

Database::execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS calculator_definitions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    category TEXT NOT NULL DEFAULT 'general',
    icon TEXT,
    purpose TEXT,
    engine_key TEXT NOT NULL,
    entity TEXT,
    source_label TEXT,
    source_url TEXT,
    rule_version TEXT NOT NULL DEFAULT '1.0',
    verified_at TEXT,
    disclaimer TEXT,
    sort_order INTEGER NOT NULL DEFAULT 100,
    status TEXT NOT NULL DEFAULT 'published' CHECK(status IN ('draft','published')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);

$items = [
['vat','ضريبة القيمة المضافة','tax','٪','أضف الضريبة أو استخرجها من مبلغ شامل الضريبة داخل خطوات.','vat','هيئة الزكاة والضريبة والجمارك','ZATCA — ضريبة القيمة المضافة','https://zatca.gov.sa/ar/RulesRegulations/VAT/Pages/About-Vat.aspx','2026.08','2026-08-26','النسبة الأساسية 15% وفق المصدر الرسمي، مع إمكانية تعديل النسبة للحالات الخاصة. النتيجة إرشادية ولا تغني عن المعالجة الضريبية الرسمية.',10],
['end-of-service','مكافأة نهاية الخدمة','work','✓','تقدير مكافأة نهاية الخدمة وفق مدة الخدمة والأجر وسبب انتهاء العلاقة.','end-of-service','وزارة الموارد البشرية والتنمية الاجتماعية','وزارة الموارد البشرية — حاسبة نهاية الخدمة','https://www.hrsd.gov.sa/ministry-services/services/end-service-benefit-calculator','2026.08','2026-08-26','تطبق القاعدة العامة للمادتين 84 و85 من نظام العمل. توجد حالات واستثناءات نظامية قد تغير الاستحقاق؛ راجع الجهة الرسمية قبل الاعتماد.',20],
['zakat-simple','الزكاة المختصرة','tax','ز','تقدير زكاة النقد بسرعة داخل المنصة مع إظهار النسبة المستخدمة.','zakat-simple','هيئة الزكاة والضريبة والجمارك','زكاتي — الحاسبة المختصرة والمطولة','https://zatca.gov.sa/ar/eServices/Pages/eServices-306.aspx','2026.08','2026-08-26','الحاسبة لا تقرر تحقق النصاب أو الحول نيابة عنك. القيمة تقديرية ويجب الرجوع لضوابط زكاتي عند اختلاف الحالة.',30],
['zakat-detailed','الزكاة المطولة','tax','ز+','اجمع البنود الزكوية المدخلة واحسب تقديرًا موسعًا داخل خطوات.','zakat-detailed','هيئة الزكاة والضريبة والجمارك','زكاتي — الحاسبة المختصرة والمطولة','https://zatca.gov.sa/ar/eServices/Pages/eServices-306.aspx','2026.08','2026-08-26','هذه نسخة تقديرية مبسطة وليست بديلًا عن حاسبة زكاتي المطولة أو رأي مختص في فقه ومحاسبة الزكاة.',40],
['salary-net','الراتب بعد الخصومات','work','ر','احسب صافي الراتب من الاستقطاعات الفعلية التي تدخلها دون افتراض نسب غير مناسبة لحالتك.','salary-net','وزارة الموارد البشرية والتنمية الاجتماعية','وزارة الموارد البشرية — الثقافة العمالية','https://www.hrsd.gov.sa/labor-culture','1.0',null,'أدخل الاستقطاعات الفعلية من مسير راتبك. لا تفترض الحاسبة نسبة تأمينات أو استقطاع موحدة.',50],
['wage-converter','تحويل الراتب إلى أجر يومي وساعي','work','س','حوّل الراتب الشهري إلى أجر يومي وساعي باستخدام المقسوم الذي تختاره.','wage-converter','وزارة الموارد البشرية والتنمية الاجتماعية','وزارة الموارد البشرية — الثقافة العمالية','https://www.hrsd.gov.sa/labor-culture','1.0',null,'النتيجة حسابية تعتمد على عدد الأيام والساعات التي تدخلها، وليست تحديدًا نظاميًا تلقائيًا للأجر المستحق.',60],
['service-duration','مدة الخدمة','work','م','احسب مدة الخدمة بين تاريخ البداية والنهاية بالسنوات والأشهر والأيام.','service-duration','وزارة الموارد البشرية والتنمية الاجتماعية','وزارة الموارد البشرية — الثقافة العمالية','https://www.hrsd.gov.sa/labor-culture','1.0',null,'حساب زمني مساعد. في المنازعات أو الاستحقاقات النظامية يعتمد التاريخ المعتمد لدى الجهة المختصة.',70],
['annual-leave','رصيد الإجازة السنوية','work','إ','احسب الرصيد المتبقي وقيمة إرشادية له عند إدخال الراتب.','annual-leave','وزارة الموارد البشرية والتنمية الاجتماعية','وزارة الموارد البشرية — الثقافة العمالية','https://www.hrsd.gov.sa/labor-culture','1.0',null,'أدخل الرصيد المستحق والمرحل والمستخدم كما هو مثبت لدى جهة العمل. القيمة المالية تقريبية.',80],
['loan-payment','القسط الشهري للتمويل','finance','ق','احسب القسط والتكلفة الإجمالية تقديريًا من المبلغ والنسبة والمدة.','loan-payment','البنك المركزي السعودي','SAMA Rulebook — التمويل المسؤول للأفراد','https://rulebook.sama.gov.sa/ar/','1.0','2026-08-26','محاكاة رياضية وليست عرضًا تمويليًا أو APR معتمدًا. الرسوم والتأمين وطريقة احتساب جهة التمويل قد تغير النتيجة.',90],
['debt-ratio','نسبة الالتزامات إلى الدخل','finance','٪','اعرف نسبة التزاماتك الشهرية إلى الدخل ومؤشر استقطاع الراتب.','debt-ratio','البنك المركزي السعودي','SAMA Rulebook — المبادئ الكمية للتمويل المسؤول','https://rulebook.sama.gov.sa/ar/','2026.08','2026-08-26','المؤشرات مرجعية وليست قرار أهلية ائتمانية؛ تختلف الحدود الكلية بحسب الدخل ونوع التمويل وحالة العميل وسياسة الممول.',100],
['discount','الخصم والسعر بعد الخصم','finance','−','احسب قيمة الخصم والسعر النهائي فورًا.','discount',null,'معادلة حسابية',null,'1.0',null,'حساب رياضي عام.',110],
['percentage','النسبة المئوية','finance','%','احسب نسبة من قيمة أو نسبة قيمة إلى أخرى أو نسبة التغير.','percentage',null,'معادلة حسابية',null,'1.0',null,'حساب رياضي عام.',120],
['age','حساب العمر','dates','ع','احسب العمر بالسنوات والأشهر والأيام مع عرض تاريخ الميلاد هجريًا.','age',null,'تقويم أم القرى عبر دعم المتصفح',null,'1.0',null,'التحويل الهجري يعتمد على دعم تقويم أم القرى في المتصفح وقد يختلف يومًا في بعض البيئات.',130],
['date-converter','تحويل التاريخ هجري / ميلادي','dates','هـ','حوّل التاريخ في الاتجاهين داخل المنصة باستخدام تقويم أم القرى عند دعم المتصفح.','date-converter',null,'تقويم أم القرى عبر دعم المتصفح',null,'1.0',null,'التحويل للاستخدام الإرشادي. عند وجود مستند رسمي يعتمد التاريخ المثبت في المستند والجهة المختصة.',140],
['date-difference','الفرق بين تاريخين','dates','↔','احسب الفرق بين تاريخين بالسنوات والأشهر والأيام وإجمالي الأيام.','date-difference',null,'معادلة زمنية',null,'1.0',null,'حساب زمني عام.',150],
['contract-duration','مدة العقد','dates','عق','احسب مدة العقد من تاريخ البداية إلى النهاية.','contract-duration',null,'معادلة زمنية',null,'1.0',null,'الحساب لا يحدد الأثر النظامي لبداية أو نهاية العقد.',160],
['electricity-consumption','استهلاك الكهرباء','utilities','ك','قدّر استهلاك جهاز أو مجموعة أجهزة بالكيلوواط ساعة ثم التكلفة وفق التعرفة التي تدخلها.','electricity-consumption','هيئة تنظيم المياه والكهرباء','هيئة تنظيم المياه والكهرباء','https://wera.gov.sa/','1.0',null,'أدخل التعرفة الفعلية المناسبة لشريحتك. لا تفترض الحاسبة تعرفة موحدة أو رسومًا ثابتة.',170],
['business-setup-cost','تكلفة تأسيس المنشأة','business','أ','اجمع رسوم وتكاليف رحلة التأسيس في مكان واحد بدل التنقل بين الحاسبات والمواقع.','business-setup-cost','المركز السعودي للأعمال','المركز السعودي للأعمال','https://business.sa/','1.0','2026-08-26','تختلف الرسوم باختلاف الكيان والنشاط والمدينة والتراخيص. أدخل القيم الحالية من المصادر الرسمية في رحلة تأسيسك.',180],
];

$sql = "INSERT INTO calculator_definitions(slug,name,category,icon,purpose,engine_key,entity,source_label,source_url,rule_version,verified_at,disclaimer,sort_order,status,created_at,updated_at)
VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,'published',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
ON CONFLICT(slug) DO UPDATE SET name=excluded.name,category=excluded.category,icon=excluded.icon,purpose=excluded.purpose,engine_key=excluded.engine_key,entity=excluded.entity,source_label=excluded.source_label,source_url=excluded.source_url,rule_version=excluded.rule_version,verified_at=excluded.verified_at,disclaimer=excluded.disclaimer,sort_order=excluded.sort_order,status='published',updated_at=CURRENT_TIMESTAMP";
foreach ($items as $item) Database::execute($sql, $item);

// Add the internal calculator detail route without replacing the current production router file.
$domainRoot = dirname(dirname(__DIR__));
$index = $domainRoot . '/public_html/index.php';
$routeAdded = false;
if (is_file($index)) {
    $code = file_get_contents($index) ?: '';
    $route = "$" . "router->get('/calculator/{slug}', [ContentController::class, 'calculator']);";
    if (!str_contains($code, $route)) {
        $needle = "$" . "router->get('/calculators', [ContentController::class, 'calculators']);";
        if (str_contains($code, $needle)) {
            @copy($index, $index . '.before-v2.0.17.' . $stamp . '.bak');
            $code = str_replace($needle, $needle . PHP_EOL . $route, $code);
            file_put_contents($index, $code);
            $routeAdded = true;
        }
    }
}

@file_put_contents(root_path('VERSION'), "2.0.17\n");

$count = (int)(Database::fetch("SELECT COUNT(*) c FROM calculator_definitions WHERE status='published'")['c'] ?? 0);
$fk = Database::fetchAll('PRAGMA foreign_key_check');

echo json_encode([
    'ok' => true,
    'version' => '2.0.17',
    'db_backup' => basename($backup),
    'internal_calculators' => $count,
    'external_calculator_redirects' => 0,
    'calculator_route' => '/calculator/{slug}',
    'route_added' => $routeAdded,
    'footer_identity' => 'unified_dark_green_ivory_gold',
    'owner_settings_tabs_alignment' => 'fixed',
    'owner_button_alignment' => 'fixed',
    'integrity' => empty($fk) ? 'ok' : 'warning',
    'foreign_key_errors' => count($fk),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
