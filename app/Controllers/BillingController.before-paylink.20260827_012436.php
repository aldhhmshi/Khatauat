<?php

declare(strict_types=1);

namespace Khatauat\Controllers;

use Khatauat\Core\Auth;
use Khatauat\Core\Csrf;
use Khatauat\Core\View;
use Khatauat\Services\BillingService;
use Khatauat\Services\MoyasarGateway;
use Khatauat\Services\SecurityService;
use RuntimeException;
use Throwable;

final class BillingController
{
    public function plans(): void
    {
        $billing=new BillingService();
        View::render('billing/plans',[
            'title'=>'الباقات والأسعار',
            'products'=>$billing->products(),
            'gateway_mode'=>(new MoyasarGateway())->mode(),
            'summary'=>Auth::check()?$billing->userSummary((int)Auth::id()):null,
        ]);
    }

    public function account(): void
    {
        Auth::requireUser();
        $billing=new BillingService();
        View::render('billing/account',[
            'title'=>'الفوترة ورصيد المشاكل',
            'summary'=>$billing->userSummary((int)Auth::id()),
            'products'=>$billing->products(),
        ]);
    }

    public function checkout(string $code): void
    {
        Auth::requireUser();
        $billing=new BillingService();
        $product=$billing->product($code);
        if(!$product){http_response_code(404);View::render('errors/404',['title'=>'الباقة غير موجودة']);return;}
        View::render('billing/checkout',[
            'title'=>'إتمام الشراء',
            'product'=>$product,
            'gateway_mode'=>(new MoyasarGateway())->mode(),
        ]);
    }

    public function startCheckout(): void
    {
        Auth::requireUser(); Csrf::verify();
        SecurityService::rateLimit('billing_checkout_user',(string)Auth::id(),10,600);
        $gateway=new MoyasarGateway();
        if(!$gateway->isConfigured()){
            \flash('error','بوابة الدفع لم تُفعّل بعد. يمكنك استعراض الباقات وسيتم تفعيل الشراء بعد إعداد حساب الدفع.');
            \redirect('plans');
        }
        try{
            $billing=new BillingService();
            $order=$billing->createPendingOrder(
                (int)Auth::id(),
                trim((string)($_POST['product_code'] ?? '')),
                !empty($_POST['accept_terms']),
                !empty($_POST['accept_privacy'])
            );
            $invoice=$gateway->createInvoice($order);
            $billing->attachInvoice((string)$order['order_uuid'],(string)$invoice['id'],(string)$invoice['url']);
            SecurityService::event('billing_redirect_to_provider','info',['order_uuid'=>$order['order_uuid']],(int)Auth::id());
            header('Location: '.(string)$invoice['url'],true,303); exit;
        }catch(Throwable $e){
            SecurityService::event('billing_checkout_failed','warning',['message'=>mb_substr($e->getMessage(),0,120)],(int)Auth::id());
            \flash('error',$e->getMessage()); \redirect('plans');
        }
    }

    public function success(): void
    {
        Auth::requireUser();
        $uuid=trim((string)($_GET['order'] ?? ''));
        $billing=new BillingService();
        $order=$billing->orderForUser($uuid,(int)Auth::id());
        if(!$order){http_response_code(404);View::render('errors/404',['title'=>'الطلب غير موجود']);return;}
        $verified=false;$error=null;
        try{
            if((string)$order['status']!=='paid' && !empty($order['provider_invoice_id'])){
                $invoice=(new MoyasarGateway())->fetchInvoice((string)$order['provider_invoice_id']);
                $verified=$billing->verifyAndFinalizeInvoice($invoice);
                $order=$billing->orderForUser($uuid,(int)Auth::id()) ?? $order;
            }else{$verified=(string)$order['status']==='paid';}
        }catch(Throwable $e){$error=$e->getMessage();}
        View::render('billing/result',['title'=>'نتيجة الدفع','order'=>$order,'verified'=>$verified,'error'=>$error]);
    }

    public function back(): void
    {
        Auth::requireUser();
        $uuid=trim((string)($_GET['order'] ?? ''));
        $order=(new BillingService())->orderForUser($uuid,(int)Auth::id());
        View::render('billing/result',['title'=>'العودة من الدفع','order'=>$order,'verified'=>false,'back'=>true]);
    }

    public function startCase(): void
    {
        Auth::requireUser(); Csrf::verify();
        SecurityService::rateLimit('start_problem_case',(string)Auth::id(),8,600);
        try{
            $case=(new BillingService())->startCase((int)Auth::id());
            SecurityService::event('problem_case_started','info',['case_ref'=>$case['case_ref'] ?? ''],(int)Auth::id());
            \redirect('ask-ai?case='.rawurlencode((string)($case['case_ref'] ?? '')));
        }catch(Throwable $e){\flash('error',$e->getMessage());\redirect('billing');}
    }

    public function webhook(): void
    {
        SecurityService::rateLimit('moyasar_webhook_ip',SecurityService::ipHash(),120,60);
        $raw=(string)file_get_contents('php://input');
        $event=json_decode($raw,true);
        if(!is_array($event)){http_response_code(400);echo 'invalid';return;}
        $gateway=new MoyasarGateway();
        if(!$gateway->verifyWebhookSecret(isset($event['secret_token'])?(string)$event['secret_token']:null)){
            SecurityService::event('webhook_secret_rejected','critical'); http_response_code(401);echo 'unauthorized';return;
        }
        unset($event['secret_token']);
        try{
            $status=(new BillingService())->queueWebhook($event,hash('sha256',$raw));
            http_response_code(202);header('Content-Type: application/json');echo json_encode(['accepted'=>true,'status'=>$status]);
        }catch(Throwable $e){http_response_code(400);echo 'invalid event';}
    }
}
