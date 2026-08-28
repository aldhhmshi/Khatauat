<?php

declare(strict_types=1);

namespace Khatauat\Controllers;

use Khatauat\Core\Auth;
use Khatauat\Core\Csrf;
use Khatauat\Core\Database;
use Khatauat\Core\Settings;
use Khatauat\Core\View;
use Khatauat\Services\AuditLog;
use Khatauat\Services\IntegrationCatalog;
use Khatauat\Services\ExaResearchService;
use Khatauat\Services\OpenAiAgentService;
use Khatauat\Services\MarketingPublishService;
use Khatauat\Services\SaudiSourceRegistry;

final class OwnerOpsController
{
    private function guard(): void { Auth::requireOwner(); }

    public function aiOps(): void
    {
        $this->guard();
        $ai = new OpenAiAgentService();
        $ops = Database::fetchAll('SELECT * FROM ai_operations ORDER BY id DESC LIMIT 30');
        $stats = [
            'sources'=>(int)(Database::fetch('SELECT COUNT(*) c FROM source_registry WHERE status="active"')['c']??0),
            'candidates'=>(int)(Database::fetch('SELECT COUNT(*) c FROM source_registry WHERE status="candidate"')['c']??0),
            'drafts'=>(int)(Database::fetch('SELECT COUNT(*) c FROM ai_drafts')['c']??0),
            'marketing'=>(int)(Database::fetch('SELECT COUNT(*) c FROM marketing_assets WHERE status="draft"')['c']??0),
        ];
        View::render('admin/ai_ops',['title'=>'مركز عمليات AI','ops'=>$ops,'configured'=>$ai->isConfigured(),'aiModel'=>$ai->model(),'stats'=>$stats]);
    }

    public function aiRun(): void
    {
        $this->guard(); Csrf::verify();
        $type = (string)($_POST['operation_type'] ?? 'growth_audit');
        $context = trim((string)($_POST['context'] ?? ''));
        $allowed = ['growth_audit','source_research','content_strategy','product_improvement'];
        if(!in_array($type,$allowed,true)) $type='growth_audit';
        $titles=[
            'growth_audit'=>'تدقيق نمو المنصة',
            'source_research'=>'بحث المصادر الرسمية',
            'content_strategy'=>'خطة المحتوى وSEO',
            'product_improvement'=>'تطوير تجربة المنتج',
        ];
        Database::execute('INSERT INTO ai_operations(operation_type,title,input_text,status,approval_required,created_by,created_at,updated_at) VALUES(?,?,?,"running",1,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)',[$type,$titles[$type],$context,Auth::id()]);
        $id=Database::lastInsertId();
        $ai=new OpenAiAgentService();
        $instructions='أنت مدير تشغيل ونمو لمنصة "خطوات" السعودية، وهي منصة مستقلة لا تمثل جهة حكومية. قدم خطة عملية قصيرة ذات أولويات ومخاطر وقياسات نجاح. لا تنفذ تغييرات حساسة ولا تدّع نتائج لم تتحقق.';
        $prompt=match($type){
            'source_research'=>'حلل فجوات المصادر الرسمية السعودية الضرورية لمنصة تربط الإجراءات من البداية للنهاية. استخدم البحث الحالي وميّز منصة التنفيذ عن المصدر التنظيمي. '.$context,
            'content_strategy'=>'اقترح خطة محتوى وSEO مبنية على نيات بحث سعودية عالية القيمة لخدمات وإجراءات وحاسبات، مع تجنب الحشو. '.$context,
            'product_improvement'=>'اقترح تحسينات منتج وتجربة مستخدم تجعل البحث عن الإجراء وتحويله لمسار خطوات أسرع وأكثر ثقة. '.$context,
            default=>'راجع فرص النمو والاكتساب والاحتفاظ والتحويل وتحقيق الدخل لمنصة خطوات. '.$context,
        };
        $result=$ai->respond($instructions,$prompt,$type==='source_research');
        if(!($result['ok']??false)){
            Database::execute('UPDATE ai_operations SET status="failed",error_detail=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[(string)($result['error']??'خطأ غير معروف'),$id]);
            \flash('error','تعذر تشغيل مدير AI: '.(string)($result['error']??''));
        } else {
            Database::execute('UPDATE ai_operations SET status="awaiting_approval",result_text=?,executed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?',[(string)$result['text'],$id]);
            AuditLog::write('ai.operation.created','ai_operation',$id,$titles[$type],['type'=>$type],'ai');
            \flash('success','اكتملت المهمة وأصبحت النتيجة بانتظار مراجعتك.');
        }
        \redirect('admin/ai-ops');
    }

    public function aiOperationStatus(): void
    {
        $this->guard(); Csrf::verify();
        $id=(int)($_POST['id']??0); $status=(string)($_POST['status']??'approved');
        if(!in_array($status,['approved','rejected'],true)) $status='rejected';
        Database::execute('UPDATE ai_operations SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$status,$id]);
        AuditLog::write('ai.operation.'.$status,'ai_operation',$id,'تحديث قرار مهمة AI');
        \redirect('admin/ai-ops');
    }

    public function integrations(): void
    {
        $this->guard();
        $catalog=new IntegrationCatalog(); $catalog->seed(); $catalog->refreshStatuses();
        $items=Database::fetchAll('SELECT * FROM integration_catalog ORDER BY category,name');
        View::render('admin/integrations',['title'=>'التكاملات','items'=>$items,'openaiModel'=>(string)Settings::get('openai_model',getenv('OPENAI_MODEL')?:''),'openaiUrl'=>(string)Settings::get('openai_responses_url','https://api.openai.com/v1/responses')]);
    }

    public function integrationSave(): void
    {
        $this->guard(); Csrf::verify();
        $key=trim((string)($_POST['provider_key']??''));
        $row=Database::fetch('SELECT * FROM integration_catalog WHERE provider_key=?',[$key]);
        if(!$row){\flash('error','التكامل غير معروف.');\redirect('admin/integrations');}
        $enabled=isset($_POST['enabled'])?1:0;
        $public=[
            'account_label'=>trim((string)($_POST['account_label']??'')),
            'property_id'=>trim((string)($_POST['property_id']??'')),
            'endpoint_url'=>trim((string)($_POST['endpoint_url']??'')),
        ];
        Database::execute('UPDATE integration_catalog SET enabled=?,public_config_json=?,updated_at=CURRENT_TIMESTAMP WHERE provider_key=?',[$enabled,json_encode($public,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$key]);
        if($key==='openai'){
            Settings::set('openai_model',trim((string)($_POST['openai_model']??'')));
            Settings::set('openai_responses_url','https://api.openai.com/v1/responses');
        }
        (new IntegrationCatalog())->refreshStatuses();
        AuditLog::write('integration.updated','integration',$key,'تحديث إعداد التكامل',['enabled'=>$enabled]);
        \flash('success','تم حفظ حالة التكامل. الأسرار تبقى في متغيرات البيئة ولا تُحفظ في قاعدة البيانات.');
        \redirect('admin/integrations');
    }

    public function marketing(): void
    {
        $this->guard();
        $campaigns=Database::fetchAll('SELECT c.*, (SELECT COUNT(*) FROM marketing_assets a WHERE a.campaign_id=c.id) assets_count FROM marketing_campaigns c ORDER BY c.id DESC LIMIT 30');
        $assets=Database::fetchAll('SELECT a.*,c.name campaign_name,(SELECT p.status FROM marketing_publications p WHERE p.asset_id=a.id ORDER BY p.id DESC LIMIT 1) publication_status,(SELECT p.error_detail FROM marketing_publications p WHERE p.asset_id=a.id ORDER BY p.id DESC LIMIT 1) publication_error FROM marketing_assets a JOIN marketing_campaigns c ON c.id=a.campaign_id ORDER BY a.id DESC LIMIT 60');
        $publishBridge=Database::fetch('SELECT * FROM integration_catalog WHERE provider_key="automation_webhook" LIMIT 1');
        View::render('admin/marketing',['title'=>'مركز التسويق AI','campaigns'=>$campaigns,'assets'=>$assets,'publishBridge'=>$publishBridge]);
    }

    public function marketingGenerate(): void
    {
        $this->guard(); Csrf::verify();
        $name=trim((string)($_POST['name']??'')); $objective=trim((string)($_POST['objective']??'')); $audience=trim((string)($_POST['audience']??'')); $offer=trim((string)($_POST['offer_text']??''));
        if($name===''||$objective===''||$audience===''){\flash('error','اسم الحملة والهدف والجمهور مطلوبة.');\redirect('admin/marketing');}
        $ai=new OpenAiAgentService(); $pack=$ai->marketingPack($name,$objective,$audience,$offer);
        if(!($pack['ok']??false)){\flash('error','تعذر إنشاء الحزمة التسويقية: '.(string)($pack['error']??''));\redirect('admin/marketing');}
        $data=is_array($pack['data']??null)?$pack['data']:[];
        Database::execute('INSERT INTO marketing_campaigns(name,objective,audience,offer_text,status,ai_strategy,created_by,created_at,updated_at) VALUES(?,?,?,?,"draft",?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)',[$name,$objective,$audience,$offer,(string)($data['strategy']??''),Auth::id()]);
        $cid=Database::lastInsertId(); $allowed=['google_ads','instagram','tiktok','snapchat','x','linkedin','youtube','whatsapp','facebook'];
        foreach(($data['assets']??[]) as $asset){
            if(!is_array($asset))continue; $platform=strtolower(trim((string)($asset['platform']??''))); if(!in_array($platform,$allowed,true))continue;
            Database::execute('INSERT INTO marketing_assets(campaign_id,platform_key,asset_type,title,content,cta,hashtags,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,"draft",CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)',[$cid,$platform,trim((string)($asset['asset_type']??'post')),trim((string)($asset['title']??'')),trim((string)($asset['content']??'')),trim((string)($asset['cta']??'')),is_array($asset['hashtags']??null)?implode(' ',array_map('strval',$asset['hashtags'])):trim((string)($asset['hashtags']??''))]);
        }
        AuditLog::write('marketing.campaign.generated','marketing_campaign',$cid,$name,[],'ai');
        \flash('success','تم إنشاء حملة وحزمة محتوى متعددة المنصات كمسودات. النشر الخارجي يحتاج ربط OAuth/API وموافقتك.');
        \redirect('admin/marketing');
    }

    public function marketingAssetStatus(): void
    {
        $this->guard(); Csrf::verify();
        $id=(int)($_POST['id']??0); $status=(string)($_POST['status']??'approved');
        if(!in_array($status,['draft','approved','rejected'],true))$status='draft';
        Database::execute('UPDATE marketing_assets SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$status,$id]);
        AuditLog::write('marketing.asset.status','marketing_asset',$id,$status);
        \redirect('admin/marketing');
    }

    public function marketingPublish(): void
    {
        $this->guard(); Csrf::verify();
        $id=(int)($_POST['id']??0);
        $scheduled=trim((string)($_POST['scheduled_at']??''));
        $publisher=new MarketingPublishService();
        $queued=$publisher->queue($id,$scheduled!==''?$scheduled:null,Auth::id());
        if(!($queued['ok']??false)){
            \flash('error',(string)($queued['error']??'تعذر إنشاء مهمة النشر.'));
            \redirect('admin/marketing');
        }
        $publicationId=(int)($queued['publication_id']??0);
        if($scheduled===''){
            $result=$publisher->dispatch($publicationId);
            if($result['ok']??false){
                $state=(string)($result['state']??'dispatched');
                \flash('success',$state==='published'?'أكد الموصل نشر المادة على المنصة الخارجية.':'تم تسليم المادة إلى موصل الأتمتة. راجع حالة المنصة الخارجية في سير العمل.');
            } else \flash('error','تم إنشاء مهمة النشر لكن تعذر إرسالها: '.(string)($result['error']??''));
        } else {
            \flash('success','تمت جدولة المادة. شغّل cron-marketing دوريًا لإرسال المهام المستحقة.');
        }
        AuditLog::write('marketing.asset.publish_queued','marketing_asset',$id,'إرسال/جدولة مادة للتسويق',['publication_id'=>$publicationId,'scheduled_at'=>$scheduled]);
        \redirect('admin/marketing');
    }

    public function sourceRegistry(): void
    {
        $this->guard();
        $registry=new SaudiSourceRegistry();
        if((int)(Database::fetch('SELECT COUNT(*) c FROM source_registry')['c']??0)===0)$registry->seedCore();
        $sector=trim((string)($_GET['sector']??''));
        $params=[]; $where='1=1'; if($sector!==''){$where.=' AND sector=?';$params[]=$sector;}
        $rows=Database::fetchAll('SELECT * FROM source_registry WHERE '.$where.' ORDER BY CASE status WHEN "candidate" THEN 0 ELSE 1 END, sector,name LIMIT 300',$params);
        $sectors=Database::fetchAll('SELECT sector,COUNT(*) c FROM source_registry GROUP BY sector ORDER BY sector');
        $stats=Database::fetch('SELECT COUNT(*) total,SUM(CASE WHEN status="active" THEN 1 ELSE 0 END) active,SUM(CASE WHEN status="candidate" THEN 1 ELSE 0 END) candidates FROM source_registry');
        View::render('admin/source_registry',['title'=>'السجل الوطني للمصادر','rows'=>$rows,'sectors'=>$sectors,'sector'=>$sector,'stats'=>$stats?:[]]);
    }

    public function sourceRegistrySeed(): void
    {
        $this->guard(); Csrf::verify();
        $n=(new SaudiSourceRegistry())->seedCore(); AuditLog::write('sources.core_seed','source_registry',null,'تحديث السجل الأساسي',['count'=>$n]);
        \flash('success','تمت مزامنة '.$n.' مصدرًا أساسيًا موثوقًا في السجل الوطني.'); \redirect('admin/source-registry');
    }

    public function sourceRegistryDiscover(): void
    {
        $this->guard(); Csrf::verify();
        $focus=trim((string)($_POST['focus']??'الخدمات الحكومية وشبه الحكومية السعودية'));
        $ai=new OpenAiAgentService();
        $exa=new ExaResearchService();
        $researchProvider='openai_web_search';
        if($exa->isConfigured()){
            $research=$exa->searchSaudiSources($focus,30);
            if($research['ok']??false){
                if($ai->isConfigured()){
                    $result=$ai->sourceDiscoveryFromEvidence($focus,(array)($research['results']??[]));
                    $researchProvider='exa+openai_validation';
                } else {
                    $result=['ok'=>true,'data'=>['sources'=>$exa->asRegistryCandidates((array)($research['results']??[]),$focus)]];
                    $researchProvider='exa_candidate_only';
                }
            } else {
                $result=$ai->sourceDiscoveryPrompt($focus);
            }
        } else {
            $result=$ai->sourceDiscoveryPrompt($focus);
        }
        if(!($result['ok']??false)){\flash('error','تعذر البحث: '.(string)($result['error']??''));\redirect('admin/source-registry');}
        $rows=is_array($result['data']['sources']??null)?$result['data']['sources']:[];
        $n=(new SaudiSourceRegistry())->importCandidates($rows);
        AuditLog::write('sources.ai_discovery','source_registry',null,'بحث AI عن مصادر',['focus'=>$focus,'candidate_count'=>$n,'research_provider'=>$researchProvider],'ai');
        \flash('success','تمت إضافة '.$n.' مصادر مرشحة فقط. لن تصبح مصادر تشغيلية قبل اعتمادك.'); \redirect('admin/source-registry');
    }

    public function sourceRegistryApprove(): void
    {
        $this->guard();
        Csrf::verify();

        $id=(int)($_POST['id']??0);
        $sector=trim((string)($_POST['sector']??''));
        $isAjax=strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH']??''))==='xmlhttprequest'
            || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT']??'')),'application/json');

        $respond=function(int $status,array $payload) use ($isAjax): void {
            if(!$isAjax) return;
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            exit;
        };

        $return='admin/source-registry'.($sector!==''?'?sector='.rawurlencode($sector):'').'#source-'.$id;

        if((string)($_POST['confirmed']??'')!=='1') {
            $respond(422,['ok'=>false,'message'=>'يجب تأكيد أنك تحققت من ملكية المصدر الرسمية قبل اعتماده.']);
            \flash('error','يجب تأكيد أنك تحققت من ملكية المصدر الرسمية قبل اعتماده.');
            \redirect($return);
        }

        $row=Database::fetch('SELECT id,name,url,status FROM source_registry WHERE id=?',[$id]);
        if(!$row) {
            $respond(404,['ok'=>false,'message'=>'المصدر غير موجود.']);
            \flash('error','المصدر غير موجود.');
            \redirect($return);
        }

        if((string)$row['status']==='active') {
            $stats=Database::fetch('SELECT SUM(CASE WHEN status="active" THEN 1 ELSE 0 END) active,SUM(CASE WHEN status="candidate" THEN 1 ELSE 0 END) candidates FROM source_registry')?:[];
            $respond(200,['ok'=>true,'already_active'=>true,'id'=>$id,'url'=>(string)$row['url'],'message'=>'المصدر معتمد بالفعل.','stats'=>['active'=>(int)($stats['active']??0),'candidates'=>(int)($stats['candidates']??0)]]);
            \redirect($return);
        }

        $ok=(new SaudiSourceRegistry())->activateToOperationalSource($id,(string)(Auth::user()['name']??'owner'));
        if(!$ok) {
            $respond(500,['ok'=>false,'message'=>'تعذر اعتماد المصدر.']);
            \flash('error','تعذر اعتماد المصدر.');
            \redirect($return);
        }

        AuditLog::write('source.approved','source_registry',$id,'اعتماد مصدر إلى المراقبة التشغيلية');
        $stats=Database::fetch('SELECT SUM(CASE WHEN status="active" THEN 1 ELSE 0 END) active,SUM(CASE WHEN status="candidate" THEN 1 ELSE 0 END) candidates FROM source_registry')?:[];
        $respond(200,[
            'ok'=>true,
            'id'=>$id,
            'url'=>(string)$row['url'],
            'message'=>'تم اعتماد المصدر وإضافته إلى مراقبة المصادر.',
            'stats'=>['active'=>(int)($stats['active']??0),'candidates'=>(int)($stats['candidates']??0)],
        ]);

        \flash('success','تم اعتماد المصدر وإضافته إلى مراقبة المصادر.');
        \redirect($return);
    }
}
