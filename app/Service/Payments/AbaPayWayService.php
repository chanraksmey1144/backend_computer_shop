<?php

namespace App\Service\Payments;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class AbaPayWayService implements PaymentGatewayInterface
{
    private string $baseUrl;

    private string $merchantId;

    private string $apiKey;

    public function __construct(private QrCodeGenerator $qrCodeGenerator)
    {
        $this->baseUrl = rtrim((string) config('services.aba_payway.base_url'), '/');
        $this->merchantId = (string) config('services.aba_payway.merchant_id');
        $this->apiKey = (string) config('services.aba_payway.api_key');
    }

    public function mode(): string
    {
        return strtolower((string) config('services.aba_payway.mode', 'sandbox'));
    }

    public function createPayment(array $data): array
    {
        $this->assertCredentialsConfigured();

        $reqTime = now('UTC')->format('YmdHis');
        $amount = number_format((float) $data['amount'], 2, '.', '');
        $shipping = number_format((float) ($data['shipping'] ?? 0), 2, '.', '');
        $itemsJson = json_encode(
            $data['items'],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $items = base64_encode($itemsJson);
        if (strlen($items) > 500) {
            $items = '';
        }
        $returnParams = json_encode([
            'order_id' => $data['order_id'],
            'payment_id' => $data['payment_id'],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $params = [
            'req_time' => $reqTime,
            'merchant_id' => $this->merchantId,
            'tran_id' => $data['tran_id'],
            'amount' => $amount,
            'items' => $items,
            'shipping' => $shipping,
            'firstname' => Str::substr((string) $data['firstname'], 0, 100),
            'lastname' => Str::substr((string) $data['lastname'], 0, 100),
            'email' => strlen((string) ($data['email'] ?? '')) <= 50 ? ($data['email'] ?? '') : '',
            'phone' => Str::substr((string) ($data['phone'] ?? ''), 0, 20),
            'type' => 'purchase',
            'payment_option' => '',
            'return_url' => $data['return_url'],
            'cancel_url' => $data['cancel_url'],
            'continue_success_url' => $data['continue_success_url'],
            'return_deeplink' => '',
            'currency' => $data['currency'],
            'custom_fields' => '',
            'return_params' => $returnParams,
            'payout' => '',
            'lifetime' => '',
            'additional_params' => '',
            'google_pay_token' => '',
            'skip_success_page' => '',
        ];

        $hashFields = [
            'req_time',
            'merchant_id',
            'tran_id',
            'amount',
            'items',
            'shipping',
            'firstname',
            'lastname',
            'email',
            'phone',
            'type',
            'payment_option',
            'return_url',
            'cancel_url',
            'continue_success_url',
            'return_deeplink',
            'currency',
            'custom_fields',
            'return_params',
            'payout',
            'lifetime',
            'additional_params',
            'google_pay_token',
            'skip_success_page',
        ];

        $params['hash'] = $this->generateHash(
            array_map(fn (string $field) => $params[$field], $hashFields)
        );

        $response = Http::asMultipart()
            ->accept('text/html')
            ->timeout(30)
            ->post($this->endpoint('/api/payment-gateway/v1/payments/purchase'), $params);

        $html = $response->body();

        if (! $response->successful() || ! str_contains(strtolower($html), '<html')) {
            throw new RuntimeException('ABA PayWay did not return a checkout page.');
        }

        return [
            'transaction_id' => $data['tran_id'],
            'checkout_html' => $html,
            'request_data' => [
                'merchant_id' => $this->merchantId,
                'tran_id' => $data['tran_id'],
                'amount' => $amount,
                'currency' => $data['currency'],
            ],
        ];
    }

    public function createQrPayment(Order $order, Payment $payment): array
    {
        $this->assertCredentialsConfigured();

        if ($this->mode() === 'mock') {
            throw new RuntimeException('ABA PayWay QR is unavailable in mock mode.');
        }

        $order->loadMissing(['items', 'user']);
        $currency = strtoupper((string) $payment->currency);
        $amount = $this->formatAmount($payment->amount, $currency);

        if (! in_array($currency, ['USD', 'KHR'], true)) {
            throw new RuntimeException('This currency is not supported by ABA PayWay.');
        }

        if ($currency === 'USD' && (float) $payment->amount < 0.01) {
            throw new RuntimeException('ABA PayWay requires at least 0.01 USD.');
        }

        if ($currency === 'KHR' && (float) $payment->amount <= 100) {
            throw new RuntimeException('ABA PayWay requires more than 100 KHR.');
        }

        $reqTime = now('UTC')->format('YmdHis');
        $nameParts = preg_split('/\s+/', trim((string) ($order->user?->name ?? 'Customer')), 2) ?: [];
        $callbackUrl = trim((string) config('services.aba_payway.callback_url', ''));

        $params = [
            'req_time' => $reqTime,
            'merchant_id' => $this->merchantId,
            'tran_id' => (string) $payment->provider_transaction_id,
            'first_name' => $this->sanitizeName($nameParts[0] ?? 'Customer'),
            'last_name' => $this->sanitizeName($nameParts[1] ?? ''),
            'email' => Str::substr((string) ($order->user?->email ?? ''), 0, 50),
            'phone' => Str::substr((string) ($order->recipient_phone ?: $order->user?->phone ?: ''), 0, 20),
            'amount' => $amount,
            'currency' => $currency,
            'purchase_type' => 'purchase',
            'payment_option' => 'abapay_khqr',
            'items' => $this->encodeItems($order),
            'callback_url' => $callbackUrl !== '' ? base64_encode($callbackUrl) : '',
            'return_deeplink' => '',
            'custom_fields' => '',
            'return_params' => '',
            'payout' => '',
            'lifetime' => (int) config('services.aba_payway.qr_lifetime_minutes', 15),
            'qr_image_template' => (string) config('services.aba_payway.qr_image_template', 'template3_color'),
        ];

        $hashFields = [
            'req_time',
            'merchant_id',
            'tran_id',
            'amount',
            'items',
            'first_name',
            'last_name',
            'email',
            'phone',
            'purchase_type',
            'payment_option',
            'callback_url',
            'return_deeplink',
            'currency',
            'custom_fields',
            'return_params',
            'payout',
            'lifetime',
            'qr_image_template',
        ];

        $params['hash'] = $this->generateHash(
            array_map(fn (string $field) => $params[$field], $hashFields)
        );

        $response = Http::asJson()
            ->acceptJson()
            ->timeout(30)
            ->post($this->endpoint('/api/payment-gateway/v1/payments/generate-qr'), $params);

        $payload = $response->json();
        $statusCode = (string) data_get($payload, 'status.code', '');

        if (
            ! $response->successful()
            || ! is_array($payload)
            || ! in_array($statusCode, ['0', '00'], true)
            || empty($payload['qrString'])
        ) {
            $message = (string) data_get($payload, 'status.message', 'ABA PayWay did not return a QR code.');

            throw new RuntimeException('ABA PayWay QR generation failed: '.$message);
        }

        $qrString = (string) $payload['qrString'];
        $qrImage = (string) ($payload['qrImage'] ?? '');

        if ($qrImage === '') {
            $qrImage = $this->qrCodeGenerator->generateDataUri($qrString);
        }

        return [
            'qr_string' => $qrString,
            'qr_image' => $qrImage,
            'abapay_deeplink' => $payload['abapay_deeplink'] ?? null,
            'app_store' => $payload['app_store'] ?? null,
            'play_store' => $payload['play_store'] ?? null,
            'request' => [
                'merchant_id' => $this->merchantId,
                'tran_id' => $params['tran_id'],
                'amount' => $amount,
                'currency' => $currency,
                'payment_option' => 'abapay_khqr',
                'lifetime' => $params['lifetime'],
                'qr_image_template' => $params['qr_image_template'],
            ],
            'response' => [
                'status' => [
                    'code' => $statusCode,
                    'message' => (string) data_get($payload, 'status.message', ''),
                    'trace_id' => (string) data_get($payload, 'status.trace_id', ''),
                ],
                'amount' => $payload['amount'] ?? null,
                'currency' => $payload['currency'] ?? null,
                'qr_string' => $qrString,
            ],
        ];
    }

    public function checkTransaction(string $transactionId): array
    {
        $this->assertCredentialsConfigured();

        $reqTime = now('UTC')->format('YmdHis');
        $hash = $this->generateHash([
            $reqTime,
            $this->merchantId,
            $transactionId,
        ]);

        $response = Http::asJson()
            ->acceptJson()
            ->timeout(10)
            ->post($this->endpoint('/api/payment-gateway/v1/payments/check-transaction-2'), [
                'req_time' => $reqTime,
                'merchant_id' => $this->merchantId,
                'tran_id' => $transactionId,
                'hash' => $hash,
            ]);

        $payload = $response->json();

        if (! $response->successful() || ! is_array($payload)) {
            throw new RuntimeException('Unable to check ABA PayWay transaction status.');
        }

        $statusCode = (string) data_get($payload, 'status.code', '');

        if ($statusCode !== '' && ! in_array($statusCode, ['00', '5', '6'], true)) {
            throw new RuntimeException('ABA PayWay rejected the transaction status request.');
        }

        return $payload;
    }

    public function verifyPayment(Payment $payment): array
    {
        $payload = $this->checkTransaction((string) $payment->provider_transaction_id);
        $statusCode = (string) data_get($payload, 'status.code', '');
        $data = (array) ($payload['data'] ?? []);

        if ($statusCode === '5') {
            throw new RuntimeException('ABA PayWay rejected the verification hash.');
        }

        $transactionId = (string) (data_get($payload, 'status.tran_id')
            ?? $payment->provider_transaction_id);

        if (
            $transactionId !== ''
            && ! hash_equals((string) $payment->provider_transaction_id, $transactionId)
        ) {
            throw new RuntimeException('ABA PayWay returned a different transaction ID.');
        }

        $providerStatus = $statusCode === '6'
            ? 'NOT_FOUND'
            : strtoupper((string) ($data['payment_status'] ?? 'PENDING'));

        if (
            $providerStatus === 'APPROVED'
            && (string) ($data['payment_status_code'] ?? '') !== '0'
        ) {
            $providerStatus = 'PENDING';
        }

        return [
            'provider_status' => $providerStatus,
            'amount' => $data['total_amount'] ?? null,
            'currency' => isset($data['payment_currency'])
                ? strtoupper((string) $data['payment_currency'])
                : null,
            'transaction_id' => $transactionId,
            'raw' => [
                'payment_status' => $providerStatus,
                'payment_status_code' => $data['payment_status_code'] ?? null,
                'total_amount' => $data['total_amount'] ?? null,
                'payment_amount' => $data['payment_amount'] ?? null,
                'payment_currency' => $data['payment_currency'] ?? null,
                'apv' => $data['apv'] ?? null,
                'transaction_date' => $data['transaction_date'] ?? null,
            ],
        ];
    }

    public function closeTransaction(string $transactionId): array
    {
        $this->assertCredentialsConfigured();

        $reqTime = now('UTC')->format('YmdHis');
        $hash = $this->generateHash([
            $reqTime,
            $this->merchantId,
            $transactionId,
        ]);

        $response = Http::asJson()
            ->acceptJson()
            ->timeout(10)
            ->post($this->endpoint('/api/payment-gateway/v1/payments/close-transaction'), [
                'req_time' => $reqTime,
                'merchant_id' => $this->merchantId,
                'tran_id' => $transactionId,
                'hash' => $hash,
            ]);

        $payload = $response->json();

        if (! $response->successful() || ! is_array($payload)) {
            throw new RuntimeException('Unable to close the ABA PayWay transaction.');
        }

        return $payload;
    }

    public function isValidCallbackSignature(array $payload, string $signature): bool
    {
        $this->assertCredentialsConfigured();

        ksort($payload, SORT_STRING);
        $hashString = '';

        foreach ($payload as $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }

            $hashString .= (string) ($value ?? '');
        }

        return hash_equals($this->generateHash([$hashString]), $signature);
    }

    private function generateHash(array $values): string
    {
        $hashString = implode('', array_map(
            static fn ($value) => (string) ($value ?? ''),
            $values
        ));

        return base64_encode(hash_hmac('sha512', $hashString, $this->apiKey, true));
    }

    private function assertCredentialsConfigured(): void
    {
        if ($this->baseUrl === '' || $this->merchantId === '' || $this->apiKey === '') {
            throw new RuntimeException('ABA PayWay is not configured.');
        }

        if (parse_url($this->baseUrl, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('ABA PayWay must use an HTTPS endpoint.');
        }
    }

    private function endpoint(string $path): string
    {
        return $this->baseUrl.$path;
    }

    private function formatAmount(mixed $amount, string $currency): string
    {
        if ($currency === 'KHR') {
            return (string) (int) round((float) $amount);
        }

        return number_format((float) $amount, 2, '.', '');
    }

    private function encodeItems(Order $order): string
    {
        $rows = $order->items
            ->map(fn ($item) => [
                'name' => Str::substr((string) $item->product_name, 0, 80),
                'quantity' => (int) $item->quantity,
                'price' => number_format((float) $item->unit_price, 2, '.', ''),
            ])
            ->values()
            ->all();

        $encoded = base64_encode(json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return strlen($encoded) > 500 ? '' : $encoded;
    }

    private function sanitizeName(string $name): string
    {
        $name = preg_replace("/[^\\p{L}\\s'.\\-]/u", '', $name) ?? '';

        return Str::substr(trim($name), 0, 20);
    }
}
