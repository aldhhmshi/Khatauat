<?php

declare(strict_types=1);

namespace Khatauat\Services;

final class ArticleDraftMapper
{
    public static function decode(string $json): ?array
    {
        $data = json_decode(trim($json), true);
        if (!is_array($data)) return null;

        // v1.3.4 bilingual format: Arabic is primary, English follows.
        if (trim((string) ($data['title_ar'] ?? '')) !== '') {
            foreach (['title_ar','title_en','seo_title_ar','seo_title_en','seo_description_ar','seo_description_en','summary_ar','summary_en','content_html_ar','content_html_en'] as $field) {
                if (trim((string) ($data[$field] ?? '')) === '') return null;
            }
            $data['title'] = trim((string) $data['title_ar']);
            $data['seo_title'] = trim((string) $data['seo_title_ar']);
            $data['seo_description'] = trim((string) $data['seo_description_ar']);
            $data['summary'] = trim((string) $data['summary_ar']);
            $data['content_html_ar'] = self::sanitizeHtml((string) $data['content_html_ar']);
            $data['content_html_en'] = self::sanitizeHtml((string) $data['content_html_en']);
            $data['source_urls'] = self::normalizeUrls($data['source_urls'] ?? []);
            if ($data['source_urls'] === []) return null;
            $data['source_notes_ar'] = self::normalizeList($data['source_notes_ar'] ?? []);
            $data['source_notes_en'] = self::normalizeList($data['source_notes_en'] ?? []);
            $data['verification_notes'] = self::normalizeList($data['verification_notes'] ?? []);
            $data['missing_information'] = self::normalizeList($data['missing_information'] ?? []);
            $data['secondary_keywords_ar'] = self::normalizeList($data['secondary_keywords_ar'] ?? []);
            $data['secondary_keywords_en'] = self::normalizeList($data['secondary_keywords_en'] ?? []);
            foreach (['seo_questions','related_services_seo','official_links','steps','conditions','fees','documents','updates','faq','internal_links'] as $field) {
                $data[$field] = self::normalizeObjectList($data[$field] ?? []);
            }
            $data['official_source'] = is_array($data['official_source'] ?? null) ? $data['official_source'] : [];
            $data['schema_jsonld'] = is_array($data['schema_jsonld'] ?? null) ? $data['schema_jsonld'] : self::buildSchema($data);
            $data['slug'] = \slugify((string) ($data['slug'] ?? $data['title_ar']));
            $data['content_html'] = self::composeBilingualHtml($data['content_html_ar'], $data['content_html_en'], $data);
            $data['legacy'] = false;
            $data['bilingual'] = true;
            return $data;
        }

        // Backward compatibility with v1.3.x Arabic-only structured drafts.
        foreach (['title', 'seo_title', 'seo_description', 'summary', 'content_html'] as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') return null;
        }
        $data['slug'] = \slugify((string) ($data['slug'] ?? $data['title']));
        $data['source_urls'] = self::normalizeUrls($data['source_urls'] ?? []);
        $data['verification_notes'] = self::normalizeList($data['verification_notes'] ?? []);
        $data['missing_information'] = self::normalizeList($data['missing_information'] ?? []);
        $data['content_html'] = self::sanitizeHtml((string) $data['content_html']);
        $data['legacy'] = false;
        $data['bilingual'] = false;
        return $data;
    }

    public static function decodeDraft(array $draft): ?array
    {
        $structured = trim((string) ($draft['structured_json'] ?? ''));
        if ($structured !== '') {
            $data = self::decode($structured);
            if ($data) return $data;
        }

        $raw = trim((string) ($draft['result_text'] ?? ''));
        if ($raw === '') return null;

        $raw = self::stripReasoning($raw);
        $title = trim((string) ($draft['topic'] ?? '')) ?: 'مسودة مقال';
        $plain = preg_replace('/\s+/u', ' ', strip_tags($raw)) ?? $raw;
        $plain = trim($plain);
        $summary = self::cut($plain, 240);
        $seoTitle = self::cut($title, 58);
        $seoDescription = self::cut($plain, 155);
        $sources = self::normalizeUrls(self::extractUrls((string) ($draft['sources_text'] ?? '')));

        return [
            'title' => $title,
            'seo_title' => $seoTitle,
            'seo_description' => $seoDescription,
            'slug' => \slugify($title),
            'summary' => $summary,
            'content_html' => self::legacyTextToHtml($raw),
            'source_urls' => $sources,
            'verification_notes' => ['هذه مسودة قديمة؛ راجع التنسيق والمعلومات والمصادر قبل النشر.'],
            'missing_information' => ['المسودة لم تُولد بصيغة v1.3.4 الثنائية؛ يفضل إعادة توليدها.'],
            'legacy' => true,
            'bilingual' => false,
        ];
    }

    public static function normalizeUrls(mixed $value): array
    {
        $rows = is_array($value) ? $value : preg_split('/\R/u', (string) $value);
        $urls = [];
        foreach ($rows ?: [] as $row) {
            $url = trim((string) $row);
            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                $urls[] = $url;
            }
        }
        return array_values(array_unique($urls));
    }

    private static function composeBilingualHtml(string $ar, string $en, array $data): string
    {
        $arExtra = self::renderStructuredSections($data, 'ar');
        $enExtra = self::renderStructuredSections($data, 'en');
        $sources = '';
        $urls = self::normalizeUrls($data['source_urls'] ?? []);
        if ($urls !== []) {
            $items = [];
            foreach ($urls as $url) {
                $safe = htmlspecialchars((string) $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $items[] = '<li><a href="' . $safe . '" rel="nofollow noopener">' . $safe . '</a></li>';
            }
            $sources = '<section lang="ar" dir="rtl"><h2>المصادر الرسمية</h2><ul>' . implode('', $items) . '</ul></section>';
        }
        return '<section lang="ar" dir="rtl">' . $ar . $arExtra . '</section><hr>'
            . '<section lang="en" dir="ltr"><h2>English</h2>' . $en . $enExtra . '</section>'
            . ($sources !== '' ? '<hr>' . $sources : '');
    }

    private static function renderStructuredSections(array $data, string $lang): string
    {
        $isAr = $lang === 'ar';
        $html = '';
        if (!empty($data['steps'])) {
            $html .= '<h2>' . ($isAr ? 'الخطوات' : 'Steps') . '</h2><ol>';
            foreach ((array)$data['steps'] as $row) {
                $title = trim((string)($row[$isAr ? 'title_ar' : 'title_en'] ?? ''));
                $desc = trim((string)($row[$isAr ? 'description_ar' : 'description_en'] ?? ''));
                if ($title !== '' || $desc !== '') $html .= '<li><strong>' . self::esc($title) . '</strong>' . ($desc !== '' ? '<br>' . self::esc($desc) : '') . '</li>';
            }
            $html .= '</ol>';
        }
        $maps = [
            'conditions' => [$isAr ? 'الشروط' : 'Conditions', $isAr ? 'text_ar' : 'text_en'],
            'documents' => [$isAr ? 'المستندات المطلوبة' : 'Required documents', $isAr ? 'name_ar' : 'name_en'],
        ];
        foreach ($maps as $key => [$heading,$field]) {
            if (empty($data[$key])) continue;
            $html .= '<h2>' . $heading . '</h2><ul>';
            foreach ((array)$data[$key] as $row) {
                $text = trim((string)($row[$field] ?? ''));
                if ($key === 'documents') {
                    $note = trim((string)($row[$isAr ? 'note_ar' : 'note_en'] ?? ''));
                    if ($note !== '') $text .= ' — ' . $note;
                }
                if ($text !== '') $html .= '<li>' . self::esc($text) . '</li>';
            }
            $html .= '</ul>';
        }
        if (!empty($data['fees'])) {
            $html .= '<h2>' . ($isAr ? 'الرسوم' : 'Fees') . '</h2><ul>';
            foreach ((array)$data['fees'] as $row) {
                $label = trim((string)($row[$isAr ? 'label_ar' : 'label_en'] ?? ''));
                $value = trim((string)($row[$isAr ? 'value_ar' : 'value_en'] ?? ''));
                if ($label !== '' || $value !== '') $html .= '<li>' . self::esc(trim($label . ($label !== '' && $value !== '' ? ': ' : '') . $value)) . '</li>';
            }
            $html .= '</ul>';
        }
        if (!empty($data['updates'])) {
            $html .= '<h2>' . ($isAr ? 'التحديثات' : 'Updates') . '</h2><ul>';
            foreach ((array)$data['updates'] as $row) {
                $text = trim((string)($row[$isAr ? 'summary_ar' : 'summary_en'] ?? ''));
                $date = trim((string)($row['date'] ?? ''));
                if ($text !== '') $html .= '<li>' . self::esc($text . ($date !== '' ? ' (' . $date . ')' : '')) . '</li>';
            }
            $html .= '</ul>';
        }
        if (!empty($data['faq'])) {
            $html .= '<h2>' . ($isAr ? 'الأسئلة الشائعة' : 'FAQ') . '</h2>';
            foreach ((array)$data['faq'] as $row) {
                $q = trim((string)($row[$isAr ? 'question_ar' : 'question_en'] ?? ''));
                $a = trim((string)($row[$isAr ? 'answer_ar' : 'answer_en'] ?? ''));
                if ($q !== '' && $a !== '') $html .= '<h3>' . self::esc($q) . '</h3><p>' . self::esc($a) . '</p>';
            }
        }
        if (!empty($data['internal_links'])) {
            $html .= '<h2>' . ($isAr ? 'خدمات مرتبطة' : 'Related services') . '</h2><ul>';
            foreach ((array)$data['internal_links'] as $row) {
                $slug = trim((string)($row['slug'] ?? ''));
                $anchor = trim((string)($row[$isAr ? 'anchor_ar' : 'anchor_en'] ?? $row['service_name'] ?? ''));
                if ($slug !== '' && $anchor !== '') $html .= '<li><a href="/service/' . rawurlencode($slug) . '">' . self::esc($anchor) . '</a></li>';
            }
            $html .= '</ul>';
        }
        return $html;
    }

    private static function normalizeObjectList(mixed $value): array
    {
        if (!is_array($value)) return [];
        $out = [];
        foreach ($value as $row) {
            if (!is_array($row)) continue;
            $clean = [];
            foreach ($row as $key => $val) {
                if (is_scalar($val) || $val === null) $clean[(string)$key] = trim((string)($val ?? ''));
            }
            if ($clean !== [] && implode('', $clean) !== '') $out[] = $clean;
        }
        return $out;
    }

    private static function buildSchema(array $data): array
    {
        $graph = [[
            '@type' => 'Service',
            'name' => (string)($data['title_ar'] ?? $data['title'] ?? ''),
            'description' => (string)($data['summary_ar'] ?? $data['summary'] ?? ''),
        ]];
        if (!empty($data['faq'])) {
            $main = [];
            foreach ((array)$data['faq'] as $row) {
                $q = trim((string)($row['question_ar'] ?? ''));
                $a = trim((string)($row['answer_ar'] ?? ''));
                if ($q !== '' && $a !== '') $main[] = ['@type'=>'Question','name'=>$q,'acceptedAnswer'=>['@type'=>'Answer','text'=>$a]];
            }
            if ($main !== []) $graph[] = ['@type'=>'FAQPage','mainEntity'=>$main];
        }
        if (!empty($data['steps'])) {
            $steps = [];
            foreach ((array)$data['steps'] as $row) {
                $name = trim((string)($row['title_ar'] ?? ''));
                $text = trim((string)($row['description_ar'] ?? ''));
                if ($name !== '' || $text !== '') $steps[] = ['@type'=>'HowToStep','name'=>$name,'text'=>$text];
            }
            if ($steps !== []) $graph[] = ['@type'=>'HowTo','name'=>(string)($data['title_ar'] ?? ''),'step'=>$steps];
        }
        return ['@context'=>'https://schema.org','@graph'=>$graph];
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function extractUrls(string $text): array
    {
        preg_match_all('~https?://[^\s<>"\']+~iu', $text, $matches);
        return $matches[0] ?? [];
    }

    private static function normalizeList(mixed $value): array
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

    private static function stripReasoning(string $text): string
    {
        $text = preg_replace('~<think>.*?</think>~isu', '', $text) ?? $text;
        return trim($text);
    }

    private static function legacyTextToHtml(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        $lines = explode("\n", $text);
        $html = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $line = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $line = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $line) ?? $line;
            if (preg_match('/^###\s*(.+)$/u', $line, $m)) {
                $html[] = '<h3>' . $m[1] . '</h3>';
            } elseif (preg_match('/^##\s*(.+)$/u', $line, $m)) {
                $html[] = '<h2>' . $m[1] . '</h2>';
            } elseif (preg_match('/^#\s*(.+)$/u', $line, $m)) {
                $html[] = '<h2>' . $m[1] . '</h2>';
            } elseif (preg_match('/^(?:[-•]|\d+[\.)])\s*(.+)$/u', $line, $m)) {
                $html[] = '<p>• ' . $m[1] . '</p>';
            } else {
                $html[] = '<p>' . $line . '</p>';
            }
        }
        return self::sanitizeHtml(implode("\n", $html));
    }

    private static function cut(string $text, int $length): string
    {
        if (function_exists('mb_substr')) return trim((string) mb_substr($text, 0, $length));
        return trim(substr($text, 0, $length));
    }

    public static function sanitizeHtml(string $html): string
    {
        $allowed = '<section><h2><h3><p><ol><ul><li><strong><em><a><blockquote><table><thead><tbody><tr><th><td><br><hr>';
        $html = strip_tags($html, $allowed);
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html) ?? $html;
        $html = preg_replace('/\s+(?:style|srcdoc)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html) ?? $html;
        $html = preg_replace_callback('/<a\b([^>]*)href\s*=\s*(["\'])(.*?)\2([^>]*)>/iu', static function (array $m): string {
            $href = trim(html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
            if ($href === '' || !in_array($scheme, ['http', 'https'], true)) return '<a>';
            return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" rel="nofollow noopener">';
        }, $html) ?? $html;
        return trim($html);
    }
}
