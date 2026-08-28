<?php

declare(strict_types=1);

namespace Khatauat\Services;

use Khatauat\Core\Database;

/**
 * Safe outbound bridge for approved marketing assets.
 * Native social OAuth adapters can be added later; this bridge makes the
 * current build usable with n8n, Make or a private integration worker without
 * storing social access tokens in SQLite.
 */
final class MarketingPublishService
{
    public function queue(int $assetId, ?string $scheduledAt, ?int $userId): array
    {
        $asset=Database::fetch('SELECT * FROM marketing_assets WHERE id=?',[$assetId]);
        if(!$asset)return ['ok'=>false,'error'=>'المادة التسويقية غير موجودة.'];
        if(($asset['status']??'')!=='approved')return ['ok'=>false,'error'=>'يجب اعتماد المادة قبل إرسالها للنشر.'];
        $when=$this->normalizeSchedule($scheduledAt);
        if(trim((string)$scheduledAt)!=='' && $when===null)return ['ok'=>false,'error'=>'موعد الجدولة غير صالح.'];
        Database::execute('INSERT INTO marketing_publications(asset_id,destination_key,status,scheduled_at,created_by,created_at,updated_at) VALUES(?,"automation_webhook","queued",?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)',[$assetId,$when,$userId]);
        Database::execute('UPDATE marketing_assets SET status="scheduled",scheduled_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$when,$assetId]);
        return ['ok'=>true,'publication_id'=>Database::lastInsertId(),'scheduled_at'=>$when];
    }

    public function dispatchDue(int $limit=20): array
    {
        $limit=max(1,min(100,$limit));
        $rows=Database::fetchAll('SELECT id FROM marketing_publications WHERE status="queued" AND (scheduled_at IS NULL OR scheduled_at<=CURRENT_TIMESTAMP) ORDER BY id LIMIT '.$limit);
        $summary=['processed'=>0,'published'=>0,'dispatched'=>0,'failed'=>0];
        foreach($rows as $row){
            $result=$this->dispatch((int)$row['id']);
            $summary['processed']++;
            if(($result['state']??'')==='published')$summary['published']++;
            elseif(($result['ok']??false))$summary['dispatched']++;
            else $summary['failed']++;
        }
        return $summary;
    }

    public function dispatch(int $publicationId): array
    {
        $row=Database::fetch('SELECT p.*,a.platform_key,a.asset_type,a.title,a.content,a.cta,a.hashtags,a.campaign_id,c.name campaign_name,c.objective,c.audience FROM marketing_publications p JOIN marketing_assets a ON a.id=p.asset_id JOIN marketing_campaigns c ON c.id=a.campaign_id WHERE p.id=?',[$publicationId]);
        if(!$row)return ['ok'=>false,'error'=>'مهمة النشر غير موجودة.'];
        if(!in_array((string)$row['status'],['queued','failed'],true))return ['ok'=>false,'error'=>'مهمة النشر ليست قابلة للإرسال في حالتها الحالية.'];
        $integration=Database::fetch('SELECT * FROM integration_catalog WHERE provider_key="automation_webhook" LIMIT 1');
        if(!$integration||(int)$integration['enabled']!==1)return $this->fail($publicationId,'موصل النشر Automation Bridge غير مفعّل.');
        $config=json_decode((string)$integration['public_config_json'],true); if(!is_array($config))$config=[];
        $endpoint=trim((string)($config['endpoint_url']??''));
        if(!$this->safeEndpoint($endpoint))return $this->fail($publicationId,'أضف رابط HTTPS صالح لموصل n8n/Make/Webhook في صفحة التكاملات.');
        $secret=(string)(getenv('AUTOMATION_WEBHOOK_SECRET')?:'');
        if(trim($secret)==='')return $this->fail($publicationId,'AUTOMATION_WEBHOOK_SECRET غير مضبوط على الخادم.');
        if(!function_exists('curl_init'))return $this->fail($publicationId,'امتداد PHP cURL غير مفعّل.');
        Database::execute('UPDATE marketing_publications SET status="dispatching",attempts=attempts+1,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$publicationId]);
        $payload=[
            'event'=>'khatauat.marketing.publish',
            'publication_id'=>$publicationId,
            'asset_id'=>(int)$row['asset_id'],
            'platform'=>(string)$row['platform_key'],
            'asset_type'=>(string)$row['asset_type'],
            'campaign'=>['id'=>(int)$row['campaign_id'],'name'=>(string)$row['campaign_name'],'objective'=>(string)$row['objective'],'audience'=>(string)$row['audience']],
            'content'=>['title'=>(string)$row['title'],'body'=>(string)$row['content'],'cta'=>(string)$row['cta'],'hashtags'=>(string)$row['hashtags']],
            'requested_at'=>gmdate('c'),
        ];
        $body=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $signature=hash_hmac('sha256',(string)$body,$secret);
        $ch=curl_init($endpoint);
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Khatauat-Signature: sha256='.$signature,'X-Khatauat-Event: marketing.publish'],
            CURLOPT_POSTFIELDS=>$body,
        ]);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
        if($raw===false||$status<200||$status>=300)return $this->fail($publicationId,$error?:('Webhook HTTP '.$status));
        $json=json_decode((string)$raw,true);$published=is_array($json)&&($json['published']??false)===true;
        $externalId=is_array($json)?trim((string)($json['external_id']??$json['id']??'')):'';
        $newStatus=$published?'published':'dispatched';
        Database::execute('UPDATE marketing_publications SET status=?,dispatched_at=CURRENT_TIMESTAMP,external_id=?,response_excerpt=?,error_detail=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$newStatus,$externalId,mb_substr((string)$raw,0,1000),$publicationId]);
        Database::execute('UPDATE marketing_assets SET status=?,external_id=COALESCE(NULLIF(?,""),external_id),updated_at=CURRENT_TIMESTAMP WHERE id=?',[$published?'published':'scheduled',$externalId,(int)$row['asset_id']]);
        return ['ok'=>true,'state'=>$newStatus,'external_id'=>$externalId];
    }

    private function fail(int $id,string $error): array
    {
        Database::execute('UPDATE marketing_publications SET status="failed",error_detail=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[mb_substr($error,0,1500),$id]);
        return ['ok'=>false,'state'=>'failed','error'=>$error];
    }

    private function normalizeSchedule(?string $value): ?string
    {
        $value=trim((string)$value); if($value==='')return null;
        $ts=strtotime($value); if($ts===false)return null;
        return date('Y-m-d H:i:s',$ts);
    }

    private function safeEndpoint(string $url): bool
    {
        if(!filter_var($url,FILTER_VALIDATE_URL)||strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https')return false;
        $host=strtolower((string)(parse_url($url,PHP_URL_HOST)?:''));
        if($host===''||in_array($host,['localhost','127.0.0.1','::1'],true))return false;
        if(filter_var($host,FILTER_VALIDATE_IP)){
            return filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)!==false;
        }
        // Reject hostnames that currently resolve to loopback/private/reserved IPs.
        // This reduces SSRF risk for an administrator-configured webhook.
        $ips=[];
        $v4=@gethostbynamel($host); if(is_array($v4))$ips=array_merge($ips,$v4);
        if(function_exists('dns_get_record')){
            $records=@dns_get_record($host,DNS_AAAA); if(is_array($records))foreach($records as $r)if(!empty($r['ipv6']))$ips[]=(string)$r['ipv6'];
        }
        if(!$ips)return false;
        foreach(array_unique($ips) as $ip){
            if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false)return false;
        }
        return true;
    }
}
