<?php

declare(strict_types=1);

namespace Khatauat\Services;

use Khatauat\Core\Database;

/**
 * Seeds a truthful production baseline: taxonomy + a very small set of
 * manually source-verified journeys. It intentionally does not fabricate
 * hundreds of services. The AI/source pipeline is responsible for expanding
 * coverage with review and evidence.
 */
final class ProductionSeeder
{
    private const VERIFIED_AT = '2026-08-26 00:00:00';
    private const VERIFIED_BY = 'Khatauat Research Seed v2.0';

    public function seed(): array
    {
        $this->retireDemoContent();
        $categories = $this->seedCategories();
        $services = $this->seedVerifiedServices();
        return ['categories' => $categories, 'services' => $services];
    }

    private function retireDemoContent(): void
    {
        // Never expose legacy demonstration journeys as government guidance.
        Database::execute("UPDATE services SET status='draft', indexable=0, published_at=NULL, updated_at=CURRENT_TIMESTAMP WHERE slug IN ('demo-start-business','demo-individual-service')");
        Database::execute("UPDATE articles SET status='draft', published_at=NULL, updated_at=CURRENT_TIMESTAMP WHERE slug LIKE 'demo-%' OR title LIKE '%تجريبي%'");
    }

    private function seedCategories(): int
    {
        $count = 0;
        foreach ($this->categoryDefinitions() as [$name,$slug,$description]) {
            Database::execute('INSERT INTO categories(name,slug,description,created_at) VALUES(?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(slug) DO UPDATE SET name=excluded.name,description=excluded.description', [$name,$slug,$description]);
            $count++;
        }
        return $count;
    }

    private function categoryDefinitions(): array
    {
        return [
            ['الأعمال والتجارة','business-commerce','تأسيس وممارسة وتطوير الأعمال والتجارة.'],
            ['الهوية والداخلية','identity-interior','الهوية والأحوال والجوازات وخدمات وزارة الداخلية.'],
            ['العمل والتوظيف','work-employment','العمل والعقود والتوظيف والتأمينات والموارد البشرية.'],
            ['العقار والإسكان','real-estate-housing','العقار والتسجيل والإيجار والدعم والتمويل السكني.'],
            ['البلديات والبناء','municipal-building','الرخص البلدية والإنشائية والمكاتب الهندسية والسلامة.'],
            ['العدل والتوثيق','justice-documentation','القضاء والتنفيذ والتوثيق والوكالات والخدمات العدلية.'],
            ['الصحة والتأمين','health-insurance','الصحة والتأمين الصحي والغذاء والدواء.'],
            ['التعليم والتدريب','education-training','التعليم والتسجيل والاختبارات والتدريب والابتعاث.'],
            ['النقل والمركبات','transport-vehicles','المركبات والطرق والنقل البري والبحري والجوي واللوجستيات.'],
            ['الزكاة والضريبة والجمارك','zakat-tax-customs','الزكاة والضرائب والفوترة والجمارك.'],
            ['الاستثمار والصناعة','investment-industry','الاستثمار والتراخيص الصناعية والتعدين والمدن الصناعية.'],
            ['الحج والعمرة والتأشيرات','hajj-umrah-visas','الحج والعمرة والتصاريح والتأشيرات وخدمات الزوار.'],
            ['الدعم والتمويل الاجتماعي','support-finance','الدعم الحكومي والتمويل الاجتماعي وبرامج الاستحقاق.'],
            ['الاتصالات والتقنية','communications-tech','الاتصالات والخدمات الرقمية والبيانات والأمن السيبراني.'],
            ['البيئة والزراعة والطاقة','environment-agri-energy','البيئة والمياه والزراعة والطاقة والتراخيص المرتبطة.'],
        ];
    }

    private function seedVerifiedServices(): int
    {
        $definitions = $this->verifiedDefinitions();
        $inserted = 0;
        foreach ($definitions as $definition) {
            if (Database::fetch('SELECT id FROM services WHERE slug=? LIMIT 1', [$definition['slug']])) continue;
            $category = Database::fetch('SELECT id FROM categories WHERE slug=? LIMIT 1', [$definition['category_slug']]);
            if (!$category) continue;
            $sourceId = $this->ensureOperationalSource($definition['source']);
            Database::execute(
                'INSERT INTO services(category_id,name,slug,official_entity,summary,beneficiaries,eligibility,requirements,notes,official_url,official_platform,status,indexable,seo_title,seo_description,published_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)',
                [
                    (int)$category['id'],$definition['name'],$definition['slug'],$definition['official_entity'],$definition['summary'],$definition['beneficiaries'],$definition['eligibility'],$definition['requirements'],$definition['notes'],$definition['official_url'],$definition['official_platform'],'published',1,$definition['seo_title'],$definition['seo_description'],self::VERIFIED_AT,
                ]
            );
            $serviceId = Database::lastInsertId();
            $previousStepId = null;
            foreach ($definition['steps'] as $position => $step) {
                Database::execute(
                    'INSERT INTO service_steps(service_id,position,title,entity,platform,prerequisite,action_text,output_text,official_url,source_id,depends_on_step_id,is_blocking,trust_status,verified_at,verified_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)',
                    [
                        $serviceId,$position+1,$step['title'],$step['entity'] ?? $definition['official_entity'],$step['platform'] ?? $definition['official_platform'],$step['prerequisite'] ?? '',$step['action'],$step['output'],$step['official_url'] ?? $definition['official_url'],$sourceId,$previousStepId,$step['blocking'] ?? 0,'verified',self::VERIFIED_AT,self::VERIFIED_BY,
                    ]
                );
                $stepId = Database::lastInsertId();
                if ($previousStepId) {
                    Database::execute('UPDATE service_steps SET next_step_id=? WHERE id=?', [$stepId,$previousStepId]);
                    Database::execute('INSERT INTO service_relations(service_id,from_step_id,to_step_id,relation_type,output_label,prerequisite_label,created_at) VALUES(?,?,?,?,?,?,CURRENT_TIMESTAMP)', [$serviceId,$previousStepId,$stepId,'output_to_prerequisite','إكمال الخطوة السابقة','الانتقال للخطوة التالية']);
                }
                $previousStepId = $stepId;
            }
            $inserted++;
        }
        return $inserted;
    }

    private function ensureOperationalSource(array $source): int
    {
        $existing = Database::fetch('SELECT id FROM sources WHERE url=? LIMIT 1', [$source['url']]);
        if ($existing) return (int)$existing['id'];
        Database::execute(
            'INSERT INTO sources(title,entity,url,monitor_enabled,verified_at,verified_by,created_at,updated_at) VALUES(?,?,?,1,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)',
            [$source['title'],$source['entity'],$source['url'],self::VERIFIED_AT,self::VERIFIED_BY]
        );
        return Database::lastInsertId();
    }

    private function verifiedDefinitions(): array
    {
        return [
            [
                'category_slug'=>'business-commerce',
                'name'=>'قيد سجل تجاري لمؤسسة فردية',
                'slug'=>'commercial-registration-sole-proprietorship',
                'official_entity'=>'وزارة التجارة / المركز السعودي للأعمال',
                'official_platform'=>'المركز السعودي للأعمال',
                'official_url'=>'https://business.sa/',
                'summary'=>'مسار رسمي لبدء ممارسة النشاط التجاري عبر قيد سجل تجاري لمؤسسة فردية من خلال المركز السعودي للأعمال.',
                'beneficiaries'=>'التاجر الراغب في قيد مؤسسة فردية.',
                'eligibility'=>"ألا يقل العمر عن 18 سنة.\nألا يكون المتقدم موظفًا حكوميًا.\nألا يكون المالك ممتلكًا سجلًا تجاريًا نشطًا لمؤسسة فردية.",
                'requirements'=>"قد يلزم إرفاق موافقة الجهة المرخِّصة إذا كان النشاط يتطلب ترخيصًا مسبقًا.\nتحديد ممارسة التجارة الإلكترونية عند انطباقها.\nتوجد متطلبات إضافية للجمعيات والمؤسسات الوقفية بحسب الحالة.",
                'notes'=>"المصدر الرسمي يذكر رسوم خدمة قدرها 500 ريال عند التحقق بتاريخ 26 أغسطس 2026. المعلومات المتغيرة تبقى خاضعة للمراقبة والمصدر الرسمي عند التنفيذ.",
                'seo_title'=>'قيد سجل تجاري لمؤسسة فردية: الشروط والخطوات الرسمية',
                'seo_description'=>'اعرف خطوات قيد سجل تجاري لمؤسسة فردية عبر المركز السعودي للأعمال، من النفاذ الوطني حتى الدفع والتسجيل التلقائي في الجهات المرتبطة.',
                'source'=>[
                    'title'=>'وزارة التجارة — قيد سجل تجاري لمؤسسة فردية',
                    'entity'=>'وزارة التجارة',
                    'url'=>'https://mc.gov.sa/ar/eservices/Pages/ServiceDetails.aspx?sID=38',
                ],
                'steps'=>[
                    ['title'=>'الدخول عبر النفاذ الوطني','action'=>'الدخول إلى منصة المركز السعودي للأعمال باستخدام النفاذ الوطني الموحد.','output'=>'جلسة موثقة والانتقال إلى الخدمات الإلكترونية.'],
                    ['title'=>'اختيار خدمة قيد السجل','action'=>'اختيار «قيد سجل تجاري»، ثم تحديد نوع السجل «مؤسسة» والبدء بالخدمة.','output'=>'فتح طلب قيد مؤسسة فردية.'],
                    ['title'=>'تعبئة بيانات المؤسسة','action'=>'مراجعة الشروط والموافقة عليها ثم تعبئة بيانات المالك وبيانات الاتصال وإدخال عنوان الأعمال المعتمد.','output'=>'بيانات المالك والمؤسسة الأساسية.'],
                    ['title'=>'إضافة النشاط والاسم ورأس المال','action'=>'اختيار الأنشطة التجارية، وإضافة بيانات التجارة الإلكترونية إن وجدت، وتحديد رأس المال ونوع الاسم التجاري وتكوينه.','output'=>'تحديد نطاق النشاط والاسم التجاري ورأس المال.'],
                    ['title'=>'استكمال بيانات السجل','action'=>'إدخال عنوان المؤسسة وبيانات الاتصال وتعيين مدير المؤسسة وإدخال بياناته.','output'=>'اكتمال بيانات السجل التجاري المطلوب.'],
                    ['title'=>'مراجعة الطلب وتقديمه','action'=>'استعراض ملخص الطلب، ثم الموافقة على الإقرار وتقديم الطلب.','output'=>'إرسال الطلب للمراجعة/المعالجة.'],
                    ['title'=>'موافقات الأطراف عند الحاجة','action'=>'استكمال موافقات مالك السجل أو المدراء الآخرين إذا تطلبت حالة الطلب ذلك.','output'=>'اكتمال الموافقات المطلوبة.'],
                    ['title'=>'سداد الفاتورة وإصدار السجل','action'=>'سداد الفاتورة عبر وسائل الدفع المتاحة في المنصة.','output'=>'إصدار السجل التجاري رسميًا بعد إتمام الدفع.'],
                    ['title'=>'التسجيل التلقائي في الجهات المرتبطة','action'=>'بعد إصدار السجل، تتم إجراءات التسجيل التلقائي لدى الجهات المرتبطة التي يحددها النظام.','output'=>'الربط مع الموارد البشرية وZATCA والتأمينات والعنوان الوطني والغرفة التجارية وفق المصدر الرسمي.'],
                ],
            ],
            [
                'category_slug'=>'municipal-building',
                'name'=>'إصدار رخصة تجارية',
                'slug'=>'issue-commercial-license-balady',
                'official_entity'=>'وزارة البلديات والإسكان',
                'official_platform'=>'بلدي',
                'official_url'=>'https://balady.gov.sa/',
                'summary'=>'إصدار رخصة تجارية إلكترونيًا عبر منصة بلدي لبدء النشاط في الموقع المرخص، مع اختلاف الاشتراطات بحسب النشاط.',
                'beneficiaries'=>'قطاع الأعمال والمنشآت الراغبة في إصدار رخصة تجارية.',
                'eligibility'=>'يلزم وجود بيانات المنشأة والموقع والنشاط، مع استيفاء اشتراطات النشاط والموافقات الحكومية ذات العلاقة عند الحاجة.',
                'requirements'=>"صورة خارجية للمحل مع إبراز اللوحة.\nعقد إيجار أو صك ملكية أو عقد استثمار للموقع.\nعقد نظافة عند انطباقه.\nمتطلبات السلامة بحسب طبيعة النشاط.\nصورة من رخصة البناء وفق ما تعرضه صفحة الخدمة الرسمية.",
                'notes'=>'مدة التنفيذ المعروضة رسميًا 1–10 أيام. الرسوم ليست قيمة ثابتة في المصدر؛ يتم الرجوع إلى حاسبة الرسوم الرسمية في بلدي.',
                'seo_title'=>'إصدار رخصة تجارية عبر بلدي: الخطوات والمتطلبات',
                'seo_description'=>'المسار الرسمي لإصدار رخصة تجارية عبر بلدي: بيانات المنشأة والنشاط والموقع والمتطلبات والسداد أو إحالة الطلب للبلدية بحسب نوع النشاط.',
                'source'=>[
                    'title'=>'وزارة البلديات والإسكان — إصدار رخصة تجارية',
                    'entity'=>'وزارة البلديات والإسكان',
                    'url'=>'https://momah.gov.sa/ar/node/15158',
                ],
                'steps'=>[
                    ['title'=>'تحديد المنشأة والنشاط','action'=>'إدخال سجل المنشأة وتحديد النشاط والمساحة المطلوبة للرخصة.','output'=>'تحديد المنشأة والنشاط ونطاق الرخصة.'],
                    ['title'=>'تحديد الموقع وتفاصيل المحل','action'=>'تحديد الموقع وتعبئة تفاصيل المحل أو العربة وإرفاق ما يلزم بحسب النشاط.','output'=>'اكتمال بيانات الموقع ومتطلبات النشاط.'],
                    ['title'=>'السداد أو الإحالة للبلدية','action'=>'دفع الرسوم إذا كان النشاط فوريًا، أو إرسال الطلب للبلدية إذا كان النشاط غير فوري.','output'=>'إصدار الرخصة عند اكتمال المسار أو انتقال الطلب للمراجعة البلدية.'],
                ],
            ],
            [
                'category_slug'=>'municipal-building',
                'name'=>'إصدار رخصة بناء',
                'slug'=>'issue-building-permit-balady',
                'official_entity'=>'وزارة البلديات والإسكان',
                'official_platform'=>'بلدي / بلدي أعمال',
                'official_url'=>'https://balady.gov.sa/',
                'summary'=>'رحلة إصدار رخصة بناء عبر بلدي، وتشمل تفويض المكتب الهندسي والتصميم والفحص الفني واعتماد البلدية والتعاقدات والتأمين والتكامل مع الجهات المرتبطة.',
                'beneficiaries'=>'الأفراد والأعمال والجهات غير الربحية والجهات الحكومية بحسب صفحة الخدمة الرسمية.',
                'eligibility'=>'يجب أن تتوافر ملكية/علاقة نظامية بالعقار وأن يتم العمل من خلال الأطراف المهنية المطلوبة في مسار الرخصة.',
                'requirements'=>"صك إلكتروني محدث من وزارة العدل أو عقد إسكان أو عقد استثماري.\nقرار مساحي بغرض البناء.\nالتعاقد مع مكتب هندسي مصمم ومكتب هندسي مشرف ومقاول بناء.\nالتأمين ضد العيوب الخفية عند انطباقه.\nتقرير دراسة التربة، والدراسة المرورية إذا تطلب النشاط ذلك.\nالإقرارات والتعهدات وسداد رسوم الخدمة.",
                'notes'=>'للطلبات المقدمة بعد 25 يونيو 2025، توضح الوزارة أن وثائق التأمين تصدر ضمن رحلة المستفيد في منصة بلدي. الرسوم تُحسب عبر حاسبة الرسوم الرسمية.',
                'seo_title'=>'إصدار رخصة بناء عبر بلدي: المسار الكامل والمتطلبات',
                'seo_description'=>'اعرف رحلة إصدار رخصة البناء عبر بلدي من تفويض المكتب الهندسي والتصميم والفحص والتعاقدات والتأمين حتى إصدار الرخصة والتكامل مع الجهات.',
                'source'=>[
                    'title'=>'وزارة البلديات والإسكان — إصدار رخصة بناء',
                    'entity'=>'وزارة البلديات والإسكان',
                    'url'=>'https://momah.gov.sa/ar/e-services/issuance-building-license',
                ],
                'steps'=>[
                    ['title'=>'تفويض المكتب الهندسي المصمم','action'=>'يقوم المالك أو من يمثله بتفويض مكتب هندسي مصمم لبدء إصدار رخصة البناء.','output'=>'تفويض مكتب التصميم لبدء المسار.'],
                    ['title'=>'إعداد الطلب والتصاميم في بلدي أعمال','action'=>'يقبل المكتب المصمم التفويض، يختار التقرير المساحي، ويستكمل بيانات الطلب والتصاميم المعمارية والإنشائية والمرفقات المطلوبة.','output'=>'طلب وتصاميم جاهزة للمراجعة الفنية.'],
                    ['title'=>'الفحص الفني ومطابقة كود البناء','action'=>'يُسند الطلب عبر شركة التأمين إلى شركة الفحص الفني لمراجعته والتأكد من مطابقته لاشتراطات كود البناء السعودي ومعالجة الملاحظات إن وجدت.','output'=>'مراجعة فنية للطلب والتصاميم.'],
                    ['title'=>'مراجعة الأمانة أو البلدية','action'=>'تراجع البلدية الطلب، وتعيد الملاحظات للمكتب الهندسي عند الحاجة، أو تعتمد الطلب والتصاميم عند اكتمالها.','output'=>'شهادة اعتماد التصاميم عند القبول.'],
                    ['title'=>'تحديد المكتب المشرف والمقاول','action'=>'يستكمل المالك والمكتب المصمم مرحلة التعاقدات عبر تحديد المكتب الهندسي المشرف ومقاول البناء واعتماد الإسناد.','output'=>'اعتماد الأطراف المهنية للتنفيذ والإشراف.'],
                    ['title'=>'استكمال بيانات المقاول','action'=>'يوافق مقاول البناء على إسناد الرخصة ويعبئ البيانات ويرفق المستندات المطلوبة لاستكمال المسار.','output'=>'اكتمال بيانات المقاول والمرفقات.'],
                    ['title'=>'إصدار وثيقة التأمين ضمن الرحلة','action'=>'يرسل نظام بلدي طلب وثيقة التأمين آليًا إلى شركة التأمين، وتستكمل إجراءات الوثيقة والسداد داخل رحلة المستفيد وفق الحالة.','output'=>'وثيقة التأمين المطلوبة للمسار عند انطباقها.'],
                    ['title'=>'استكمال الرسوم وإصدار الرخصة','action'=>'استكمال متطلبات الرسوم والإصدار في منصة بلدي بعد اكتمال المراجعات والتعاقدات والتأمين.','output'=>'رخصة البناء الصادرة إلكترونيًا.'],
                    ['title'=>'إشعار الجهات المرتبطة','action'=>'ترسل بيانات الرخصة الصادرة تكامليًا إلى الجهات المرتبطة بنظام بلدي مثل الكهرباء والمياه والطاقة والدفاع المدني والتأمين والسجل العقاري بحسب الحالة.','output'=>'تحديث الجهات المرتبطة ببيانات الرخصة.'],
                ],
            ],
        ];
    }
}
