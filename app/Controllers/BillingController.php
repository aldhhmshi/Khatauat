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
        $provider = strtolower(
            trim((string)(getenv('PAYMENT_GATEWAY') ?: 'moyasar'))
        );

        if (!in_array($provider, ['moyasar','paylink'], true)) {
            $provider = 'moyasar';
        }

        $gateway = $provider === 'paylink'
            ? new \Khatauat\Services\PaylinkGateway()
            : new MoyasarGateway();
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
                !empty($_POST['accept_privacy']),
                $provider
            );
            $invoice = $provider === 'paylink'
            ? $gateway->createInvoice(
                $order,
                trim((string)($_POST['client_name'] ?? '')),
                trim((string)($_POST['client_mobile'] ?? ''))
            )
            : $gateway->createInvoice($order);
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

    public function paylinkCallback(): void
    {
        Auth::requireUser();

        $transactionNo = trim(
            (string)(
                $_GET['TransactionNo']
                ?? $_GET['transactionNo']
                ?? ''
            )
        );

        $orderNumber = trim(
            (string)(
                $_GET['OrderNumber']
                ?? $_GET['orderNumber']
                ?? ''
            )
        );

        $billing = new BillingService();

        $order = $orderNumber !== ''
            ? $billing->orderForUser(
                $orderNumber,
                (int)Auth::id()
            )
            : null;

        if (!$order) {
            http_response_code(404);

            View::render(
                'errors/404',
                ['title' => 'طلب الدفع غير موجود']
            );

            return;
        }

        $verified = false;
        $error = null;

        try {
            if (
                strtolower((string)($order['provider'] ?? ''))
                !== 'paylink'
            ) {
                throw new \RuntimeException(
                    'بوابة الدفع لا تطابق الطلب.'
                );
            }

            $localTransaction = trim(
                (string)($order['provider_invoice_id'] ?? '')
            );

            if (
                $transactionNo === ''
                || $localTransaction === ''
                || !hash_equals(
                    $localTransaction,
                    $transactionNo
                )
            ) {
                throw new \RuntimeException(
                    'رقم معاملة Paylink غير مطابق.'
                );
            }

            $gateway =
                new \Khatauat\Services\PaylinkGateway();

            /*
             * Never trust callback state directly.
             * Fetch Paylink invoice server-side.
             */
            $invoice =
                $gateway->fetchInvoice($transactionNo);

            $verified =
                $billing->verifyAndFinalizeInvoice($invoice);

            $order =
                $billing->orderForUser(
                    $orderNumber,
                    (int)Auth::id()
                ) ?? $order;

        } catch (Throwable $e) {

            SecurityService::event(
                'paylink_callback_failed',
                'warning',
                [
                    'message' =>
                        mb_substr(
                            $e->getMessage(),
                            0,
                            160
                        ),
                ],
                (int)Auth::id()
            );

            $error = $e->getMessage();
        }

        View::render(
            'billing/result',
            [
                'title' => 'نتيجة عملية الدفع',
                'order' => $order,
                'verified' => $verified,
                'error' => $error,
            ]
        );
    }


    public function paylinkCancel(): void
    {
        Auth::requireUser();

        $orderNumber = trim(
            (string)(
                $_GET['OrderNumber']
                ?? $_GET['orderNumber']
                ?? ''
            )
        );

        $order = $orderNumber !== ''
            ? (new BillingService())->orderForUser(
                $orderNumber,
                (int)Auth::id()
            )
            : null;

        View::render(
            'billing/result',
            [
                'title' => 'تم إلغاء عملية الدفع',
                'order' => $order,
                'verified' => false,
                'back' => true,
            ]
        );
    }


    public function paylinkWebhook(): void
    {
        SecurityService::rateLimit(
            'paylink_webhook_ip',
            SecurityService::ipHash(),
            120,
            60
        );

        $gateway =
            new \Khatauat\Services\PaylinkGateway();

        $providedSecret =
            isset(
                $_SERVER[
                    'HTTP_X_KHATAUAT_WEBHOOK_SECRET'
                ]
            )
                ? (string)$_SERVER[
                    'HTTP_X_KHATAUAT_WEBHOOK_SECRET'
                ]
                : null;

        if (
            !$gateway->verifyWebhookSecret(
                $providedSecret
            )
        ) {
            SecurityService::event(
                'paylink_webhook_secret_rejected',
                'critical'
            );

            http_response_code(401);
            echo 'unauthorized';
            return;
        }

        $raw = (string)file_get_contents(
            'php://input'
        );

        $event = json_decode(
            $raw,
            true
        );

        if (!is_array($event)) {
            http_response_code(400);
            echo 'invalid';
            return;
        }

        $transactionNo = trim(
            (string)(
                $event['transactionNo']
                ?? ''
            )
        );

        $merchantOrderNumber = trim(
            (string)(
                $event['merchantOrderNumber']
                ?? ''
            )
        );

        if ($transactionNo === '') {
            http_response_code(400);
            echo 'invalid transaction';
            return;
        }

        $billing = new BillingService();

        /*
         * Paylink's portal Test may use a transaction that
         * does not exist in Khatauat. Acknowledge such events
         * but never grant credit.
         */
        $localOrder =
            $billing->orderByProviderInvoice(
                $transactionNo
            );

        if (!$localOrder) {

            SecurityService::event(
                'paylink_webhook_unmatched',
                'info',
                [
                    'transaction_no' =>
                        $transactionNo,
                ]
            );

            http_response_code(200);
            header(
                'Content-Type: application/json'
            );

            echo json_encode([
                'accepted' => true,
                'matched' => false,
            ]);

            return;
        }

        if (
            strtolower(
                (string)($localOrder['provider'] ?? '')
            ) !== 'paylink'
        ) {
            http_response_code(400);
            echo 'provider mismatch';
            return;
        }

        if (
            $merchantOrderNumber !== ''
            && !hash_equals(
                (string)$localOrder['order_uuid'],
                $merchantOrderNumber
            )
        ) {
            SecurityService::event(
                'paylink_webhook_order_mismatch',
                'critical',
                [
                    'transaction_no' =>
                        $transactionNo,
                ],
                (int)$localOrder['user_id']
            );

            http_response_code(400);
            echo 'order mismatch';
            return;
        }

        try {

            /*
             * Webhook itself is not trusted as payment proof.
             * Re-fetch the invoice from Paylink API.
             */
            $invoice =
                $gateway->fetchInvoice(
                    $transactionNo
                );

            $status =
                (string)($invoice['status'] ?? '');

            if ($status === 'paid') {

                $billing
                    ->verifyAndFinalizeInvoice(
                        $invoice
                    );

            } elseif ($status === 'failed') {

                $billing
                    ->markFailedByInvoice(
                        $transactionNo
                    );
            }

            SecurityService::event(
                'paylink_webhook_processed',
                'info',
                [
                    'transaction_no' =>
                        $transactionNo,
                    'status' => $status,
                ],
                (int)$localOrder['user_id']
            );

            http_response_code(200);
            header(
                'Content-Type: application/json'
            );

            echo json_encode([
                'accepted' => true,
                'matched' => true,
                'status' => $status,
            ]);

        } catch (Throwable $e) {

            SecurityService::event(
                'paylink_webhook_processing_failed',
                'warning',
                [
                    'transaction_no' =>
                        $transactionNo,
                    'message' =>
                        mb_substr(
                            $e->getMessage(),
                            0,
                            160
                        ),
                ],
                (int)$localOrder['user_id']
            );

            /*
             * Non-200 lets Paylink retry.
             */
            http_response_code(503);
            echo 'temporary failure';
        }
    }

}
