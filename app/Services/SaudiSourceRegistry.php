<?php

declare(strict_types=1);

namespace Khatauat\Services;

use Khatauat\Core\Database;

final class SaudiSourceRegistry
{
    public function seedCore(): int
    {
        $count = 0;
        foreach ($this->coreSources() as $s) {
            $domain = strtolower((string)(parse_url($s['url'], PHP_URL_HOST) ?: ''));
            if ($domain === '') continue;
            Database::execute(
                'INSERT INTO source_registry(name,entity,sector,authority_type,source_role,url,domain,status,trust_level,discovery_method,auto_monitor,notes,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON CONFLICT(url) DO UPDATE SET name=excluded.name,entity=excluded.entity,sector=excluded.sector,authority_type=excluded.authority_type,source_role=excluded.source_role,domain=excluded.domain,trust_level=excluded.trust_level,notes=excluded.notes,updated_at=CURRENT_TIMESTAMP',
                [$s['name'],$s['entity'],$s['sector'],$s['authority_type'],$s['source_role'],$s['url'],$domain,'active','official','core_seed',1,$s['notes'] ?? '']
            );
            $count++;
        }
        return $count;
    }

    public function importCandidates(array $rows): int
    {
        $added = 0;
        foreach ($rows as $s) {
            if (!is_array($s)) continue;
            $url = trim((string)($s['url'] ?? ''));
            if (!$this->isSafeOfficialCandidate($url)) continue;
            $domain = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
            $name = trim((string)($s['name'] ?? $domain));
            if ($name === '') $name = $domain;
            $authorityType = (string)($s['authority_type'] ?? 'government_platform');
            $sourceRole = (string)($s['source_role'] ?? 'reference');
            if (!in_array($authorityType,['government','semi_government','government_platform','regulator','official_gazette','reference'],true)) $authorityType='government_platform';
            if (!in_array($sourceRole,['reference','regulation','service','execution','data','verification','directory'],true)) $sourceRole='reference';
            if (Database::fetch('SELECT id FROM source_registry WHERE url=? LIMIT 1',[$url])) continue;
            Database::execute(
                'INSERT INTO source_registry(name,entity,sector,authority_type,source_role,url,domain,status,trust_level,discovery_method,auto_monitor,notes,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)',
                [$name,trim((string)($s['entity']??'')),trim((string)($s['sector']??'عام')),$authorityType,$sourceRole,$url,$domain,'candidate','candidate','ai_web_search',0,trim((string)($s['notes']??''))]
            );
            $added++;
        }
        return $added;
    }

    public function activateToOperationalSource(int $registryId, string $verifiedBy): bool
    {
        $row = Database::fetch('SELECT * FROM source_registry WHERE id=?', [$registryId]);
        if (!$row) return false;
        Database::execute('UPDATE source_registry SET status="active",trust_level="official",auto_monitor=1,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$registryId]);
        $existing = Database::fetch('SELECT id FROM sources WHERE url=? LIMIT 1',[(string)$row['url']]);
        if (!$existing) {
            Database::execute('INSERT INTO sources(title,entity,url,monitor_enabled,verified_at,verified_by,created_at,updated_at) VALUES(?,?,?,?,CURRENT_TIMESTAMP,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)',[(string)$row['name'],(string)$row['entity'],(string)$row['url'],1,$verifiedBy]);
        }
        return true;
    }

    public function coreSources(): array
    {
        return [
            ['name'=>'المنصة الوطنية GOV.SA','entity'=>'هيئة الحكومة الرقمية','sector'=>'مرجع مركزي','authority_type'=>'reference','source_role'=>'directory','url'=>'https://my.gov.sa/ar','notes'=>'المرجع الوطني للمعلومات والخدمات الحكومية.'],
            ['name'=>'دليل الجهات الحكومية GOV.SA','entity'=>'هيئة الحكومة الرقمية','sector'=>'مرجع مركزي','authority_type'=>'reference','source_role'=>'directory','url'=>'https://my.gov.sa/ar/agencies','notes'=>'دليل الجهات الحكومية.'],
            ['name'=>'دليل الخدمات الحكومية GOV.SA','entity'=>'هيئة الحكومة الرقمية','sector'=>'مرجع مركزي','authority_type'=>'reference','source_role'=>'directory','url'=>'https://my.gov.sa/ar/services','notes'=>'دليل الخدمات الحكومية.'],
            ['name'=>'هيئة الحكومة الرقمية','entity'=>'هيئة الحكومة الرقمية','sector'=>'الحكومة الرقمية','authority_type'=>'regulator','source_role'=>'regulation','url'=>'https://dga.gov.sa/','notes'=>'تنظيم الحكومة الرقمية والمنصات الحكومية.'],
            ['name'=>'وزارة الداخلية','entity'=>'وزارة الداخلية','sector'=>'الداخلية','authority_type'=>'government','source_role'=>'reference','url'=>'https://www.moi.gov.sa/','notes'=>'المرجع الرسمي لقطاعات الداخلية.'],
            ['name'=>'أبشر أفراد','entity'=>'وزارة الداخلية','sector'=>'الداخلية','authority_type'=>'government_platform','source_role'=>'execution','url'=>'https://www.absher.sa/','notes'=>'منصة تنفيذ خدمات الأفراد.'],
            ['name'=>'المديرية العامة للجوازات','entity'=>'وزارة الداخلية','sector'=>'الجوازات','authority_type'=>'government','source_role'=>'service','url'=>'https://www.moi.gov.sa/wps/portal/Home/sectors/passports','notes'=>'الجوازات والإقامة وخدمات الوافدين.'],
            ['name'=>'الدفاع المدني السعودي','entity'=>'المديرية العامة للدفاع المدني','sector'=>'السلامة','authority_type'=>'government','source_role'=>'regulation','url'=>'https://998.gov.sa/','notes'=>'السلامة ولوائح الدفاع المدني.'],
            ['name'=>'منصة سلامة','entity'=>'المديرية العامة للدفاع المدني','sector'=>'السلامة','authority_type'=>'government_platform','source_role'=>'execution','url'=>'https://salamah.998.gov.sa/','notes'=>'تراخيص السلامة وخدمات المكاتب والشركات المعتمدة.'],
            ['name'=>'وزارة العدل','entity'=>'وزارة العدل','sector'=>'العدل','authority_type'=>'government','source_role'=>'reference','url'=>'https://www.moj.gov.sa/','notes'=>'الخدمات والأنظمة العدلية.'],
            ['name'=>'ناجز','entity'=>'وزارة العدل','sector'=>'العدل','authority_type'=>'government_platform','source_role'=>'execution','url'=>'https://najiz.sa/','notes'=>'منصة الخدمات العدلية.'],
            ['name'=>'وزارة التجارة','entity'=>'وزارة التجارة','sector'=>'الأعمال','authority_type'=>'government','source_role'=>'reference','url'=>'https://mc.gov.sa/','notes'=>'الخدمات والأنظمة التجارية.'],
            ['name'=>'المركز السعودي للأعمال','entity'=>'المركز السعودي للأعمال','sector'=>'الأعمال','authority_type'=>'government_platform','source_role'=>'execution','url'=>'https://business.sa/','notes'=>'تأسيس وتشغيل الأعمال والخدمات المترابطة.'],
            ['name'=>'منصة بلدي','entity'=>'وزارة البلديات والإسكان','sector'=>'البلديات والإسكان','authority_type'=>'government_platform','source_role'=>'execution','url'=>'https://balady.gov.sa/','notes'=>'الرخص البلدية والبناء والخدمات البلدية.'],
            ['name'=>'وزارة البلديات والإسكان','entity'=>'وزارة البلديات والإسكان','sector'=>'البلديات والإسكان','authority_type'=>'government','source_role'=>'reference','url'=>'https://momah.gov.sa/','notes'=>'مرجع القطاع البلدي والإسكان.'],
            ['name'=>'هيئة الزكاة والضريبة والجمارك','entity'=>'هيئة الزكاة والضريبة والجمارك','sector'=>'الزكاة والضريبة والجمارك','authority_type'=>'regulator','source_role'=>'execution','url'=>'https://zatca.gov.sa/','notes'=>'الزكاة والضرائب والجمارك.'],
            ['name'=>'وزارة الموارد البشرية والتنمية الاجتماعية','entity'=>'وزارة الموارد البشرية والتنمية الاجتماعية','sector'=>'العمل والدعم','authority_type'=>'government','source_role'=>'reference','url'=>'https://www.hrsd.gov.sa/','notes'=>'العمل والتنمية الاجتماعية.'],
            ['name'=>'قوى','entity'=>'وزارة الموارد البشرية والتنمية الاجتماعية','sector'=>'العمل','authority_type'=>'government_platform','source_role'=>'execution','url'=>'https://www.qiwa.sa/','notes'=>'خدمات قطاع العمل والمنشآت.'],
            ['name'=>'مساند','entity'=>'وزارة الموارد البشرية والتنمية الاجتماعية','sector'=>'العمالة المنزلية','authority_type'=>'government_platform','source_role'=>'execution','url'=>'https://musaned.com.sa/','notes'=>'استقدام وإدارة العمالة المنزلية.'],
            ['name'=>'صندوق تنمية الموارد البشرية هدف','entity'=>'صندوق تنمية الموارد البشرية','sector'=>'التوظيف','authority_type'=>'semi_government','source_role'=>'service','url'=>'https://www.hrdf.org.sa/','notes'=>'التوظيف وبرامج دعم العمل وجدارات.'],
            ['name'=>'التأمينات الاجتماعية','entity'=>'المؤسسة العامة للتأمينات الاجتماعية','sector'=>'التأمينات','authority_type'=>'government','source_role'=>'execution','url'=>'https://www.gosi.gov.sa/','notes'=>'التأمينات والتقاعد وساند.'],
            ['name'=>'بنك التنمية الاجتماعية','entity'=>'بنك التنمية الاجتماعية','sector'=>'التمويل والدعم','authority_type'=>'government','source_role'=>'execution','url'=>'https://www.sdb.gov.sa/','notes'=>'التمويل الاجتماعي وتمويل المنشآت.'],
            ['name'=>'وزارة الصحة','entity'=>'وزارة الصحة','sector'=>'الصحة','authority_type'=>'government','source_role'=>'reference','url'=>'https://www.moh.gov.sa/','notes'=>'الخدمات والمعلومات الصحية الرسمية.'],
            ['name'=>'مجلس الضمان الصحي','entity'=>'مجلس الضمان الصحي','sector'=>'التأمين الصحي','authority_type'=>'regulator','source_role'=>'verification','url'=>'https://chi.gov.sa/','notes'=>'التأمين الصحي وخدمات التحقق.'],
            ['name'=>'الهيئة العامة للغذاء والدواء','entity'=>'الهيئة العامة للغذاء والدواء','sector'=>'الغذاء والدواء','authority_type'=>'regulator','source_role'=>'verification','url'=>'https://www.sfda.gov.sa/','notes'=>'الغذاء والدواء والأجهزة والمنتجات الخاضعة للرقابة.'],
            ['name'=>'وزارة التعليم','entity'=>'وزارة التعليم','sector'=>'التعليم','authority_type'=>'government','source_role'=>'reference','url'=>'https://www.moe.gov.sa/','notes'=>'التعليم العام والعالي.'],
            ['name'=>'نظام نور','entity'=>'وزارة التعليم','sector'=>'التعليم','authority_type'=>'government_platform','source_role'=>'execution','url'=>'https://noor.moe.gov.sa/','notes'=>'الخدمات التعليمية والتسجيل.'],
            ['name'=>'هيئة تقويم التعليم والتدريب','entity'=>'هيئة تقويم التعليم والتدريب','sector'=>'التعليم','authority_type'=>'regulator','source_role'=>'verification','url'=>'https://etec.gov.sa/','notes'=>'الاختبارات والتقويم والاعتماد.'],
            ['name'=>'المركز الوطني للتعليم الإلكتروني','entity'=>'المركز الوطني للتعليم الإلكتروني','sector'=>'التعليم','authority_type'=>'regulator','source_role'=>'reference','url'=>'https://nelc.gov.sa/','notes'=>'تنظيم وتمكين التعليم الإلكتروني.'],
            ['name'=>'وزارة الاستثمار','entity'=>'وزارة الاستثمار','sector'=>'الاستثمار','authority_type'=>'government','source_role'=>'execution','url'=>'https://misa.gov.sa/','notes'=>'خدمات المستثمرين والاستثمار الأجنبي.'],
            ['name'=>'وزارة الصناعة والثروة المعدنية','entity'=>'وزارة الصناعة والثروة المعدنية','sector'=>'الصناعة والتعدين','authority_type'=>'government','source_role'=>'execution','url'=>'https://www.mim.gov.sa/','notes'=>'التراخيص الصناعية والتعدينية.'],
            ['name'=>'مدن','entity'=>'الهيئة السعودية للمدن الصناعية ومناطق التقنية','sector'=>'الصناعة','authority_type'=>'semi_government','source_role'=>'execution','url'=>'https://modon.gov.sa/','notes'=>'المدن الصناعية وخدمات المستثمرين.'],
            ['name'=>'وزارة البيئة والمياه والزراعة','entity'=>'وزارة البيئة والمياه والزراعة','sector'=>'البيئة والزراعة','authority_type'=>'government','source_role'=>'reference','url'=>'https://www.mewa.gov.sa/','notes'=>'البيئة والمياه والزراعة.'],
            ['name'=>'وزارة الطاقة','entity'=>'وزارة الطاقة','sector'=>'الطاقة','authority_type'=>'government','source_role'=>'execution','url'=>'https://www.moenergy.gov.sa/','notes'=>'الخدمات والتراخيص المرتبطة بالطاقة.'],
            ['name'=>'الهيئة العامة للعقار','entity'=>'الهيئة العامة للعقار','sector'=>'العقار','authority_type'=>'regulator','source_role'=>'execution','url'=>'https://rega.gov.sa/','notes'=>'تنظيم وخدمات القطاع العقاري.'],
            ['name'=>'وزارة النقل والخدمات اللوجستية','entity'=>'وزارة النقل والخدمات اللوجستية','sector'=>'النقل','authority_type'=>'government','source_role'=>'reference','url'=>'https://mot.gov.sa/','notes'=>'مرجع منظومة النقل والخدمات اللوجستية.'],
            ['name'=>'الهيئة العامة للنقل','entity'=>'الهيئة العامة للنقل','sector'=>'النقل','authority_type'=>'regulator','source_role'=>'execution','url'=>'https://www.tga.gov.sa/','notes'=>'التراخيص وتنظيم النقل البري والبحري والسككي.'],
            ['name'=>'وزارة السياحة','entity'=>'وزارة السياحة','sector'=>'السياحة','authority_type'=>'government','source_role'=>'execution','url'=>'https://mt.gov.sa/','notes'=>'التراخيص والخدمات السياحية.'],
            ['name'=>'وزارة الحج والعمرة','entity'=>'وزارة الحج والعمرة','sector'=>'الحج والعمرة','authority_type'=>'government','source_role'=>'reference','url'=>'https://haj.gov.sa/','notes'=>'الخدمات والإجراءات الرسمية للحج والعمرة.'],
            ['name'=>'نسك','entity'=>'وزارة الحج والعمرة','sector'=>'الحج والعمرة','authority_type'=>'government_platform','source_role'=>'execution','url'=>'https://www.nusuk.sa/','notes'=>'تخطيط وتنفيذ خدمات العمرة والزيارة.'],
            ['name'=>'نسك حج','entity'=>'وزارة الحج والعمرة','sector'=>'الحج والعمرة','authority_type'=>'government_platform','source_role'=>'execution','url'=>'https://hajj.nusuk.sa/','notes'=>'منصة باقات وخدمات الحج.'],
            ['name'=>'KSA Visa','entity'=>'وزارة الخارجية','sector'=>'التأشيرات','authority_type'=>'government_platform','source_role'=>'execution','url'=>'https://ksavisa.sa/','notes'=>'خدمات التأشيرات السعودية.'],
            ['name'=>'البنك المركزي السعودي','entity'=>'البنك المركزي السعودي','sector'=>'القطاع المالي','authority_type'=>'regulator','source_role'=>'regulation','url'=>'https://www.sama.gov.sa/','notes'=>'الأنظمة والخدمات المالية والمصرفية.'],
            ['name'=>'هيئة التأمين','entity'=>'هيئة التأمين','sector'=>'التأمين','authority_type'=>'regulator','source_role'=>'regulation','url'=>'https://www.ia.gov.sa/','notes'=>'تنظيم قطاع التأمين.'],
            ['name'=>'هيئة السوق المالية','entity'=>'هيئة السوق المالية','sector'=>'الأسواق المالية','authority_type'=>'regulator','source_role'=>'regulation','url'=>'https://cma.org.sa/','notes'=>'تنظيم سوق المال والأوراق المالية.'],
            ['name'=>'هيئة الاتصالات والفضاء والتقنية','entity'=>'هيئة الاتصالات والفضاء والتقنية','sector'=>'الاتصالات والتقنية','authority_type'=>'regulator','source_role'=>'execution','url'=>'https://www.cst.gov.sa/','notes'=>'الاتصالات والتقنية والتراخيص والشكاوى.'],
            ['name'=>'الهيئة الوطنية للأمن السيبراني','entity'=>'الهيئة الوطنية للأمن السيبراني','sector'=>'الأمن السيبراني','authority_type'=>'regulator','source_role'=>'regulation','url'=>'https://nca.gov.sa/','notes'=>'الضوابط والأطر الوطنية للأمن السيبراني.'],
            ['name'=>'سدايا','entity'=>'الهيئة السعودية للبيانات والذكاء الاصطناعي','sector'=>'البيانات والذكاء الاصطناعي','authority_type'=>'government','source_role'=>'reference','url'=>'https://sdaia.gov.sa/','notes'=>'البيانات والذكاء الاصطناعي والهوية الرقمية.'],
            ['name'=>'الهيئة العامة للإحصاء','entity'=>'الهيئة العامة للإحصاء','sector'=>'البيانات والإحصاء','authority_type'=>'government','source_role'=>'data','url'=>'https://www.stats.gov.sa/','notes'=>'الإحصاءات الرسمية السعودية.'],
            ['name'=>'هيئة الخبراء - الأنظمة السعودية','entity'=>'هيئة الخبراء بمجلس الوزراء','sector'=>'الأنظمة واللوائح','authority_type'=>'reference','source_role'=>'regulation','url'=>'https://laws.boe.gov.sa/','notes'=>'النصوص النظامية واللوائح السعودية.'],
            ['name'=>'جريدة أم القرى','entity'=>'جريدة أم القرى','sector'=>'الأنظمة والقرارات','authority_type'=>'official_gazette','source_role'=>'regulation','url'=>'https://www.uqn.gov.sa/','notes'=>'الجريدة الرسمية السعودية.'],
            ['name'=>'منصة اعتماد','entity'=>'وزارة المالية','sector'=>'المشتريات الحكومية','authority_type'=>'government_platform','source_role'=>'execution','url'=>'https://portal.etimad.sa/','notes'=>'المنافسات والمشتريات والخدمات المالية الحكومية.'],
            ['name'=>'منشآت','entity'=>'الهيئة العامة للمنشآت الصغيرة والمتوسطة','sector'=>'المنشآت','authority_type'=>'semi_government','source_role'=>'service','url'=>'https://www.monshaat.gov.sa/','notes'=>'برامج وخدمات المنشآت الصغيرة والمتوسطة.'],
            ['name'=>'المساحة الجيولوجية السعودية','entity'=>'هيئة المساحة الجيولوجية السعودية','sector'=>'الجيولوجيا والتعدين','authority_type'=>'government','source_role'=>'data','url'=>'https://sgs.gov.sa/','notes'=>'البيانات الجيولوجية والمخاطر والخرائط.'],
            ['name'=>'رَقمي — سجل المنصات الحكومية','entity'=>'هيئة الحكومة الرقمية','sector'=>'الحكومة الرقمية','authority_type'=>'reference','source_role'=>'verification','url'=>'https://raqmi.dga.gov.sa/','notes'=>'مرجع للتحقق من تسجيل وترخيص المنصات الحكومية الرقمية.'],
            ['name'=>'منصة البيانات المفتوحة','entity'=>'الجهات الحكومية السعودية','sector'=>'البيانات والإحصاء','authority_type'=>'government_platform','source_role'=>'data','url'=>'https://open.data.gov.sa/','notes'=>'المنصة الوطنية للبيانات الحكومية المفتوحة.'],
            ['name'=>'إيجار','entity'=>'الهيئة العامة للعقار','sector'=>'العقار والإسكان','authority_type'=>'government_platform','source_role'=>'execution','url'=>'https://www.ejar.sa/','notes'=>'العقود الإيجارية والخدمات المنظمة لقطاع الإيجار.'],
            ['name'=>'منصة مسار','entity'=>'وزارة الموارد البشرية والتنمية الاجتماعية','sector'=>'الموارد البشرية الحكومية','authority_type'=>'government_platform','source_role'=>'execution','url'=>'https://masar.sa/','notes'=>'خدمات الموارد البشرية الحكومية والموظفين.'],
            ['name'=>'ادرس في السعودية','entity'=>'وزارة التعليم','sector'=>'التعليم','authority_type'=>'government_platform','source_role'=>'execution','url'=>'https://studyinsaudi.sa/','notes'=>'التقديم للدراسة في المؤسسات التعليمية السعودية للطلاب الدوليين.'],
            ['name'=>'المؤسسة العامة للتدريب التقني والمهني','entity'=>'المؤسسة العامة للتدريب التقني والمهني','sector'=>'التعليم والتدريب','authority_type'=>'government','source_role'=>'service','url'=>'https://tvtc.gov.sa/','notes'=>'التدريب التقني والمهني وخدمات المتدربين والمنشآت التدريبية.'],
            ['name'=>'وزارة الخارجية','entity'=>'وزارة الخارجية','sector'=>'التأشيرات والخارجية','authority_type'=>'government','source_role'=>'reference','url'=>'https://www.mofa.gov.sa/','notes'=>'الخدمات الدبلوماسية والتأشيرات والخدمات القنصلية.'],
            ['name'=>'وزارة المالية','entity'=>'وزارة المالية','sector'=>'المالية الحكومية','authority_type'=>'government','source_role'=>'reference','url'=>'https://www.mof.gov.sa/','notes'=>'الأنظمة والخدمات والبيانات المالية الحكومية.'],
            ['name'=>'الهيئة العامة للطيران المدني','entity'=>'الهيئة العامة للطيران المدني','sector'=>'الطيران','authority_type'=>'regulator','source_role'=>'service','url'=>'https://gaca.gov.sa/','notes'=>'تنظيم وخدمات قطاع الطيران المدني.'],
            ['name'=>'الهيئة العامة للموانئ — موانئ','entity'=>'الهيئة العامة للموانئ','sector'=>'النقل واللوجستيات','authority_type'=>'government','source_role'=>'service','url'=>'https://mawani.gov.sa/','notes'=>'خدمات وتنظيم الموانئ السعودية.'],
            ['name'=>'الهيئة السعودية للمواصفات والمقاييس والجودة','entity'=>'الهيئة السعودية للمواصفات والمقاييس والجودة','sector'=>'المواصفات والجودة','authority_type'=>'regulator','source_role'=>'regulation','url'=>'https://www.saso.gov.sa/','notes'=>'المواصفات القياسية والمطابقة والجودة.'],
            ['name'=>'كود البناء السعودي','entity'=>'اللجنة الوطنية لكود البناء السعودي','sector'=>'البلديات والبناء','authority_type'=>'reference','source_role'=>'regulation','url'=>'https://sbc.gov.sa/','notes'=>'متطلبات وأكواد البناء السعودية.'],
            ['name'=>'الهيئة السعودية للملكية الفكرية','entity'=>'الهيئة السعودية للملكية الفكرية','sector'=>'الملكية الفكرية','authority_type'=>'regulator','source_role'=>'execution','url'=>'https://www.saip.gov.sa/','notes'=>'العلامات التجارية وبراءات الاختراع وحقوق الملكية الفكرية.'],
            ['name'=>'المركز الوطني لتنمية القطاع غير الربحي','entity'=>'المركز الوطني لتنمية القطاع غير الربحي','sector'=>'القطاع غير الربحي','authority_type'=>'government','source_role'=>'service','url'=>'https://ncnp.gov.sa/','notes'=>'تنظيم وخدمات الجمعيات والمؤسسات والقطاع غير الربحي.'],
            ['name'=>'المركز الوطني للرقابة على الالتزام البيئي','entity'=>'المركز الوطني للرقابة على الالتزام البيئي','sector'=>'البيئة','authority_type'=>'regulator','source_role'=>'execution','url'=>'https://ncec.gov.sa/','notes'=>'التصاريح والالتزام والرقابة البيئية.'],
            ['name'=>'وزارة الثقافة','entity'=>'وزارة الثقافة','sector'=>'الثقافة','authority_type'=>'government','source_role'=>'reference','url'=>'https://www.moc.gov.sa/','notes'=>'الخدمات والهيئات والأنشطة الثقافية.'],
            ['name'=>'وزارة الرياضة','entity'=>'وزارة الرياضة','sector'=>'الرياضة','authority_type'=>'government','source_role'=>'reference','url'=>'https://www.mos.gov.sa/','notes'=>'الخدمات والأنظمة والأنشطة الرياضية.'],
            ['name'=>'وزارة الإعلام','entity'=>'وزارة الإعلام','sector'=>'الإعلام والنشر','authority_type'=>'government','source_role'=>'reference','url'=>'https://media.gov.sa/','notes'=>'الخدمات والأنظمة المتعلقة بالإعلام والنشر.'],
            ['name'=>'الهيئة العامة للأوقاف','entity'=>'الهيئة العامة للأوقاف','sector'=>'الأوقاف','authority_type'=>'government','source_role'=>'service','url'=>'https://www.awqaf.gov.sa/','notes'=>'تنظيم وخدمات الأوقاف.'],
            ['name'=>'هيئة المحتوى المحلي والمشتريات الحكومية','entity'=>'هيئة المحتوى المحلي والمشتريات الحكومية','sector'=>'المشتريات والمحتوى المحلي','authority_type'=>'regulator','source_role'=>'regulation','url'=>'https://lcgpa.gov.sa/','notes'=>'المحتوى المحلي والمشتريات الحكومية والمتطلبات ذات العلاقة.'],
        ];
    }

    private function isSafeOfficialCandidate(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') return false;
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
        if ($host === '') return false;
        if (str_ends_with($host,'.gov.sa')) return true;

        // For non-gov.sa domains, accept discovery only when the domain is already
        // represented by an approved/active source in the national registry.
        // This prevents private .sa/.com.sa sites from entering the candidate queue
        // merely because they use a Saudi domain. New unknown platforms must first
        // be verified by the owner (for example through GOV.SA / Raqmi / the owning
        // authority) and then added explicitly.
        $candidateHost = preg_replace('/^www\./', '', $host) ?: $host;
        $trusted = Database::fetchAll('SELECT domain FROM source_registry WHERE status IN ("active","approved")');
        foreach ($trusted as $row) {
            $known = strtolower(trim((string)($row['domain'] ?? '')));
            $known = preg_replace('/^www\./', '', $known) ?: $known;
            if ($known === '') continue;
            if ($candidateHost === $known || str_ends_with($candidateHost, '.'.$known)) return true;
        }
        return false;
    }
}
