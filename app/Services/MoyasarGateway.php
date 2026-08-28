<?php

declare(strict_types=1);

namespace Khatauat\Services;

use RuntimeException;

final class MoyasarGateway
{
    private const API_BASE = 'https://api.moyasar.com/v1';

    public function isConfigured(): bool
    {
        $key = trim((string)(getenv('MOYASAR_SECRET_KEY') ?: ''));
        return $key !== '' && preg_match('/^sk_(test|live)_/', $key) === 1;
    }

    public function mode(): string
    {
        $key = trim((string)(getenv('MOYASAR_SECRET_KEY') ?: ''));
        return str_starts_with($key, 'sk_live_') ? 'live' : (str_starts_with($key, 'sk_test_') ? 'test' : 'disabled');
    }

    public function webhookConfigured(): bool
    {
        return trim((string)(getenv('MOYASAR_WEBHOOK_SECRET') ?: '')) !== '';
    }

    public function createInvoice(array $order): array
    {
        if (!$this->isConfigured()) throw new RuntimeException('بوابة الدفع غير مهيأة بعد. أضف MOYASAR_SECRET_KEY في ملف البيئة.');
        $base = rtrim((string)\config('base_url',''), '/');
        if ($base === '' || !str_starts_with($base, 'https://')) throw new RuntimeException('يجب ضبط APP_URL على رابط HTTPS قبل تفعيل الدفع.');

        $payload = [
            'amount' => (int)$order['amount_minor'],
            'currency' => (string)$order['currency'],
            'description' => (string)$order['product_name_snapshot'],
            'success_url' => $base . '/billing/success?order=' . rawurlencode((string)$order['order_uuid']),
            'back_url' => $base . '/billing/back?order=' . rawurlencode((string)$order['order_uuid']),
            'expired_at' => gmdate('c', time() + 1800),
            'metadata' => [
                'order_uuid' => (string)$order['order_uuid'],
                'product_code' => (string)$order['product_code_snapshot'],
                'user_id' => (string)$order['user_id'],
            ],
        ];
        $result = $this->request('POST', '/invoices', $payload);
        if (empty($result['id']) || empty($result['url'])) throw new RuntimeException('لم تُرجع بوابة الدفع رابط فاتورة صالحًا.');
        return $result;
    }

    public function fetchInvoice(string $invoiceId): array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{8,80}$/', $invoiceId)) throw new RuntimeException('معرف فاتورة غير صالح.');
        return $this->request('GET', '/invoices/' . rawurlencode($invoiceId));
    }

    public function verifyWebhookSecret(?string $provided): bool
    {
        $expected = trim((string)(getenv('MOYASAR_WEBHOOK_SECRET') ?: ''));
        return $expected !== '' && is_string($provided) && hash_equals($expected, $provided);
    }

    private function request(string $method, string $path, ?array $json = null): array
    {
        if (!extension_loaded('curl')) throw new RuntimeException('امتداد cURL غير مفعّل على الخادم.');
        $secret = trim((string)(getenv('MOYASAR_SECRET_KEY') ?: ''));
        $ch = curl_init(self::API_BASE . $path);
        if ($ch === false) throw new RuntimeException('تعذر تهيئة اتصال بوابة الدفع.');
        $headers = ['Accept: application/json','Content-Type: application/json'];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERPWD => $secret . ':',
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);
        if ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $error !== '') throw new RuntimeException('تعذر الاتصال ببوابة الدفع.');
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) $decoded = [];
        if ($status < 200 || $status >= 300) {
            $message = (string)($decoded['message'] ?? $decoded['error'] ?? 'رفضت بوابة الدفع الطلب.');
            throw new RuntimeException($message);
        }
        return $decoded;
    }
}
