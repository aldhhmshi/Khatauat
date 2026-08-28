<?php

declare(strict_types=1);

namespace Khatauat\Services;

use Khatauat\Core\Settings;

final class OpenAiAgentService
{
    public function isConfigured(): bool
    {
        return trim((string)(getenv('OPENAI_API_KEY') ?: '')) !== '' && $this->model() !== '';
    }

    public function model(): string
    {
        return trim((string)(Settings::get('openai_model', getenv('OPENAI_MODEL') ?: '')));
    }

    public function respond(string $instructions, string $input, bool $webSearch = false): array
    {
        $key = trim((string)(getenv('OPENAI_API_KEY') ?: ''));
        $model = $this->model();
        if ($key === '' || $model === '') {
            return ['ok'=>false,'error'=>'أضف OPENAI_API_KEY واسم النموذج من إعدادات الخادم قبل تشغيل مدير AI.'];
        }
        if (!function_exists('curl_init')) {
            return ['ok'=>false,'error'=>'امتداد PHP cURL غير مفعّل.'];
        }

        $endpoint = trim((string)Settings::get('openai_responses_url', 'https://api.openai.com/v1/responses'));
        $parts = parse_url($endpoint);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || strtolower((string)($parts['host'] ?? '')) !== 'api.openai.com' || (string)($parts['path'] ?? '') !== '/v1/responses') {
            $endpoint = 'https://api.openai.com/v1/responses';
        }
        $payload = [
            'model' => $model,
            'instructions' => $instructions,
            'input' => $input,
        ];
        if ($webSearch) $payload['tools'] = [['type'=>'web_search']];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$key, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) return ['ok'=>false,'error'=>$error ?: 'تعذر الاتصال بـ OpenAI.'];
        $json = json_decode((string)$raw, true);
        if ($status < 200 || $status >= 300) {
            $detail = is_array($json) ? (string)($json['error']['message'] ?? '') : '';
            return ['ok'=>false,'error'=>trim($detail) !== '' ? $detail : ('OpenAI HTTP '.$status)];
        }
        $text = $this->extractText(is_array($json) ? $json : []);
        if ($text === '') return ['ok'=>false,'error'=>'عاد OpenAI باستجابة بلا نص قابل للاستخدام.'];
        return ['ok'=>true,'text'=>$text,'response_id'=>(string)($json['id'] ?? ''),'model'=>(string)($json['model'] ?? $model)];
    }

    public function marketingPack(string $name, string $objective, string $audience, string $offer): array
    {
        $instructions = 'أنت مدير نمو وتسويق لمنصة سعودية اسمها خطوات. أنشئ مواد تسويقية دقيقة وغير مضللة. أعد JSON فقط بلا Markdown بالمفاتيح strategy وassets. assets مصفوفة، وكل عنصر: platform,asset_type,title,content,cta,hashtags. المنصات المطلوبة: google_ads,facebook,instagram,tiktok,snapchat,x,linkedin,youtube,whatsapp. اجعل المحتوى عربيًا سعوديًا مهنيًا، ولا تدّع أن المنصة جهة حكومية.';
        $input = "اسم الحملة: {$name}\nالهدف: {$objective}\nالجمهور: {$audience}\nالعرض/الرسالة: {$offer}";
        $result = $this->respond($instructions, $input, false);
        if (!($result['ok'] ?? false)) return $result;
        $decoded = $this->decodeJsonObject((string)$result['text']);
        if (!$decoded) return ['ok'=>false,'error'=>'تعذر قراءة حزمة التسويق المنظمة من استجابة AI.','raw'=>$result['text']];
        return ['ok'=>true,'data'=>$decoded,'raw'=>$result['text']];
    }

    public function sourceDiscoveryFromEvidence(string $focus, array $evidence): array
    {
        $instructions = 'أنت مدقق مصادر لمنصة إرشادية سعودية. لديك نتائج بحث من Exa. صنّف فقط الصفحات التي تبدو مصادر رسمية حكومية أو شبه حكومية أو منصات حكومية. أعد JSON فقط بالشكل {"sources":[{"name":"","entity":"","sector":"","authority_type":"government|semi_government|government_platform|regulator|official_gazette|reference","source_role":"reference|regulation|service|execution|data|verification|directory","url":"https://...","notes":"سبب الاعتماد أو سبب الحاجة للمراجعة"}]}. لا تختر مواقع تجارية لمجرد أنها سعودية، ولا تختر نتيجة لا تدعم النطاق.';
        $compact=[];
        foreach(array_slice($evidence,0,40) as $row){
            if(!is_array($row))continue;
            $compact[]=['title'=>(string)($row['title']??''),'url'=>(string)($row['url']??''),'publishedDate'=>(string)($row['publishedDate']??'')];
        }
        $input = 'نطاق البحث: '.$focus."\nنتائج Exa المرشحة:\n".json_encode($compact,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $result=$this->respond($instructions,$input,false);
        if(!($result['ok']??false))return $result;
        $decoded=$this->decodeJsonObject((string)$result['text']);
        if(!$decoded)return ['ok'=>false,'error'=>'تعذر تصنيف نتائج Exa كبيانات منظمة.','raw'=>$result['text']];
        return ['ok'=>true,'data'=>$decoded,'raw'=>$result['text'],'research_provider'=>'exa'];
    }

    public function sourceDiscoveryPrompt(string $focus = 'الخدمات الحكومية وشبه الحكومية في السعودية'): array
    {
        $instructions = 'أنت باحث مصادر لمنصة إرشادية سعودية. استخدم البحث على الويب. ابحث عن مصادر رسمية حكومية أو منصات حكومية مسجلة فقط. أعد JSON فقط بالشكل {"sources":[{"name":"","entity":"","sector":"","authority_type":"government|semi_government|government_platform|regulator|official_gazette|reference","source_role":"reference|regulation|service|execution|data|verification|directory","url":"https://...","notes":"سبب الاعتماد"}]}. لا تضف موقعًا تجاريًا أو مدونة. إذا لم تتأكد من الملكية الرسمية فلا تضفه.';
        $input = 'اكتشف مصادر رسمية إضافية تخدم نطاق: '.$focus.'. ركز على المنصات التنفيذية التي يستخدمها المواطن والمقيم والمنشأة، ومصادر الأنظمة واللوائح والبيانات.';
        $result = $this->respond($instructions, $input, true);
        if (!($result['ok'] ?? false)) return $result;
        $decoded = $this->decodeJsonObject((string)$result['text']);
        if (!$decoded) return ['ok'=>false,'error'=>'تعذر قراءة نتائج اكتشاف المصادر كبيانات منظمة.','raw'=>$result['text']];
        return ['ok'=>true,'data'=>$decoded,'raw'=>$result['text']];
    }

    private function extractText(array $json): string
    {
        $parts = [];
        $walk = function(mixed $node) use (&$walk, &$parts): void {
            if (!is_array($node)) return;
            if (($node['type'] ?? '') === 'output_text' && is_string($node['text'] ?? null)) $parts[] = $node['text'];
            foreach ($node as $v) if (is_array($v)) $walk($v);
        };
        $walk($json['output'] ?? []);
        return trim(implode("\n", array_unique(array_filter($parts))));
    }

    private function decodeJsonObject(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $text) ?? $text;
        $decoded = json_decode($text, true);
        if (is_array($decoded)) return $decoded;
        $start = strpos($text, '{'); $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) return null;
        $decoded = json_decode(substr($text, $start, $end-$start+1), true);
        return is_array($decoded) ? $decoded : null;
    }
}
