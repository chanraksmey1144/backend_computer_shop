<?php

namespace Tests\Unit;

use App\Service\Payments\AbaPayWayService;
use Carbon\Carbon;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AbaPayWayServiceTest extends TestCase
{
    private string $apiKey = 'test-payway-key';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.aba_payway.base_url' => 'https://checkout-sandbox.payway.com.kh',
            'services.aba_payway.merchant_id' => 'merchant-test',
            'services.aba_payway.api_key' => $this->apiKey,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_purchase_uses_multipart_and_the_payway_field_order_for_its_hash(): void
    {
        Carbon::setTestNow('2026-11-07 12:34:56 UTC');
        Http::fake([
            '*payments/purchase' => Http::response('<html><body>ABA checkout</body></html>', 200, [
                'Content-Type' => 'text/html',
            ]),
        ]);

        $result = app(AbaPayWayService::class)->createPayment([
            'order_id' => 12,
            'payment_id' => 34,
            'tran_id' => 'ABA261107123456ABCD',
            'amount' => '125.50',
            'shipping' => '0.00',
            'currency' => 'USD',
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'email' => 'jane@example.com',
            'phone' => '012345678',
            'items' => [['name' => 'Laptop', 'quantity' => 1, 'price' => '125.50']],
            'return_url' => 'https://api.example.test/api/payments/aba/callback',
            'continue_success_url' => 'https://shop.example.test/payment/result?payment_id=34',
            'cancel_url' => 'https://shop.example.test/payment/result?payment_id=34&result=cancelled',
        ]);

        $this->assertSame('ABA261107123456ABCD', $result['transaction_id']);
        $this->assertStringContainsString('<html', $result['checkout_html']);

        Http::assertSent(function (ClientRequest $request) {
            $fields = [];
            foreach ($request->data() as $part) {
                $fields[$part['name']] = $part['contents'];
            }
            $hashFields = [
                'req_time', 'merchant_id', 'tran_id', 'amount', 'items', 'shipping',
                'firstname', 'lastname', 'email', 'phone', 'type', 'payment_option',
                'return_url', 'cancel_url', 'continue_success_url', 'return_deeplink',
                'currency', 'custom_fields', 'return_params', 'payout', 'lifetime',
                'additional_params', 'google_pay_token', 'skip_success_page',
            ];
            $hashString = '';
            foreach ($hashFields as $field) {
                $hashString .= (string) ($fields[$field] ?? '');
            }
            $expectedHash = base64_encode(hash_hmac('sha512', $hashString, $this->apiKey, true));

            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/api/payment-gateway/v1/payments/purchase')
                && $request->isMultipart()
                && ($fields['req_time'] ?? null) === '20261107123456'
                && ($fields['hash'] ?? null) === $expectedHash;
        });
    }

    public function test_status_check_posts_json_and_hashes_the_transaction_request(): void
    {
        Carbon::setTestNow('2026-11-07 12:34:56 UTC');
        Http::fake([
            '*check-transaction-2' => Http::response([
                'data' => [
                    'payment_status_code' => 0,
                    'payment_status' => 'APPROVED',
                    'total_amount' => 125.50,
                    'payment_currency' => 'USD',
                ],
                'status' => ['code' => '00', 'message' => 'Success!'],
            ]),
        ]);

        $result = app(AbaPayWayService::class)->checkTransaction('ABA261107123456ABCD');

        $this->assertSame('APPROVED', $result['data']['payment_status']);
        Http::assertSent(function (ClientRequest $request) {
            $fields = $request->data();
            $expectedHash = base64_encode(hash_hmac(
                'sha512',
                '20261107123456merchant-testABA261107123456ABCD',
                $this->apiKey,
                true
            ));

            return $request->isJson()
                && ($fields['req_time'] ?? null) === '20261107123456'
                && ($fields['hash'] ?? null) === $expectedHash;
        });
    }

    public function test_callback_signature_is_verified_against_sorted_payload_values(): void
    {
        $payload = [
            'tran_id' => 'ABA261107123456ABCD',
            'status' => '0',
            'total_amount' => 125.5,
            'payment_currency' => 'USD',
        ];
        $sorted = $payload;
        ksort($sorted, SORT_STRING);
        $hashString = implode('', array_map(static fn ($value) => (string) $value, $sorted));
        $signature = base64_encode(hash_hmac('sha512', $hashString, $this->apiKey, true));

        $service = app(AbaPayWayService::class);

        $this->assertTrue($service->isValidCallbackSignature($payload, $signature));
        $this->assertFalse($service->isValidCallbackSignature($payload, 'invalid-signature'));
    }
}
