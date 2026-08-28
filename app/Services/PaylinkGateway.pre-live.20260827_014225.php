<?php
declare(strict_types=1);

namespace Khatauat\Services;

use RuntimeException;

final class PaylinkGateway
{
    private const DEFAULT_BASE_URL = 'https://restapi.paylink.sa';

    public function isConfigured(): bool
    {
        return trim((string)(getenv('PAYLINK_API_ID') ?: '')) !== ''
            && trim((string)(getenv('PAYLINK_SECRET_KEY') ?: '')) !== ''
            && str_starts_with($this->baseUrl(), 'https://');
    }

    public function createInvoice(
        array $order,
        string $clientName,
        string $clientMobile
    ): array {
        if (!$this->isConfigured()) {
            throw new RuntimeException('إعداد Paylink غير مكتمل.');
        }

        $clientName = trim($clientName);

        if (mb_strlen($clientName) < 2 || mb_strlen($clientName) > 120) {
            throw new RuntimeException('اسم العميل غير صالح.');
        }

        $clientMobile = $this->normalizeMobile($clientMobile);

        $orderUuid = trim((string)($order['order_uuid'] ?? ''));

        if ($orderUuid === '') {
            throw new RuntimeException('رقم طلب خطوات غير صالح.');
        }

        $currency = strtoupper(
            trim((string)($order['currency'] ?? 'SAR'))
        );

        if ($currency !== 'SAR') {
            throw new RuntimeException(
                'Paylink مفعّل حاليًا لمدفوعات SAR فقط.'
            );
        }

        $amountMinor = (int)($order['amount_minor'] ?? 0);

        if ($amountMinor <= 0) {
            throw new RuntimeException('قيمة الطلب غير صالحة.');
        }

        /*
         * Khatauat:
         * 100 halalas = 1 SAR
         */
        $amountSar = round($amountMinor / 100, 2);

        $productName = trim(
            (string)($order['product_name_snapshot'] ?? '')
        );

        if ($productName === '') {
            $productName = 'خدمة رقمية من خطوات';
        }

        $payload = [
            'amount' => $amountSar,

            'callBackUrl' =>
                $this->appBaseUrl() . '/billing/paylink/callback',

            'cancelUrl' =>
                $this->appBaseUrl() . '/billing/paylink/cancel',

            'clientMobile' => $clientMobile,
            'clientName' => $clientName,

            'currency' => 'SAR',

            /*
             * order_uuid is already unique inside Khatauat.
             */
            'orderNumber' => $orderUuid,

            'note' => 'Khatauat order ' . $orderUuid,

            'products' => [
                [
                    'title' => $productName,
                    'price' => $amountSar,
                    'qty' => 1,
                    'description' => 'خدمة رقمية من منصة خطوات',
                    'isDigital' => true,
                ],
            ],
        ];

        $raw = $this->apiRequest(
            'POST',
            '/api/addInvoice',
            $payload
        );

        $invoice = $this->normalizeInvoice($raw);

        if ($invoice['id'] === '') {
            throw new RuntimeException(
                'Paylink لم يُرجع transactionNo صالحًا.'
            );
        }

        if ($invoice['url'] === '') {
            throw new RuntimeException(
                'Paylink لم يُرجع رابط الدفع.'
            );
        }

        /*
         * Verify the provider returned the same invoice value.
         */
        if ($invoice['amount'] !== $amountMinor) {
            throw new RuntimeException(
                'قيمة فاتورة Paylink لا تطابق قيمة طلب خطوات.'
            );
        }

        /*
         * Verify merchant order reference when present.
         */
        if (
            $invoice['merchant_order_number'] !== ''
            && $invoice['merchant_order_number'] !== $orderUuid
        ) {
            throw new RuntimeException(
                'رقم طلب Paylink لا يطابق طلب خطوات.'
            );
        }

        return $invoice;
    }

    public function fetchInvoice(string $transactionNo): array
    {
        $transactionNo = trim($transactionNo);

        if (
            $transactionNo === ''
            || !preg_match('/^[A-Za-z0-9_-]{3,150}$/', $transactionNo)
        ) {
            throw new RuntimeException(
                'رقم معاملة Paylink غير صالح.'
            );
        }

        $raw = $this->apiRequest(
            'GET',
            '/api/getInvoice/' . rawurlencode($transactionNo)
        );

        $invoice = $this->normalizeInvoice($raw);

        if ($invoice['id'] === '') {
            throw new RuntimeException(
                'تعذر التحقق من فاتورة Paylink.'
            );
        }

        return $invoice;
    }

    public function verifyWebhookSecret(?string $provided): bool
    {
        $expected = trim(
            (string)(getenv('PAYLINK_WEBHOOK_SECRET') ?: '')
        );

        return $expected !== ''
            && is_string($provided)
            && $provided !== ''
            && hash_equals($expected, $provided);
    }

    private function normalizeInvoice(array $raw): array
    {
        $transactionNo = trim(
            (string)($raw['transactionNo'] ?? '')
        );

        $rawStatus = strtolower(
            trim((string)($raw['orderStatus'] ?? ''))
        );

        $status = match ($rawStatus) {
            'paid',
            'completed',
            'complete',
            'successful',
            'success' => 'paid',

            'failed',
            'cancelled',
            'canceled',
            'expired',
            'rejected' => 'failed',

            'refunded' => 'refunded',

            default => 'pending',
        };

        /*
         * Paylink amount is SAR decimal.
         * BillingService expects minor units (halalas).
         */
        $amountMinor = isset($raw['amount'])
            ? (int)round(((float)$raw['amount']) * 100)
            : -1;

        $gatewayOrder = [];

        if (
            isset($raw['gatewayOrderRequest'])
            && is_array($raw['gatewayOrderRequest'])
        ) {
            $gatewayOrder = $raw['gatewayOrderRequest'];
        }

        $currency = strtoupper(
            trim(
                (string)(
                    $gatewayOrder['currency']
                    ?? $raw['currency']
                    ?? 'SAR'
                )
            )
        );

        if ($currency === '') {
            $currency = 'SAR';
        }

        $merchantOrderNumber = trim(
            (string)(
                $gatewayOrder['orderNumber']
                ?? $raw['merchantOrderNumber']
                ?? $raw['orderNumber']
                ?? ''
            )
        );

        $url = trim(
            (string)(
                $raw['url']
                ?? $raw['mobileUrl']
                ?? ''
            )
        );

        /*
         * BillingService expects a payments array.
         * We safely map Paylink transactionNo as provider payment ID
         * only when Paylink confirms Paid.
         */
        $payments = [];

        if ($status === 'paid' && $transactionNo !== '') {
            $payments[] = [
                'id' => $transactionNo,
                'status' => 'paid',
            ];
        }

        return [
            'id' => $transactionNo,
            'url' => $url,
            'status' => $status,
            'amount' => $amountMinor,
            'currency' => $currency,
            'payments' => $payments,

            'merchant_order_number' => $merchantOrderNumber,
            'provider' => 'paylink',
        ];
    }

    private function authenticate(): string
    {
        $apiId = trim(
            (string)(getenv('PAYLINK_API_ID') ?: '')
        );

        $secret = trim(
            (string)(getenv('PAYLINK_SECRET_KEY') ?: '')
        );

        if ($apiId === '' || $secret === '') {
            throw new RuntimeException(
                'مفاتيح Paylink غير مكتملة.'
            );
        }

        $response = $this->request(
            'POST',
            '/api/auth',
            [
                'apiId' => $apiId,
                'secretKey' => $secret,
                'persistToken' => false,
            ],
            null
        );

        $token = trim(
            (string)(
                $response['id_token']
                ?? $response['access_token']
                ?? $response['token']
                ?? ''
            )
        );

        if ($token === '') {
            throw new RuntimeException(
                'Paylink Authentication failed.'
            );
        }

        return $token;
    }

    private function apiRequest(
        string $method,
        string $path,
        ?array $json = null
    ): array {
        return $this->request(
            $method,
            $path,
            $json,
            $this->authenticate()
        );
    }

    private function request(
        string $method,
        string $path,
        ?array $json,
        ?string $bearerToken
    ): array {
        if (!extension_loaded('curl')) {
            throw new RuntimeException(
                'امتداد cURL غير متوفر.'
            );
        }

        $ch = curl_init(
            $this->baseUrl() . $path
        );

        if ($ch === false) {
            throw new RuntimeException(
                'تعذر بدء اتصال Paylink.'
            );
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        if ($bearerToken !== null && $bearerToken !== '') {
            $headers[] =
                'Authorization: Bearer ' . $bearerToken;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($json !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode(
                $json,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            );
        }

        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);

        $httpCode = (int)curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        $curlError = curl_error($ch);

        curl_close($ch);

        if ($body === false || $curlError !== '') {
            throw new RuntimeException(
                'تعذر الاتصال بخدمة Paylink.'
            );
        }

        $decoded = json_decode(
            (string)$body,
            true
        );

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'استجابة Paylink ليست JSON صالحًا.'
            );
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $message =
                $decoded['message']
                ?? $decoded['error']
                ?? ($decoded['response']['message'] ?? null)
                ?? 'API request failed';

            if (!is_scalar($message)) {
                $message = 'API request failed';
            }

            throw new RuntimeException(
                'Paylink: '
                . mb_substr((string)$message, 0, 180)
            );
        }

        return $decoded;
    }

    private function normalizeMobile(string $mobile): string
    {
        $mobile = preg_replace(
            '/[^0-9+]/',
            '',
            trim($mobile)
        ) ?? '';

        if (str_starts_with($mobile, '+966')) {
            $mobile = '0' . substr($mobile, 4);
        } elseif (str_starts_with($mobile, '966')) {
            $mobile = '0' . substr($mobile, 3);
        }

        if (!preg_match('/^05[0-9]{8}$/', $mobile)) {
            throw new RuntimeException(
                'رقم الجوال يجب أن يكون بصيغة 05XXXXXXXX.'
            );
        }

        return $mobile;
    }

    private function baseUrl(): string
    {
        $base = trim(
            (string)(
                getenv('PAYLINK_BASE_URL')
                ?: self::DEFAULT_BASE_URL
            )
        );

        $base = rtrim($base, '/');

        if (!str_starts_with($base, 'https://')) {
            throw new RuntimeException(
                'PAYLINK_BASE_URL يجب أن يستخدم HTTPS.'
            );
        }

        return $base;
    }

    private function appBaseUrl(): string
    {
        $base = '';

        if (function_exists('config')) {
            $base = trim(
                (string)(config('app_url') ?? '')
            );
        }

        if ($base === '') {
            $base = trim(
                (string)(getenv('APP_URL') ?: '')
            );
        }

        $base = rtrim($base, '/');

        if (
            $base === ''
            || !str_starts_with($base, 'https://')
        ) {
            throw new RuntimeException(
                'APP_URL يجب أن يكون HTTPS صالحًا.'
            );
        }

        return $base;
    }
}
