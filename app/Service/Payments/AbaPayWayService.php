<?php

namespace App\Service\Payments;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AbaPayWayService
{
    private string $baseUrl;

    private string $merchantId;

    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.aba_payway.base_url'), '/');
        $this->merchantId = (string) config('services.aba_payway.merchant_id');
        $this->apiKey = (string) config('services.aba_payway.api_key');
    }

    public function createPayment(array $data): array
    {
        $this->assertConfigured();

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
            'firstname' => $data['firstname'],
            'lastname' => $data['lastname'],
            'email' => $data['email'] ?? '',
            'phone' => $data['phone'] ?? '',
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

    public function checkTransaction(string $transactionId): array
    {
        $this->assertConfigured();

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

        if (
            ! $response->successful()
            || ! is_array($payload)
            || ! is_array($payload['data'] ?? null)
            || (isset($payload['status']['code']) && (string) $payload['status']['code'] !== '00')
        ) {
            throw new RuntimeException('Unable to check ABA PayWay transaction status.');
        }

        return $payload;
    }

    public function isValidCallbackSignature(array $payload, string $signature): bool
    {
        $this->assertConfigured();

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

    private function assertConfigured(): void
    {
        if ($this->baseUrl === '' || $this->merchantId === '' || $this->apiKey === '') {
            throw new RuntimeException('ABA PayWay is not configured.');
        }
    }

    private function endpoint(string $path): string
    {
        return $this->baseUrl.$path;
    }
}
