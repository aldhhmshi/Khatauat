<?php

declare(strict_types=1);

namespace Khatauat\Services;

use Khatauat\Core\Database;

final class IntegrationCatalog
{
    public function seed(): int
    {
        $items = [
            ['openai','OpenAI — مدير AI','ai',['OPENAI_API_KEY'],['research','content','seo','marketing','tool_calling']],
            ['exa','Exa — البحث العميق والمصادر','ai',['EXA_API_KEY'],['web_search','source_discovery','research']],
            ['google_analytics','Google Analytics 4','analytics',[],['analytics','audiences']],
            ['google_search_console','Google Search Console','seo',['GOOGLE_CLIENT_ID','GOOGLE_CLIENT_SECRET'],['queries','indexing_insights','seo']],
            ['google_ads','Google Ads','marketing',['GOOGLE_ADS_DEVELOPER_TOKEN','GOOGLE_CLIENT_ID','GOOGLE_CLIENT_SECRET'],['campaigns','ads','measurement']],
            ['google_business_profile','Google Business Profile','marketing',['GOOGLE_CLIENT_ID','GOOGLE_CLIENT_SECRET'],['locations','posts','reviews','insights']],
            ['bing_webmaster','Bing Webmaster Tools','seo',['MICROSOFT_CLIENT_ID','MICROSOFT_CLIENT_SECRET'],['seo','indexing','search_insights']],
            ['google_adsense','Google AdSense','monetization',[],['ads','revenue']],
            ['monetag','Monetag','monetization',[],['ads','revenue']],
            ['youtube','YouTube','social',['GOOGLE_CLIENT_ID','GOOGLE_CLIENT_SECRET'],['publish','analytics','content']],
            ['meta','Meta — Facebook & Instagram','social',['META_APP_ID','META_APP_SECRET','META_ACCESS_TOKEN'],['publish','ads','analytics']],
            ['tiktok','TikTok','social',['TIKTOK_CLIENT_KEY','TIKTOK_CLIENT_SECRET'],['publish','ads','analytics']],
            ['snapchat','Snapchat','social',['SNAP_CLIENT_ID','SNAP_CLIENT_SECRET'],['ads','analytics','content']],
            ['x','X','social',['X_CLIENT_ID','X_CLIENT_SECRET'],['publish','analytics']],
            ['linkedin','LinkedIn','social',['LINKEDIN_CLIENT_ID','LINKEDIN_CLIENT_SECRET'],['publish','analytics']],
            ['pinterest','Pinterest','social',['PINTEREST_APP_ID','PINTEREST_APP_SECRET'],['publish','analytics']],
            ['whatsapp_business','WhatsApp Business','messaging',['WHATSAPP_ACCESS_TOKEN','WHATSAPP_PHONE_NUMBER_ID'],['messages','leads','notifications']],
            ['telegram','Telegram','messaging',['TELEGRAM_BOT_TOKEN'],['messages','notifications']],
            ['smtp','Email / SMTP','messaging',['SMTP_PASSWORD'],['email','notifications','campaigns']],
            ['automation_webhook','Automation Bridge — n8n / Make / Webhook','automation',['AUTOMATION_WEBHOOK_SECRET'],['publish_bridge','workflows','crm','multi_platform']],
        ];
        foreach ($items as [$key,$name,$category,$secrets,$caps]) {
            Database::execute('INSERT INTO integration_catalog(provider_key,name,category,secret_env_keys,capabilities_json,created_at,updated_at) VALUES(?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON CONFLICT(provider_key) DO UPDATE SET name=excluded.name,category=excluded.category,secret_env_keys=excluded.secret_env_keys,capabilities_json=excluded.capabilities_json,updated_at=CURRENT_TIMESTAMP',[$key,$name,$category,json_encode($secrets),json_encode($caps)]);
        }
        return count($items);
    }

    public function refreshStatuses(): void
    {
        foreach (Database::fetchAll('SELECT * FROM integration_catalog') as $row) {
            $required = json_decode((string)$row['secret_env_keys'], true); if(!is_array($required)) $required=[];
            $missing=[]; foreach($required as $key) if(trim((string)(getenv((string)$key)?:''))==='') $missing[]=$key;
            $enabled=(int)$row['enabled']===1;
            $status = !$enabled ? 'disabled' : ($missing ? 'needs_attention' : 'configured');
            if ($row['provider_key']==='google_analytics' && $enabled) {
                if (trim((string)\setting('ga4_measurement_id',''))==='') { $status='needs_attention'; $missing[]='GA4 Measurement ID'; }
                else $status='configured';
            }
            if ($row['provider_key']==='google_adsense' && $enabled) {
                if (trim((string)\setting('adsense_code',''))==='') { $status='needs_attention'; $missing[]='AdSense code'; }
                else $status='configured';
            }
            if ($row['provider_key']==='monetag' && $enabled) {
                if (trim((string)\setting('monetag_code',''))==='') { $status='needs_attention'; $missing[]='Monetag code'; }
                else $status='configured';
            }
            if ($row['provider_key']==='automation_webhook' && $enabled && !$missing) {
                $cfg=json_decode((string)$row['public_config_json'],true); if(!is_array($cfg))$cfg=[];
                $endpoint=trim((string)($cfg['endpoint_url']??''));
                if(!filter_var($endpoint,FILTER_VALIDATE_URL) || !str_starts_with(strtolower($endpoint),'https://')) {
                    $status='needs_attention'; $missing[]='HTTPS endpoint_url';
                }
            }
            Database::execute('UPDATE integration_catalog SET status=?,last_checked_at=CURRENT_TIMESTAMP,notes=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$status,$missing?('مفاتيح الخادم المطلوبة: '.implode(', ',$missing)):'جاهز من ناحية الإعدادات المحلية.',(int)$row['id']]);
        }
    }
}
