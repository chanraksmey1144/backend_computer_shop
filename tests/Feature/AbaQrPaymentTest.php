<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class AbaQrPaymentTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.aba_payway.mode' => 'mock',
            'services.aba_payway.merchant_id' => 'merchant-test',
            'services.aba_payway.api_key' => 'test-payway-key',
            'services.aba_payway.base_url' => 'https://checkout-sandbox.payway.com.kh',
            'services.aba_payway.qr_lifetime_minutes' => 15,
            'services.aba_payway.mock_ttl_minutes' => 15,
        ]);
    }

    public function test_mock_qr_can_be_created_reused_and_simulated_to_paid(): void
    {
        $user = $this->createUser();
        $order = $this->createOrder($user);
        $this->actingAs($user, 'sanctum');

        $created = $this->postJson('/api/payments/aba/qr', ['order_id' => $order->id]);

        $created->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.payment.payment_mode', 'mock')
            ->assertJsonPath('data.payment.is_mock', true)
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.payment.amount', '125.50');

        $paymentId = $created->json('data.payment.id');

        $this->assertNotEmpty($created->json('data.payment.qr_string'));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $created->json('data.payment.qr_image'));

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'payment_mode' => 'mock',
            'payment_method' => 'aba_qr',
            'status' => 'pending',
        ]);

        $this->postJson('/api/payments/aba/qr', ['order_id' => $order->id])
            ->assertOk()
            ->assertJsonPath('data.payment.id', $paymentId);

        $this->getJson("/api/payments/{$paymentId}/status")
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.payment.provider_status', 'PENDING');

        $this->postJson("/api/dev/aba-simulator/payments/{$paymentId}/simulate", [
            'result' => 'success',
        ])
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'paid')
            ->assertJsonPath('data.payment.provider_status', 'APPROVED');

        $this->assertDatabaseHas('payments', ['id' => $paymentId, 'status' => 'paid']);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => 'paid',
            'status' => 'confirmed',
        ]);
    }

    public function test_mock_qr_payment_can_be_cancelled(): void
    {
        $user = $this->createUser();
        $order = $this->createOrder($user);
        $this->actingAs($user, 'sanctum');

        $paymentId = $this->postJson('/api/payments/aba/qr', ['order_id' => $order->id])
            ->assertCreated()
            ->json('data.payment.id');

        $this->postJson("/api/payments/{$paymentId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'cancelled')
            ->assertJsonPath('data.payment.provider_status', 'CANCELLED');

        $this->assertDatabaseHas('payments', ['id' => $paymentId, 'status' => 'cancelled']);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => 'failed',
        ]);
    }

    public function test_amount_mismatch_never_marks_payment_paid(): void
    {
        $user = $this->createUser();
        $order = $this->createOrder($user);
        $this->actingAs($user, 'sanctum');

        $paymentId = $this->postJson('/api/payments/aba/qr', ['order_id' => $order->id])
            ->assertCreated()
            ->json('data.payment.id');

        $payment = Payment::findOrFail($paymentId);
        $providerResponse = $payment->provider_response;
        $providerResponse['simulator'] = [
            'result' => 'success',
            'amount' => '999.00',
            'currency' => 'USD',
            'simulated_at' => now()->toIso8601String(),
        ];
        $payment->update(['provider_response' => $providerResponse]);

        $this->getJson("/api/payments/{$paymentId}/status")->assertStatus(502);

        $this->assertDatabaseHas('payments', ['id' => $paymentId, 'status' => 'pending']);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => 'pending',
        ]);
    }

    public function test_simulator_is_not_available_outside_mock_mode(): void
    {
        $user = $this->createUser();
        $order = $this->createOrder($user);

        $payment = Payment::create([
            'order_id' => $order->id,
            'provider' => 'aba_payway',
            'provider_transaction_id' => Payment::generateAbaTransactionId(),
            'payment_method' => 'aba_qr',
            'payment_mode' => 'mock',
            'amount' => $order->total_amount,
            'currency' => $order->currency,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);

        config(['services.aba_payway.mode' => 'sandbox']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/dev/aba-simulator/payments/{$payment->id}/simulate", [
                'result' => 'success',
            ])
            ->assertNotFound();
    }

    public function test_cannot_create_qr_for_another_users_order(): void
    {
        $owner = $this->createUser();
        $order = $this->createOrder($owner);
        $other = $this->createUser();

        $this->actingAs($other, 'sanctum')
            ->postJson('/api/payments/aba/qr', ['order_id' => $order->id])
            ->assertNotFound();
    }

    private function createUser(): User
    {
        return User::factory()->create([
            'password' => bcrypt('password'),
        ]);
    }

    private function createOrder(User $user): Order
    {
        return Order::create([
            'order_number' => 'ORD-QR-'.Str::upper(Str::random(10)),
            'user_id' => $user->id,
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'currency' => 'USD',
            'subtotal' => 125.50,
            'discount_amount' => 0,
            'delivery_fee' => 0,
            'tax_amount' => 0,
            'total_amount' => 125.50,
            'recipient_name' => $user->name,
            'recipient_phone' => '012345678',
            'shipping_address' => 'Phnom Penh',
            'placed_at' => now(),
        ]);
    }
}
