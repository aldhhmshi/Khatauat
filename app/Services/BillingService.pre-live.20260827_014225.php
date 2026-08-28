<?php

declare(strict_types=1);

namespace Khatauat\Services;

use Khatauat\Core\Database;
use Khatauat\Core\Settings;
use RuntimeException;
use Throwable;

final class BillingService
{
    public const TERMS_VERSION = '2026-08-26.1';
    public const PRIVACY_VERSION = '2026-08-26.1';

    public function products(): array
    {
        return Database::fetchAll("SELECT * FROM billing_products WHERE status='active' ORDER BY sort_order,id");
    }

    public function product(string $code): ?array
    {
        return Database::fetch("SELECT * FROM billing_products WHERE code=? AND status='active' LIMIT 1", [$code]);
    }

    public function orderForUser(string $uuid, int $userId): ?array
    {
        return Database::fetch('SELECT * FROM billing_orders WHERE order_uuid=? AND user_id=? LIMIT 1', [$uuid,$userId]);
    }

    public function createPendingOrder(int $userId, string $productCode, bool $termsAccepted, bool $privacyAccepted, string $provider = 'moyasar'): array
    {
                $provider = strtolower(trim($provider));
        if (!in_array($provider, ['moyasar','paylink'], true)) {
            throw new RuntimeException('بوابة الدفع غير مدعومة.');
        }

if (!$termsAccepted || !$privacyAccepted) throw new RuntimeException('يجب الموافقة على الشروط وسياسة الخصوصية قبل الدفع.');
        $product = $this->product($productCode);
        if (!$product) throw new RuntimeException('الباقة غير متاحة حاليًا.');

        $uuid = $this->uuidV4();
        $idempotency = bin2hex(random_bytes(24));
        $now = date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', time()+1800);
        $termsVersion = (string)Settings::get('terms_version', self::TERMS_VERSION);
        $privacyVersion = (string)Settings::get('privacy_version', self::PRIVACY_VERSION);

        Database::immediate(function() use ($userId,$product,$uuid,$idempotency,$now,$expires,$termsVersion,$privacyVersion): void {
            Database::execute(
                'INSERT INTO billing_orders(order_uuid,user_id,product_id,product_code_snapshot,product_name_snapshot,product_type_snapshot,amount_minor,currency,included_cases_snapshot,case_message_limit_snapshot,validity_days_snapshot,status,provider,idempotency_key,terms_version,privacy_version,terms_accepted_at,privacy_accepted_at,expires_at,created_at,updated_at) '
                . 'VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$uuid,$userId,(int)$product['id'],(string)$product['code'],(string)$product['name'],(string)$product['product_type'],(int)$product['price_minor'],(string)$product['currency'],(int)$product['included_cases'],(int)$product['case_message_limit'],(int)$product['validity_days'],'pending',$provider,$idempotency,$termsVersion,$privacyVersion,$now,$now,$expires,$now,$now]
            );
            Database::execute(
                'INSERT INTO policy_acceptances(user_id,order_uuid,terms_version,privacy_version,context,ip_hash,user_agent_hash,accepted_at) VALUES(?,?,?,?,?,?,?,?)',
                [$userId,$uuid,$termsVersion,$privacyVersion,'checkout',SecurityService::ipHash(),SecurityService::userAgentHash(),$now]
            );
        });
        SecurityService::event('billing_order_created','info',['order_uuid'=>$uuid,'product_code'=>$productCode],$userId);
        return Database::fetch('SELECT * FROM billing_orders WHERE order_uuid=?', [$uuid]) ?? throw new RuntimeException('تعذر إنشاء الطلب.');
    }

    
    public function orderByProviderInvoice(string $invoiceId): ?array
    {
        $invoiceId = trim($invoiceId);

        if ($invoiceId === '') {
            return null;
        }

        return Database::fetch(
            'SELECT * FROM billing_orders WHERE provider_invoice_id=? LIMIT 1',
            [$invoiceId]
        ) ?: null;
    }

public function attachInvoice(string $orderUuid, string $invoiceId, string $invoiceUrl): void
    {
        Database::execute('UPDATE billing_orders SET provider_invoice_id=?,provider_invoice_url=?,updated_at=CURRENT_TIMESTAMP WHERE order_uuid=? AND status=?', [$invoiceId,$invoiceUrl,$orderUuid,'pending']);
    }

    public function verifyAndFinalizeInvoice(array $invoice): bool
    {
        $invoiceId = (string)($invoice['id'] ?? '');
        if ($invoiceId === '') return false;
        $order = Database::fetch('SELECT * FROM billing_orders WHERE provider_invoice_id=? LIMIT 1', [$invoiceId]);
        if (!$order) return false;

        $invoiceProvider = strtolower(
            trim((string)($invoice['provider'] ?? ''))
        );

        if (
            $invoiceProvider !== ''
            && strtolower((string)($order['provider'] ?? '')) !== $invoiceProvider
        ) {
            SecurityService::event(
                'payment_provider_mismatch',
                'critical',
                [
                    'order_uuid' => $order['order_uuid'],
                    'invoice_id' => $invoiceId,
                ],
                (int)$order['user_id']
            );

            throw new RuntimeException('بوابة الدفع لا تطابق الطلب.');
        }

        $merchantOrderNumber = trim(
            (string)($invoice['merchant_order_number'] ?? '')
        );

        if (
            $merchantOrderNumber !== ''
            && !hash_equals(
                (string)$order['order_uuid'],
                $merchantOrderNumber
            )
        ) {
            SecurityService::event(
                'payment_order_number_mismatch',
                'critical',
                [
                    'order_uuid' => $order['order_uuid'],
                    'invoice_id' => $invoiceId,
                ],
                (int)$order['user_id']
            );

            throw new RuntimeException('رقم طلب الدفع لا يطابق الطلب المحلي.');
        }
        if ((string)($invoice['status'] ?? '') !== 'paid') return false;
        $amount = (int)($invoice['amount'] ?? -1);
        $currency = strtoupper((string)($invoice['currency'] ?? ''));
        if ($amount !== (int)$order['amount_minor'] || $currency !== strtoupper((string)$order['currency'])) {
            SecurityService::event('payment_amount_mismatch','critical',['order_uuid'=>$order['order_uuid'],'invoice_id'=>$invoiceId],(int)$order['user_id']);
            throw new RuntimeException('تعذر التحقق من مبلغ عملية الدفع.');
        }
        $paymentId = '';
        if (!empty($invoice['payments']) && is_array($invoice['payments'])) {
            foreach ($invoice['payments'] as $p) {
                if (is_array($p) && (string)($p['status'] ?? '') === 'paid') { $paymentId=(string)($p['id'] ?? ''); break; }
            }
        }
        $this->finalizePaidOrder((string)$order['order_uuid'],$paymentId,$invoiceId);
        return true;
    }

    public function finalizePaidOrder(string $orderUuid, string $paymentId = '', string $invoiceId = ''): void
    {
        Database::immediate(function() use ($orderUuid,$paymentId,$invoiceId): void {
            $order = Database::fetch('SELECT * FROM billing_orders WHERE order_uuid=? LIMIT 1', [$orderUuid]);
            if (!$order) throw new RuntimeException('الطلب غير موجود.');
            if ((string)$order['status'] === 'paid') return;
            if (!in_array((string)$order['status'], ['pending','failed'], true)) throw new RuntimeException('حالة الطلب لا تسمح بالتفعيل.');

            $now = date('Y-m-d H:i:s');
            Database::execute('UPDATE billing_orders SET status=?,provider_payment_id=COALESCE(NULLIF(?,\'\'),provider_payment_id),provider_invoice_id=COALESCE(NULLIF(?,\'\'),provider_invoice_id),paid_at=?,updated_at=? WHERE id=?', ['paid',$paymentId,$invoiceId,$now,$now,(int)$order['id']]);

            $type = (string)$order['product_type_snapshot'];
            $validityDays = max(1,(int)$order['validity_days_snapshot']);
            $expires = date('Y-m-d H:i:s', strtotime($now . ' +' . $validityDays . ' days'));
            $subscriptionId = null;
            if (str_starts_with($type, 'subscription_')) {
                Database::execute(
                    'INSERT INTO billing_subscriptions(user_id,product_id,order_id,status,starts_at,ends_at,auto_renew,cases_included,case_message_limit,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)',
                    [(int)$order['user_id'],(int)$order['product_id'],(int)$order['id'],'active',$now,$expires,0,(int)$order['included_cases_snapshot'],(int)$order['case_message_limit_snapshot']]
                );
                $subscriptionId = Database::lastInsertId();
            }
            Database::execute(
                'INSERT INTO case_credit_grants(user_id,order_id,subscription_id,source_type,total_cases,remaining_cases,case_message_limit,valid_from,expires_at,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)',
                [(int)$order['user_id'],(int)$order['id'],$subscriptionId,$type,(int)$order['included_cases_snapshot'],(int)$order['included_cases_snapshot'],(int)$order['case_message_limit_snapshot'],$now,$expires,'active']
            );
            $grantId = Database::lastInsertId();
            $balance = $this->balanceCases((int)$order['user_id']);
            Database::execute(
                'INSERT INTO billing_ledger(user_id,order_id,grant_id,entry_type,delta_cases,balance_after,reference,created_at) VALUES(?,?,?,?,?,?,?,CURRENT_TIMESTAMP)',
                [(int)$order['user_id'],(int)$order['id'],$grantId,'credit',(int)$order['included_cases_snapshot'],$balance,'payment:' . $orderUuid]
            );
        });
        $order = Database::fetch('SELECT user_id FROM billing_orders WHERE order_uuid=?',[$orderUuid]);
        SecurityService::event('billing_order_paid','info',['order_uuid'=>$orderUuid],isset($order['user_id'])?(int)$order['user_id']:null);
    }

    public function markFailedByInvoice(string $invoiceId): void
    {
        Database::execute("UPDATE billing_orders SET status='failed',updated_at=CURRENT_TIMESTAMP WHERE provider_invoice_id=? AND status='pending'",[$invoiceId]);
    }

    public function processRefund(string $invoiceId, int $refundedMinor): void
    {
        Database::immediate(function() use ($invoiceId,$refundedMinor): void {
            $order=Database::fetch('SELECT * FROM billing_orders WHERE provider_invoice_id=? LIMIT 1',[$invoiceId]);
            if(!$order) return;
            $refundedMinor=max(0,min((int)$order['amount_minor'],$refundedMinor));
            $status=$refundedMinor >= (int)$order['amount_minor'] ? 'refunded' : 'partially_refunded';
            Database::execute('UPDATE billing_orders SET refunded_minor=?,status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$refundedMinor,$status,(int)$order['id']]);
            if($status==='refunded'){
                $grants=Database::fetchAll("SELECT * FROM case_credit_grants WHERE order_id=? AND status IN ('active','exhausted')",[(int)$order['id']]);
                foreach($grants as $g){
                    $remaining=(int)$g['remaining_cases'];
                    if($remaining>0){
                        Database::execute("UPDATE case_credit_grants SET remaining_cases=0,status='revoked',updated_at=CURRENT_TIMESTAMP WHERE id=?",[(int)$g['id']]);
                        $balance=$this->balanceCases((int)$order['user_id']);
                        Database::execute('INSERT INTO billing_ledger(user_id,order_id,grant_id,entry_type,delta_cases,balance_after,reference,created_at) VALUES(?,?,?,?,?,?,?,CURRENT_TIMESTAMP)',[(int)$order['user_id'],(int)$order['id'],(int)$g['id'],'refund_reversal',-$remaining,$balance,'refund:' . $invoiceId]);
                    }
                }
                Database::execute("UPDATE billing_subscriptions SET status='canceled',updated_at=CURRENT_TIMESTAMP WHERE order_id=? AND status='active'",[(int)$order['id']]);
            }
        });
    }

    public function balanceCases(int $userId): int
    {
        $row = Database::fetch("SELECT COALESCE(SUM(remaining_cases),0) AS c FROM case_credit_grants WHERE user_id=? AND status='active' AND remaining_cases>0 AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)",[$userId]);
        return max(0,(int)($row['c'] ?? 0));
    }

    public function startCase(int $userId): array
    {
        return Database::immediate(function() use ($userId): array {
            $grant=Database::fetch("SELECT * FROM case_credit_grants WHERE user_id=? AND status='active' AND remaining_cases>0 AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP) ORDER BY COALESCE(expires_at,'9999-12-31'),id LIMIT 1",[$userId]);
            if(!$grant) throw new RuntimeException('لا يوجد لديك رصيد مشكلات متاح.');
            Database::execute('UPDATE case_credit_grants SET remaining_cases=remaining_cases-1,status=CASE WHEN remaining_cases-1<=0 THEN \'exhausted\' ELSE status END,updated_at=CURRENT_TIMESTAMP WHERE id=? AND remaining_cases>0',[(int)$grant['id']]);
            $ref=$this->uuidV4();
            $messageLimit=max(1,(int)$grant['case_message_limit']);
            $expires=date('Y-m-d H:i:s', min(strtotime((string)$grant['expires_at']), time()+7*86400));
            Database::execute('INSERT INTO problem_cases(case_ref,user_id,grant_id,status,message_limit,message_count,opened_at,expires_at,created_at,updated_at) VALUES(?,?,?,?,?,?,CURRENT_TIMESTAMP,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)',[$ref,$userId,(int)$grant['id'],'open',$messageLimit,0,$expires]);
            $caseId=Database::lastInsertId();
            $balance=$this->balanceCases($userId);
            Database::execute('INSERT INTO billing_ledger(user_id,order_id,grant_id,case_id,entry_type,delta_cases,balance_after,reference,created_at) VALUES(?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)',[$userId,(int)$grant['order_id'],(int)$grant['id'],$caseId,'consume_case',-1,$balance,'case:' . $ref]);
            return Database::fetch('SELECT * FROM problem_cases WHERE id=?',[$caseId]) ?? [];
        });
    }

    public function caseForUser(string $caseRef, int $userId): ?array
    {
        $case=Database::fetch("SELECT * FROM problem_cases WHERE case_ref=? AND user_id=? LIMIT 1",[$caseRef,$userId]);
        if(!$case) return null;
        if((string)$case['status']==='open' && strtotime((string)$case['expires_at'])<=time()){
            Database::execute("UPDATE problem_cases SET status='expired',updated_at=CURRENT_TIMESTAMP WHERE id=?",[(int)$case['id']]);
            $case['status']='expired';
        }
        return $case;
    }

    public function consumeCaseMessage(int $caseId, int $userId): void
    {
        Database::immediate(function() use ($caseId,$userId): void {
            $case=Database::fetch('SELECT * FROM problem_cases WHERE id=? AND user_id=? LIMIT 1',[$caseId,$userId]);
            if(!$case || (string)$case['status']!=='open') throw new RuntimeException('جلسة المشكلة غير متاحة.');
            if(strtotime((string)$case['expires_at'])<=time()) throw new RuntimeException('انتهت صلاحية جلسة المشكلة.');
            if((int)$case['message_count'] >= (int)$case['message_limit']) throw new RuntimeException('وصلت جلسة المشكلة إلى الحد المخصص للرسائل.');
            Database::execute("UPDATE problem_cases SET message_count=message_count+1,status=CASE WHEN message_count+1>=message_limit THEN 'resolved' ELSE status END,updated_at=CURRENT_TIMESTAMP WHERE id=?",[$caseId]);
        });
    }

    public function userSummary(int $userId): array
    {
        return [
            'case_balance'=>$this->balanceCases($userId),
            'subscriptions'=>Database::fetchAll("SELECT s.*,p.name product_name FROM billing_subscriptions s LEFT JOIN billing_products p ON p.id=s.product_id WHERE s.user_id=? ORDER BY s.id DESC LIMIT 10",[$userId]),
            'orders'=>Database::fetchAll('SELECT * FROM billing_orders WHERE user_id=? ORDER BY id DESC LIMIT 30',[$userId]),
            'cases'=>Database::fetchAll('SELECT * FROM problem_cases WHERE user_id=? ORDER BY id DESC LIMIT 20',[$userId]),
        ];
    }

    public function queueWebhook(array $event, string $payloadHash): string
    {
        $eventId=(string)($event['id'] ?? '');
        $type=(string)($event['type'] ?? '');
        $data=is_array($event['data'] ?? null)?$event['data']:[];
        if($eventId==='' || $type==='') throw new RuntimeException('Webhook غير صالح.');
        $existing=Database::fetch('SELECT payload_hash FROM billing_webhook_events WHERE provider=? AND event_id=?',['moyasar',$eventId]);
        if($existing){
            if(!hash_equals((string)$existing['payload_hash'],$payloadHash)) SecurityService::event('webhook_duplicate_hash_mismatch','critical',['event_id'=>$eventId]);
            return 'duplicate';
        }
        $invoiceId=(string)($data['invoice_id'] ?? '');
        $metadata=is_array($data['metadata'] ?? null)?$data['metadata']:[];
        $orderUuid=(string)($metadata['order_uuid'] ?? '');
        if($orderUuid==='' && $invoiceId!==''){
            $o=Database::fetch('SELECT order_uuid FROM billing_orders WHERE provider_invoice_id=?',[$invoiceId]);
            $orderUuid=(string)($o['order_uuid'] ?? '');
        }
        Database::execute('INSERT INTO billing_webhook_events(provider,event_id,event_type,payload_hash,order_uuid,invoice_id,payment_id,amount_minor,currency,refunded_minor,status,received_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)',[
            'moyasar',$eventId,$type,$payloadHash,$orderUuid,$invoiceId,(string)($data['id'] ?? ''),(int)($data['amount'] ?? 0),strtoupper((string)($data['currency'] ?? 'SAR')),(int)($data['refunded'] ?? 0),'queued'
        ]);
        return 'queued';
    }

    public function processQueuedWebhooks(int $limit=50): array
    {
        $gateway=new MoyasarGateway();
        $rows=Database::fetchAll("SELECT * FROM billing_webhook_events WHERE status='queued' ORDER BY id LIMIT " . max(1,min(200,$limit)));
        $done=0;$failed=0;
        foreach($rows as $row){
            try{
                $type=(string)$row['event_type'];
                $invoiceId=(string)$row['invoice_id'];
                if($invoiceId===''){ Database::execute("UPDATE billing_webhook_events SET status='ignored',processed_at=CURRENT_TIMESTAMP WHERE id=?",[(int)$row['id']]); continue; }
                if($type==='payment_paid'){
                    $invoice=$gateway->fetchInvoice($invoiceId);
                    $this->verifyAndFinalizeInvoice($invoice);
                }elseif($type==='payment_refunded'){
                    $invoice=$gateway->fetchInvoice($invoiceId);
                    $refunded=0; foreach((array)($invoice['payments'] ?? []) as $p) if(is_array($p)) $refunded=max($refunded,(int)($p['refunded'] ?? 0));
                    $this->processRefund($invoiceId,$refunded);
                }elseif(in_array($type,['payment_failed','payment_voided'],true)){
                    $this->markFailedByInvoice($invoiceId);
                }
                Database::execute("UPDATE billing_webhook_events SET status='processed',processed_at=CURRENT_TIMESTAMP WHERE id=?",[(int)$row['id']]);$done++;
            }catch(Throwable $e){
                Database::execute("UPDATE billing_webhook_events SET status='failed',error_message=?,processed_at=CURRENT_TIMESTAMP WHERE id=?",[mb_substr($e->getMessage(),0,300),(int)$row['id']]);$failed++;
            }
        }
        return ['processed'=>$done,'failed'=>$failed,'seen'=>count($rows)];
    }

    public function maintenance(): array
    {
        Database::execute("UPDATE billing_orders SET status='expired',updated_at=CURRENT_TIMESTAMP WHERE status='pending' AND expires_at<CURRENT_TIMESTAMP");
        Database::execute("UPDATE problem_cases SET status='expired',updated_at=CURRENT_TIMESTAMP WHERE status='open' AND expires_at<CURRENT_TIMESTAMP");
        Database::execute("UPDATE case_credit_grants SET status='expired',remaining_cases=0,updated_at=CURRENT_TIMESTAMP WHERE status='active' AND expires_at<CURRENT_TIMESTAMP");
        Database::execute("UPDATE billing_subscriptions SET status='expired',updated_at=CURRENT_TIMESTAMP WHERE status='active' AND ends_at<CURRENT_TIMESTAMP");
        Database::execute('DELETE FROM rate_limit_buckets WHERE window_start < ?', [time()-172800]);
        Database::execute("DELETE FROM security_events WHERE created_at < datetime('now','-90 days') AND severity IN ('info','warning')");
        return $this->processQueuedWebhooks();
    }

    private function uuidV4(): string
    {
        $d=random_bytes(16);$d[6]=chr((ord($d[6])&0x0f)|0x40);$d[8]=chr((ord($d[8])&0x3f)|0x80);$h=bin2hex($d);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
}
