<?php

declare(strict_types=1);

namespace Khatauat\Services;

final class ExaResearchService
{
    private const ENDPOINT = 'https://api.exa.ai/search';

    public function isConfigured(): bool
    {
        return trim((string)(getenv('EXA_API_KEY') ?: '')) !== '';
    }

    /**
     * Search the live web with Exa, returning only Saudi-domain candidates.
     * This is discovery evidence, not approval. The source registry always
     * requires owner review before operational monitoring.
     */
    public function searchSaudiSources(string $focus, int $limit = 20): array
    {
        $key = trim((string)(getenv('EXA_API_KEY') ?: ''));
        if ($key === '') return ['ok'=>false,'error'=>'EXA_API_KEY غير مضبوط.'];
        if (!function_exists('curl_init')) return ['ok'=>false,'error'=>'امتداد PHP cURL غير مفعّل.'];
        $limit = max(3, min(15, $limit));
        $query = 'Official Saudi government, semi-government, regulator or government-owned digital service platform pages for: '.$focus.'. Prefer primary sources, service directories, execution platforms, regulations and official data. Saudi Arabia.';
        $payload = [
            'query'=>$query,
            'type'=>'fast',
            'numResults'=>$limit,
        ];
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>12,
            CURLOPT_TIMEOUT=>60,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-api-key: '.$key],
            CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
        $raw=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
        if($raw===false) return ['ok'=>false,'error'=>$err?:'تعذر الاتصال بـ Exa.'];
        $json=json_decode((string)$raw,true);
        if($status<200||$status>=300){
            $message=is_array($json)?(string)($json['error']??$json['message']??''):'';
            return ['ok'=>false,'error'=>$message!==''?$message:('Exa HTTP '.$status)];
        }
        $rows=[];
        foreach((array)($json['results']??[]) as $r){
            if(!is_array($r))continue;
            $url=trim((string)($r['url']??$r['id']??''));
            if(!$this->isSaudiCandidateUrl($url))continue;
            $rows[]=[
                'title'=>trim((string)($r['title']??'')),
                'url'=>$url,
                'publishedDate'=>trim((string)($r['publishedDate']??'')),
                'author'=>trim((string)($r['author']??'')),
            ];
        }
        return ['ok'=>true,'results'=>$rows,'request_id'=>(string)($json['requestId']??'')];
    }

    public function asRegistryCandidates(array $results, string $sector='عام'): array
    {
        $out=[];
        foreach($results as $row){
            if(!is_array($row))continue;
            $url=trim((string)($row['url']??''));
            if(!$this->isSaudiCandidateUrl($url))continue;
            $host=strtolower((string)(parse_url($url,PHP_URL_HOST)?:''));
            $out[]=[
                'name'=>trim((string)($row['title']??$host)) ?: $host,
                'entity'=>'',
                'sector'=>$sector!==''?$sector:'عام',
                'authority_type'=>'reference',
                'source_role'=>'reference',
                'url'=>$url,
                'notes'=>'مرشح اكتشفه Exa؛ يحتاج التحقق من الملكية الرسمية والدور قبل الاعتماد.',
            ];
        }
        return $out;
    }

    private function isSaudiCandidateUrl(string $url): bool
    {
        if(!filter_var($url,FILTER_VALIDATE_URL))return false;
        if(strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https')return false;
        $host=strtolower((string)(parse_url($url,PHP_URL_HOST)?:''));
        return $host!=='' && ($host==='sa'||str_ends_with($host,'.sa'));
    }
}
