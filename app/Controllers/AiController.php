<?php

declare(strict_types=1);

namespace Khatauat\Controllers;

use Khatauat\Core\Auth;
use Khatauat\Core\Csrf;
use Khatauat\Core\Database;
use Khatauat\Core\Settings;
use Khatauat\Core\View;
use Khatauat\Services\BillingService;
use Khatauat\Services\PublicAiService;
use Khatauat\Services\SecurityService;
use Khatauat\Services\SocialIntelligenceService;
use Throwable;

final class AiController
{
    public function index(): void
    {
        Auth::requireUser();
        $limit = $this->limit();
        $prefill='';
        $servicePulse=null;
        $serviceSlug=trim((string)($_GET['service'] ?? ''));
        if($serviceSlug!==''){
            $svc=Database::fetch("SELECT id,name,slug,summary,official_entity,official_platform,official_url FROM services WHERE slug=? AND status='published' LIMIT 1",[$serviceSlug]);
            if($svc){
                $prefill='أواجه مشكلة في خدمة «'.(string)$svc['name'].'»: ';
                try { $servicePulse=(new SocialIntelligenceService())->pulseForService($svc); } catch (Throwable) { $servicePulse=null; }
            }
        }
        $paidCase=$this->paidCaseFromRequest();
        [$used,$remaining,$displayLimit]=$this->usageState($limit,$paidCase);
        View::render('ask_ai',[
            'title'=>'اسأل خطوات AI','answer'=>null,'question'=>$prefill,'limit'=>$displayLimit,'used'=>$used,'remaining'=>$remaining,
            'configured'=>(new PublicAiService())->isConfigured(),'result_status'=>null,'diagnostic_questions'=>[],'contact'=>null,'evidence'=>[],
            'service_pulse'=>$servicePulse,'incident'=>null,'paid_case'=>$paidCase,'case_balance'=>(new BillingService())->balanceCases((int)Auth::id()),
        ]);
    }

    public function ask(): void
    {
        Auth::requireUser(); Csrf::verify();
        SecurityService::rateLimit('ask_ai_user',(string)Auth::id(),30,300);
        $question=trim((string)($_POST['question'] ?? ''));
        $limit=$this->limit();
        $paidCase=$this->paidCaseFromPost();
        [$used,$remaining,$displayLimit]=$this->usageState($limit,$paidCase);
        if(!Auth::isOwner() && !$paidCase && $remaining<=0){
            View::render('ask_ai',[
                'title'=>'اسأل خطوات AI','answer'=>null,'question'=>$question,'limit'=>$displayLimit,'used'=>$used,'remaining'=>0,'configured'=>(new PublicAiService())->isConfigured(),
                'quota_error'=>'استخدمت استفسارات AI المجانية لهذا اليوم. يمكنك بدء مشكلة مدفوعة من رصيدك أو شراء باقة، بينما البحث ودليل الإجراءات يبقيان متاحين.',
                'result_status'=>null,'diagnostic_questions'=>[],'contact'=>null,'evidence'=>[],'service_pulse'=>null,'incident'=>null,
                'paid_case'=>null,'case_balance'=>(new BillingService())->balanceCases((int)Auth::id()),
            ]);return;
        }
        if($paidCase && $remaining<=0){
            View::render('ask_ai',[
                'title'=>'اسأل خطوات AI','answer'=>null,'question'=>$question,'limit'=>$displayLimit,'used'=>$used,'remaining'=>0,'configured'=>(new PublicAiService())->isConfigured(),
                'quota_error'=>'وصلت جلسة هذه المشكلة إلى حد الرسائل أو انتهت صلاحيتها. يمكنك بدء مشكلة جديدة من رصيدك.',
                'result_status'=>null,'diagnostic_questions'=>[],'contact'=>null,'evidence'=>[],'service_pulse'=>null,'incident'=>null,
                'paid_case'=>$paidCase,'case_balance'=>(new BillingService())->balanceCases((int)Auth::id()),
            ]);return;
        }

        $service=new PublicAiService();
        $result=$service->answer($question);
        if(($result['ok']??false) && ($result['used_ai']??false) && !Auth::isOwner()){
            if($paidCase){
                try{(new BillingService())->consumeCaseMessage((int)$paidCase['id'],(int)Auth::id());}catch(Throwable $e){$result=['ok'=>false,'error'=>$e->getMessage(),'used_ai'=>false];}
            }else{$this->incrementUsage();}
        }
        if($paidCase) $paidCase=(new BillingService())->caseForUser((string)$paidCase['case_ref'],(int)Auth::id());
        [$used,$remaining,$displayLimit]=$this->usageState($limit,$paidCase);

        $matchedServices=(array)($result['services'] ?? []);$servicePulse=null;
        if($matchedServices!==[]){try{$servicePulse=(new SocialIntelligenceService())->pulseForService((array)$matchedServices[0]);}catch(Throwable){$servicePulse=null;}}

        View::render('ask_ai',[
            'title'=>'اسأل خطوات AI','answer'=>($result['ok']??false)?(string)($result['text']??''):null,'error'=>($result['ok']??false)?null:(string)($result['error']??'تعذر إكمال الطلب.'),
            'question'=>$question,'limit'=>$displayLimit,'used'=>$used,'remaining'=>$remaining,'configured'=>$service->isConfigured(),'matched_services'=>$matchedServices,
            'used_ai'=>(bool)($result['used_ai']??false),'result_status'=>(string)($result['status']??''),'diagnostic_questions'=>(array)($result['diagnostic_questions']??[]),
            'contact'=>$result['contact']??null,'evidence'=>(array)($result['evidence']??[]),'missing_information'=>(string)($result['missing_information']??''),
            'incident'=>$result['incident']??null,'service_pulse'=>$servicePulse,'paid_case'=>$paidCase,'case_balance'=>(new BillingService())->balanceCases((int)Auth::id()),
        ]);
    }

    private function paidCaseFromRequest(): ?array
    {
        if(Auth::isOwner()) return null;
        $ref=trim((string)($_GET['case'] ?? ''));
        return $ref!==''?(new BillingService())->caseForUser($ref,(int)Auth::id()):null;
    }
    private function paidCaseFromPost(): ?array
    {
        if(Auth::isOwner()) return null;
        $ref=trim((string)($_POST['case_ref'] ?? ''));
        return $ref!==''?(new BillingService())->caseForUser($ref,(int)Auth::id()):null;
    }
    private function usageState(int $freeLimit, ?array $paidCase): array
    {
        if(Auth::isOwner()) return [0,999,999];
        if($paidCase && (string)($paidCase['status']??'')==='open'){
            $max=(int)$paidCase['message_limit'];$used=(int)$paidCase['message_count'];return[$used,max(0,$max-$used),$max];
        }
        $used=$this->usedToday();return[$used,max(0,$freeLimit-$used),$freeLimit];
    }
    private function limit(): int{return max(1,min(20,(int)Settings::get('free_ai_daily_limit','3')));}
    private function usedToday(): int{if(Auth::isOwner())return 0;$row=Database::fetch('SELECT request_count FROM user_ai_daily_usage WHERE user_id=? AND usage_date=?',[Auth::id(),date('Y-m-d')]);return(int)($row['request_count']??0);}
    private function incrementUsage(): void{Database::execute('INSERT INTO user_ai_daily_usage(user_id,usage_date,request_count,updated_at) VALUES(?,?,1,CURRENT_TIMESTAMP) ON CONFLICT(user_id,usage_date) DO UPDATE SET request_count=request_count+1,updated_at=CURRENT_TIMESTAMP',[Auth::id(),date('Y-m-d')]);}
}
