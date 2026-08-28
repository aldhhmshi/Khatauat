<?php

declare(strict_types=1);

namespace Khatauat\Services;

use Khatauat\Core\Database;
use Khatauat\Core\Settings;

final class AiDraftService
{
    public function generate(string $topic, string $audience, string $keyword, string $sources): array
    {
        $endpoint = trim((string) Settings::get('ai_api_url', ''));
        $key = trim((string) (getenv('AI_API_KEY') ?: ''));
        $model = trim((string) Settings::get('ai_model', ''));

        if ($endpoint === '' || $key === '' || $model === '') {
            return ['ok' => false, 'error' => 'موصل الذكاء الاصطناعي غير مهيأ من الإعدادات.'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'امتداد cURL غير متاح على الخادم.'];
        }
        if (mb_strlen(trim($topic)) < 4) {
            return ['ok' => false, 'error' => 'اكتب موضوعًا واضحًا للمسودة.'];
        }

        $allowedSourceUrls = $this->extractSourceUrls($sources);
        $serviceCatalog = $this->serviceCatalog();
        $serviceCatalogText = $this->serviceCatalogText($serviceCatalog);
        if ($allowedSourceUrls === []) {
            return ['ok' => false, 'error' => 'يجب إرفاق رابط مصدر رسمي واحد على الأقل (https://...) قبل التوليد.'];
        }

        $system = <<<'SYS'
أنت محرر محتوى وخبير SEO إجرائي ثنائي اللغة لمنصة سعودية مستقلة اسمها «خطوات».
مهمتك إنشاء «حزمة خدمة SEO وإجراءات» احترافية، عربية أولًا ثم إنجليزية، مبنية على المصدر الرسمي المرفق فقط في الحقائق الإجرائية.

قواعد غير قابلة للتجاوز:
1) الحقائق الإجرائية (الخطوات، الشروط، الرسوم، المستندات، المدد، التحديثات) يجب أن تكون مثبتة بالمصدر المرفق فقط. لا تستخدم الذاكرة أو المعرفة العامة ولا تخمن.
2) يجب أن يوجد مصدر رسمي واحد على الأقل ورابط رسمي صالح. لا تختلق الروابط ولا تغيّرها.
3) العربية هي النسخة الأساسية، ثم الإنجليزية في حقول منفصلة. لا تخلط اللغتين في الفقرة نفسها إلا اسمًا رسميًا عند الحاجة.
4) لا تعرض التفكير الداخلي أو التحليل أو <think> أو عبارات مثل We need to / Let's think.
5) المحتوى معمق ومهني ومباشر لكن مختصر بلا حشو. ابدأ بالخلاصة العملية ثم المعلومات التي يحتاجها المستخدم للتنفيذ.
6) إذا لم يثبت المصدر شرطًا أو رسمًا أو مستندًا أو تحديثًا، اترك القائمة المقابلة فارغة وسجل النقص في missing_information.
7) أسئلة بحث SEO يمكن صياغتها من موضوع الخدمة ونيات البحث، لكن لا تتضمن حقائق غير مثبتة.
8) الخدمات المرتبطة والروابط الداخلية يجب اختيارها حصريًا من «دليل الخدمات الداخلي» المرفق في الطلب. لا تخترع خدمة أو slug.
9) الروابط الرسمية يجب أن تكون من الروابط المرفقة حرفيًا. الروابط الداخلية فقط يمكن أن تكون بصيغة /service/{slug} وفق دليل الخدمات الداخلي.
10) لا تقل إن «خطوات» جهة حكومية ولا توحِ بأنها تنفذ الخدمة.
11) استخدم HTML آمنًا فقط في content_html_ar/content_html_en: h2,h3,p,ol,ul,li,strong,em,a,blockquote,table,thead,tbody,tr,th,td,br,hr.
12) أخرج JSON صالحًا فقط، بلا Markdown وبلا أي نص قبله أو بعده.
SYS;

        $prompt = <<<PROMPT
{$system}

الموضوع: {$topic}
الجمهور المستهدف: {$audience}
الكلمة المفتاحية الأساسية: {$keyword}

المصادر الرسمية المعتمدة — المصدر الوحيد للحقائق الإجرائية:
---
{$sources}
---

دليل الخدمات الداخلي المتاح للروابط الداخلية والخدمات المرتبطة فقط:
---
{$serviceCatalogText}
---

أعد كائن JSON واحدًا بهذه البنية بالضبط:
{
  "title_ar": "عنوان عربي واضح ومباشر",
  "title_en": "Concise English title",
  "seo_title_ar": "عنوان SEO عربي طبيعي",
  "seo_title_en": "Natural English SEO title",
  "seo_description_ar": "وصف ميتا عربي موجز ومفيد",
  "seo_description_en": "Concise useful English meta description",
  "slug": "short-service-slug",
  "summary_ar": "ملخص عربي عملي من 2-3 جمل",
  "summary_en": "English practical summary in 2-3 sentences",
  "content_html_ar": "المحتوى العربي الأساسي فقط، بلا تكرار الأقسام المنظمة أدناه",
  "content_html_en": "Independent English primary content only, without duplicating the structured sections below",

  "seo_questions": [
    {"question_ar":"سؤال بحث عربي","question_en":"English search question","intent":"informational|transactional|navigational|commercial"}
  ],

  "related_services_seo": [
    {"service_name":"اسم خدمة من دليل الخدمات الداخلي فقط","slug":"slug مطابق للدليل","anchor_ar":"نص رابط عربي","anchor_en":"English anchor","reason_ar":"سبب الارتباط باختصار","reason_en":"Brief relevance reason"}
  ],

  "official_source": {"name_ar":"اسم الجهة/المنصة إن كان معروفًا من المصدر","name_en":"English name if available","url":"رابط رسمي من المصادر المرفقة"},
  "official_links": [
    {"label_ar":"اسم الرابط الرسمي","label_en":"Official link label","url":"رابط رسمي حرفيًا من المصادر"}
  ],

  "steps": [
    {"order":1,"title_ar":"اسم الخطوة","title_en":"Step title","description_ar":"وصف مختصر مثبت","description_en":"Verified concise description","source_url":"رابط رسمي"}
  ],
  "conditions": [
    {"text_ar":"شرط مثبت","text_en":"Verified condition","source_url":"رابط رسمي"}
  ],
  "fees": [
    {"label_ar":"اسم الرسم","label_en":"Fee label","value_ar":"القيمة أو الوصف كما بالمصدر","value_en":"Value/note exactly supported by source","source_url":"رابط رسمي"}
  ],
  "documents": [
    {"name_ar":"اسم المستند","name_en":"Document name","note_ar":"ملاحظة إن وجدت","note_en":"Note if any","source_url":"رابط رسمي"}
  ],
  "updates": [
    {"date":"YYYY-MM-DD أو فارغ إذا لم يذكر المصدر تاريخًا","summary_ar":"تحديث مثبت بالمصدر","summary_en":"Verified update","source_url":"رابط رسمي"}
  ],
  "faq": [
    {"question_ar":"سؤال شائع","answer_ar":"إجابة مختصرة من المصدر","question_en":"FAQ question","answer_en":"Concise source-backed answer","source_url":"رابط رسمي"}
  ],
  "internal_links": [
    {"service_name":"اسم خدمة من الدليل","slug":"slug مطابق للدليل","anchor_ar":"نص رابط داخلي عربي","anchor_en":"English internal-link anchor"}
  ],

  "source_urls": ["روابط رسمية موجودة حرفيًا في المصادر فقط"],
  "source_notes_ar": ["ما الذي يدعمه كل مصدر باختصار"],
  "source_notes_en": ["Brief note on what each source supports"],
  "verification_notes": ["نقاط يجب مراجعتها قبل النشر"],
  "missing_information": ["معلومات مهمة لم يثبتها المصدر ولا يجوز تخمينها"],
  "secondary_keywords_ar": ["حتى 8 كلمات عربية مرتبطة طبيعيًا"],
  "secondary_keywords_en": ["Up to 8 natural English related keywords"],
  "search_intent_ar": "نية البحث بالعربية باختصار",
  "search_intent_en": "Concise English search intent"
}

معايير إلزامية:
- seo_questions: من 5 إلى 20 سؤالًا مختلفًا، تغطي نيات البحث الفعلية دون حشو أو تكرار.
- related_services_seo: من 5 إلى 10 خدمات إذا كان دليل الخدمات الداخلي يحتوي على 5 خدمات مناسبة على الأقل؛ وإن كان أقل فاستخدم المتاح فقط ولا تخترع.
- official_source.url يجب أن يكون من الروابط الرسمية المرفقة.
- official_links وsource_url في الأقسام الإجرائية يجب أن تكون من الروابط المرفقة فقط.
- steps وconditions وfees وdocuments وupdates: املأ فقط ما يثبته المصدر، وإلا اترك القائمة فارغة.
- faq: أنشئ 5-10 أسئلة عند توفر معلومات كافية بالمصدر؛ لا تخمن الإجابات.
- internal_links: استخدم فقط slugs الموجودة في دليل الخدمات الداخلي.
- لا تضع Schema داخل الإجابة؛ النظام سيولده برمجيًا من الحقول الموثقة بعد التحقق.
- العربية أولًا دائمًا والإنجليزية ثانيًا.
- المحتوى الأساسي لا يكرر القوائم المنظمة؛ النظام سيعرضها تلقائيًا في صفحة الخدمة/المقال.
PROMPT;

        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        $isGroq = str_contains($host, 'groq.com');
        $isQwen = str_contains(strtolower($model), 'qwen');

        $payload = [
            'model' => $model,
            'messages' => $isGroq && $isQwen
                ? [['role' => 'user', 'content' => $prompt]]
                : [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => preg_replace('/^' . preg_quote($system, '/') . '\s*/u', '', $prompt) ?: $prompt],
                ],
            'temperature' => 0.25,
            'max_tokens' => 8000,
        ];

        // Groq + Qwen: hide reasoning and force valid JSON so <think> content never reaches editors.
        if ($isGroq) {
            $payload['response_format'] = ['type' => 'json_object'];
            if ($isQwen) {
                $payload['reasoning_format'] = 'hidden';
                $payload['reasoning_effort'] = 'none';
            }
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 75,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'error' => $curlError !== '' ? $curlError : 'تعذر الاتصال بموصل الذكاء الاصطناعي.'];
        }

        $decodedResponse = json_decode((string) $raw, true);
        if ($status >= 400) {
            $apiMessage = is_array($decodedResponse) ? trim((string) ($decodedResponse['error']['message'] ?? '')) : '';
            $apiCode = is_array($decodedResponse) ? trim((string) ($decodedResponse['error']['code'] ?? $decodedResponse['error']['type'] ?? '')) : '';
            $detail = trim(implode(' — ', array_filter([$apiCode, $apiMessage])));
            return ['ok' => false, 'error' => 'API HTTP ' . $status . ($detail !== '' ? ': ' . mb_substr($detail, 0, 350) : '')];
        }

        $text = is_array($decodedResponse)
            ? (string) ($decodedResponse['choices'][0]['message']['content'] ?? $decodedResponse['output_text'] ?? '')
            : '';
        $text = $this->stripReasoning(trim($text));
        if ($text === '') {
            return ['ok' => false, 'error' => 'لم يرجع المزود محتوى نهائيًا متوافقًا.'];
        }

        $structured = $this->decodeJsonObject($text);
        if ($structured === null) {
            return ['ok' => false, 'error' => 'رجع المزود نتيجة غير منظمة. أعد المحاولة؛ يجب أن تكون النتيجة JSON صالحًا فقط.'];
        }

        $required = [
            'title_ar','title_en','seo_title_ar','seo_title_en',
            'seo_description_ar','seo_description_en','summary_ar','summary_en',
            'content_html_ar','content_html_en'
        ];
        foreach ($required as $field) {
            if (trim((string) ($structured[$field] ?? '')) === '') {
                return ['ok' => false, 'error' => 'المسودة ناقصة الحقل: ' . $field];
            }
        }

        $generatedSourceUrls = $this->cleanStringList($structured['source_urls'] ?? []);
        $structured['source_urls'] = array_values(array_intersect($generatedSourceUrls, $allowedSourceUrls));
        if ($structured['source_urls'] === []) {
            $structured['source_urls'] = $allowedSourceUrls;
        }

        $structured['source_notes_ar'] = $this->cleanStringList($structured['source_notes_ar'] ?? []);
        $structured['source_notes_en'] = $this->cleanStringList($structured['source_notes_en'] ?? []);
        $structured['verification_notes'] = $this->cleanStringList($structured['verification_notes'] ?? []);
        $structured['missing_information'] = $this->cleanStringList($structured['missing_information'] ?? []);
        $structured['secondary_keywords_ar'] = array_slice($this->cleanStringList($structured['secondary_keywords_ar'] ?? []), 0, 8);
        $structured['secondary_keywords_en'] = array_slice($this->cleanStringList($structured['secondary_keywords_en'] ?? []), 0, 8);
        $structured['search_intent_ar'] = trim((string) ($structured['search_intent_ar'] ?? ''));
        $structured['search_intent_en'] = trim((string) ($structured['search_intent_en'] ?? ''));
        $structured['slug'] = \slugify((string) ($structured['slug'] ?? $structured['title_ar']));

        $structured['seo_questions'] = array_slice($this->cleanObjectList($structured['seo_questions'] ?? [], ['question_ar','question_en','intent']), 0, 20);
        if (count($structured['seo_questions']) < 5) {
            return ['ok' => false, 'error' => 'حزمة SEO ناقصة: يجب توليد 5 أسئلة بحث على الأقل. أعد المحاولة.'];
        }

        $structured['related_services_seo'] = $this->filterCatalogItems($structured['related_services_seo'] ?? [], $serviceCatalog, 10);
        $structured['internal_links'] = $this->filterCatalogItems($structured['internal_links'] ?? [], $serviceCatalog, 10);
        if (count($serviceCatalog) >= 5 && count($structured['related_services_seo']) < 5) {
            return ['ok' => false, 'error' => 'حزمة الخدمات المرتبطة ناقصة: يجب اختيار 5 خدمات مرتبطة على الأقل من دليل الخدمات الداخلي.'];
        }

        $structured['official_links'] = $this->filterOfficialLinkObjects($structured['official_links'] ?? [], $allowedSourceUrls, 12);
        $structured['steps'] = $this->filterSourceBackedObjects($structured['steps'] ?? [], $allowedSourceUrls, 30);
        $structured['conditions'] = $this->filterSourceBackedObjects($structured['conditions'] ?? [], $allowedSourceUrls, 30);
        $structured['fees'] = $this->filterSourceBackedObjects($structured['fees'] ?? [], $allowedSourceUrls, 20);
        $structured['documents'] = $this->filterSourceBackedObjects($structured['documents'] ?? [], $allowedSourceUrls, 30);
        $structured['updates'] = $this->filterSourceBackedObjects($structured['updates'] ?? [], $allowedSourceUrls, 20);
        $structured['faq'] = $this->filterSourceBackedObjects($structured['faq'] ?? [], $allowedSourceUrls, 10);

        $officialSource = is_array($structured['official_source'] ?? null) ? $structured['official_source'] : [];
        $officialUrl = trim((string) ($officialSource['url'] ?? ''));
        if (!in_array($officialUrl, $allowedSourceUrls, true)) $officialUrl = $allowedSourceUrls[0];
        $structured['official_source'] = [
            'name_ar' => trim((string) ($officialSource['name_ar'] ?? 'المصدر الرسمي')) ?: 'المصدر الرسمي',
            'name_en' => trim((string) ($officialSource['name_en'] ?? 'Official source')) ?: 'Official source',
            'url' => $officialUrl,
        ];
        if ($structured['official_links'] === []) {
            $structured['official_links'][] = ['label_ar' => $structured['official_source']['name_ar'], 'label_en' => $structured['official_source']['name_en'], 'url' => $officialUrl];
        }
        $structured['schema_jsonld'] = $this->buildSchema($structured);

        // Backward-compatible primary fields: Arabic is primary for SEO and article listing.
        $structured['title'] = trim((string) $structured['title_ar']);
        $structured['seo_title'] = trim((string) $structured['seo_title_ar']);
        $structured['seo_description'] = trim((string) $structured['seo_description_ar']);
        $structured['summary'] = trim((string) $structured['summary_ar']);
        $structured['content_html'] = $this->composeBilingualHtml(
            (string) $structured['content_html_ar'],
            (string) $structured['content_html_en'],
            $structured
        );

        $pretty = json_encode($structured, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($pretty === false) {
            return ['ok' => false, 'error' => 'تعذر ترميز المسودة المنظمة.'];
        }

        return [
            'ok' => true,
            'text' => $pretty,
            'data' => $structured,
            'provider' => (string) (parse_url($endpoint, PHP_URL_HOST) ?: 'custom'),
        ];
    }

    private function composeBilingualHtml(string $ar, string $en, array $data): string
    {
        $arExtra = $this->renderStructuredSections($data, 'ar');
        $enExtra = $this->renderStructuredSections($data, 'en');
        $sources = '';
        $urls = is_array($data['source_urls'] ?? null) ? $data['source_urls'] : [];
        if ($urls !== []) {
            $items = [];
            foreach ($urls as $url) {
                $safe = htmlspecialchars((string) $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $items[] = '<li><a href="' . $safe . '" rel="nofollow noopener">' . $safe . '</a></li>';
            }
            $sources = '<section lang="ar" dir="rtl"><h2>المصادر الرسمية</h2><ul>' . implode('', $items) . '</ul></section>';
        }
        return '<section lang="ar" dir="rtl">' . trim($ar) . $arExtra . '</section><hr>'
            . '<section lang="en" dir="ltr"><h2>English</h2>' . trim($en) . $enExtra . '</section>'
            . ($sources !== '' ? '<hr>' . $sources : '');
    }

    private function renderStructuredSections(array $data, string $lang): string
    {
        $isAr = $lang === 'ar';
        $html = '';
        $simple = [
            'conditions' => $isAr ? 'الشروط' : 'Conditions',
            'fees' => $isAr ? 'الرسوم' : 'Fees',
            'documents' => $isAr ? 'المستندات المطلوبة' : 'Required documents',
            'updates' => $isAr ? 'التحديثات' : 'Updates',
        ];
        if (!empty($data['steps'])) {
            $html .= '<h2>' . ($isAr ? 'الخطوات' : 'Steps') . '</h2><ol>';
            foreach ((array) $data['steps'] as $row) {
                $title = trim((string) ($row[$isAr ? 'title_ar' : 'title_en'] ?? ''));
                $desc = trim((string) ($row[$isAr ? 'description_ar' : 'description_en'] ?? ''));
                if ($title !== '' || $desc !== '') $html .= '<li><strong>' . $this->esc($title) . '</strong>' . ($desc !== '' ? '<br>' . $this->esc($desc) : '') . '</li>';
            }
            $html .= '</ol>';
        }
        foreach ($simple as $key => $heading) {
            if (empty($data[$key])) continue;
            $html .= '<h2>' . $heading . '</h2><ul>';
            foreach ((array) $data[$key] as $row) {
                if (!is_array($row)) continue;
                if ($key === 'conditions') $text = (string) ($row[$isAr ? 'text_ar' : 'text_en'] ?? '');
                elseif ($key === 'fees') $text = trim((string) ($row[$isAr ? 'label_ar' : 'label_en'] ?? '')) . ': ' . trim((string) ($row[$isAr ? 'value_ar' : 'value_en'] ?? ''));
                elseif ($key === 'documents') $text = trim((string) ($row[$isAr ? 'name_ar' : 'name_en'] ?? '')) . ($row[$isAr ? 'note_ar' : 'note_en'] ?? '' ? ' — ' . trim((string) $row[$isAr ? 'note_ar' : 'note_en']) : '');
                else $text = trim((string) ($row[$isAr ? 'summary_ar' : 'summary_en'] ?? '')) . (!empty($row['date']) ? ' (' . trim((string) $row['date']) . ')' : '');
                if (trim($text) !== '') $html .= '<li>' . $this->esc(trim($text, ' :—')) . '</li>';
            }
            $html .= '</ul>';
        }
        if (!empty($data['faq'])) {
            $html .= '<h2>' . ($isAr ? 'الأسئلة الشائعة' : 'FAQ') . '</h2>';
            foreach ((array) $data['faq'] as $row) {
                if (!is_array($row)) continue;
                $q = trim((string) ($row[$isAr ? 'question_ar' : 'question_en'] ?? ''));
                $a = trim((string) ($row[$isAr ? 'answer_ar' : 'answer_en'] ?? ''));
                if ($q !== '' && $a !== '') $html .= '<h3>' . $this->esc($q) . '</h3><p>' . $this->esc($a) . '</p>';
            }
        }
        if (!empty($data['internal_links'])) {
            $html .= '<h2>' . ($isAr ? 'خدمات مرتبطة' : 'Related services') . '</h2><ul>';
            foreach ((array) $data['internal_links'] as $row) {
                if (!is_array($row) || empty($row['slug'])) continue;
                $anchor = trim((string) ($row[$isAr ? 'anchor_ar' : 'anchor_en'] ?? $row['service_name'] ?? ''));
                $slug = rawurlencode((string) $row['slug']);
                if ($anchor !== '') $html .= '<li><a href="/service/' . $slug . '">' . $this->esc($anchor) . '</a></li>';
            }
            $html .= '</ul>';
        }
        return $html;
    }

    private function serviceCatalog(): array
    {
        try {
            $rows = Database::fetchAll("SELECT name,slug,summary FROM services WHERE slug IS NOT NULL AND slug<>'' ORDER BY CASE status WHEN 'published' THEN 0 ELSE 1 END, updated_at DESC LIMIT 60");
            $out = [];
            foreach ($rows as $row) {
                $slug = trim((string) ($row['slug'] ?? ''));
                $name = trim((string) ($row['name'] ?? ''));
                if ($slug === '' || $name === '') continue;
                $out[$slug] = ['name' => $name, 'slug' => $slug, 'summary' => trim((string) ($row['summary'] ?? ''))];
            }
            return array_values($out);
        } catch (\Throwable) {
            return [];
        }
    }

    private function serviceCatalogText(array $catalog): string
    {
        if ($catalog === []) return 'لا توجد خدمات داخلية متاحة حاليًا.';
        $lines = [];
        foreach ($catalog as $row) $lines[] = '- ' . $row['name'] . ' | slug=' . $row['slug'] . ($row['summary'] !== '' ? ' | ' . mb_substr($row['summary'], 0, 160) : '');
        return implode("\n", $lines);
    }

    private function cleanObjectList(mixed $value, array $preferredKeys = []): array
    {
        if (!is_array($value)) return [];
        $out = [];
        foreach ($value as $row) {
            if (!is_array($row)) continue;
            $clean = [];
            $keys = $preferredKeys !== [] ? array_unique(array_merge($preferredKeys, array_keys($row))) : array_keys($row);
            foreach ($keys as $key) {
                if (!array_key_exists($key, $row) || (!is_scalar($row[$key]) && $row[$key] !== null)) continue;
                $clean[(string) $key] = trim((string) ($row[$key] ?? ''));
            }
            if ($clean !== [] && implode('', $clean) !== '') $out[] = $clean;
        }
        return $out;
    }

    private function filterOfficialLinkObjects(mixed $value, array $allowedUrls, int $limit): array
    {
        $out = [];
        foreach ($this->cleanObjectList($value) as $row) {
            $url = trim((string) ($row['url'] ?? ''));
            if (!in_array($url, $allowedUrls, true)) continue;
            $out[] = ['label_ar' => trim((string) ($row['label_ar'] ?? 'رابط رسمي')) ?: 'رابط رسمي', 'label_en' => trim((string) ($row['label_en'] ?? 'Official link')) ?: 'Official link', 'url' => $url];
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    private function filterSourceBackedObjects(mixed $value, array $allowedUrls, int $limit): array
    {
        $out = [];
        foreach ($this->cleanObjectList($value) as $row) {
            $url = trim((string) ($row['source_url'] ?? ''));
            if ($url === '' || !in_array($url, $allowedUrls, true)) continue;
            $row['source_url'] = $url;
            $out[] = $row;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    private function filterCatalogItems(mixed $value, array $catalog, int $limit): array
    {
        $bySlug = [];
        foreach ($catalog as $row) $bySlug[(string) $row['slug']] = $row;
        $out = [];
        foreach ($this->cleanObjectList($value) as $row) {
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug === '' || !isset($bySlug[$slug])) continue;
            $row['service_name'] = $bySlug[$slug]['name'];
            $row['slug'] = $slug;
            $row['url'] = '/service/' . $slug;
            $out[$slug] = $row;
            if (count($out) >= $limit) break;
        }
        return array_values($out);
    }

    private function buildSchema(array $data): array
    {
        $graph = [[
            '@type' => 'Service',
            'name' => (string) ($data['title_ar'] ?? ''),
            'description' => (string) ($data['summary_ar'] ?? ''),
            'provider' => [
                '@type' => 'Organization',
                'name' => (string) ($data['official_source']['name_ar'] ?? 'المصدر الرسمي'),
                'url' => (string) ($data['official_source']['url'] ?? ''),
            ],
        ]];
        if (!empty($data['faq'])) {
            $entities = [];
            foreach ($data['faq'] as $row) {
                $q = trim((string) ($row['question_ar'] ?? ''));
                $a = trim((string) ($row['answer_ar'] ?? ''));
                if ($q !== '' && $a !== '') $entities[] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]];
            }
            if ($entities !== []) $graph[] = ['@type' => 'FAQPage', 'mainEntity' => $entities];
        }
        if (!empty($data['steps'])) {
            $steps = [];
            foreach ($data['steps'] as $row) {
                $name = trim((string) ($row['title_ar'] ?? ''));
                $text = trim((string) ($row['description_ar'] ?? ''));
                if ($name !== '' || $text !== '') $steps[] = ['@type' => 'HowToStep', 'name' => $name, 'text' => $text];
            }
            if ($steps !== []) $graph[] = ['@type' => 'HowTo', 'name' => (string) ($data['title_ar'] ?? ''), 'step' => $steps];
        }
        return ['@context' => 'https://schema.org', '@graph' => $graph];
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function stripReasoning(string $text): string
    {
        $text = preg_replace('~<think>.*?</think>~isu', '', $text) ?? $text;
        $text = preg_replace('~^\s*<think>.*$~isu', '', $text) ?? $text;
        return trim($text);
    }

    private function decodeJsonObject(string $text): ?array
    {
        $candidate = trim($text);
        $candidate = preg_replace('/^```(?:json)?\s*/iu', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/\s*```$/u', '', $candidate) ?? $candidate;

        $data = json_decode($candidate, true);
        if (is_array($data)) return $data;

        $start = strpos($candidate, '{');
        $end = strrpos($candidate, '}');
        if ($start === false || $end === false || $end <= $start) return null;
        $data = json_decode(substr($candidate, $start, $end - $start + 1), true);
        return is_array($data) ? $data : null;
    }

    private function extractSourceUrls(string $sources): array
    {
        if (!preg_match_all('#https?://[^\s<>"\']+#iu', $sources, $matches)) return [];
        $urls = [];
        foreach ($matches[0] as $url) {
            $url = rtrim(trim($url), ".,،؛;:!?)\]}");
            if (filter_var($url, FILTER_VALIDATE_URL)) $urls[] = $url;
        }
        return array_values(array_unique($urls));
    }

    private function cleanStringList(mixed $value): array
    {
        if (!is_array($value)) return [];
        $items = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) continue;
            $item = trim((string) $item);
            if ($item !== '') $items[] = $item;
        }
        return array_values(array_unique($items));
    }
}
