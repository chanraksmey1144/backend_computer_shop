<?php

namespace App\Service\Payments;

use App\Models\Order;
use App\Models\Payment;
use RuntimeException;

class AbaPaymentSimulator implements PaymentGatewayInterface
{
    private const ALLOWED_RESULTS = ['success', 'failed', 'cancelled'];

    public function __construct(private QrCodeGenerator $qrCodeGenerator) {}

    public function mode(): string
    {
        return 'mock';
    }

    public function createQrPayment(Order $order, Payment $payment): array
    {
        $this->assertAvailable();

        $transactionId = (string) $payment->provider_transaction_id;
        $expiresAt = $payment->expires_at?->toIso8601String()
            ?? now()->addMinutes((int) config('services.aba_payway.mock_ttl_minutes', 15))->toIso8601String();

        $qrString = implode('|', [
            'COMPUTERSHOP-MOCK-ABA',
            'v1',
            'tran='.$transactionId,
            'order='.$order->order_number,
            'amount='.$this->formatAmount($payment->amount),
            'currency='.strtoupper((string) $payment->currency),
            'expires='.$expiresAt,
        ]);

        $qrString .= '|sig='.substr(
            hash_hmac('sha256', $qrString, (string) config('app.key')),
            0,
            16
        );

        $qrImage = $this->qrCodeGenerator->generateDataUri($qrString);

        return [
            'qr_string' => $qrString,
            'qr_image' => $qrImage,
            'abapay_deeplink' => null,
            'app_store' => null,
            'play_store' => null,
            'request' => [
                'mode' => 'mock',
                'tran_id' => $transactionId,
                'amount' => $this->formatAmount($payment->amount),
                'currency' => strtoupper((string) $payment->currency),
                'lifetime_minutes' => (int) config('services.aba_payway.mock_ttl_minutes', 15),
            ],
            'response' => [
                'simulated' => true,
                'qr_string' => $qrString,
            ],
        ];
    }

    public function verifyPayment(Payment $payment): array
    {
        $this->assertAvailable();

        if ($payment->payment_mode !== 'mock') {
            throw new RuntimeException('This payment was not created in mock mode.');
        }

        $providerResponse = (array) $payment->provider_response;
        $simulator = (array) ($providerResponse['simulator'] ?? []);
        $result = strtolower((string) ($simulator['result'] ?? ''));

        $providerStatus = match ($result) {
            'success' => 'APPROVED',
            'failed' => 'DECLINED',
            'cancelled' => 'CANCELLED',
            default => $this->isExpired($payment) ? 'EXPIRED' : 'PENDING',
        };

        return [
            'provider_status' => $providerStatus,
            'amount' => $simulator['amount'] ?? $payment->amount,
            'currency' => strtoupper((string) ($simulator['currency'] ?? $payment->currency)),
            'transaction_id' => $payment->provider_transaction_id,
            'raw' => [
                'mock' => true,
                'result' => $result !== '' ? $result : null,
                'simulated_at' => $simulator['simulated_at'] ?? null,
                'expires_at' => $payment->expires_at?->toIso8601String(),
            ],
        ];
    }

    public function isValidCallbackSignature(array $payload, string $signature): bool
    {
        return false;
    }

    public function simulate(Payment $payment, string $result): void
    {
        $this->assertAvailable();

        if ($payment->payment_mode !== 'mock') {
            throw new RuntimeException('Only mock payments can be simulated.');
        }

        if (! in_array($result, self::ALLOWED_RESULTS, true)) {
            throw new RuntimeException('Unsupported simulation result.');
        }

        if (in_array($payment->status, ['paid', 'failed', 'cancelled', 'expired', 'refunded'], true)) {
            throw new RuntimeException('This payment can no longer be simulated.');
        }

        if ($this->isExpired($payment)) {
            throw new RuntimeException('This mock payment has expired.');
        }

        $providerResponse = (array) $payment->provider_response;
        $providerResponse['simulator'] = [
            'result' => $result,
            'amount' => $this->formatAmount($payment->amount),
            'currency' => strtoupper((string) $payment->currency),
            'simulated_at' => now()->toIso8601String(),
        ];

        $payment->forceFill(['provider_response' => $providerResponse])->save();
    }

    private function assertAvailable(): void
    {
        if (! in_array(app()->environment(), ['local', 'testing'], true)) {
            throw new RuntimeException('The ABA payment simulator is only available locally.');
        }

        if (config('services.aba_payway.mode') !== 'mock') {
            throw new RuntimeException('The ABA payment simulator requires mock mode.');
        }
    }

    private function isExpired(Payment $payment): bool
    {
        return $payment->expires_at !== null && now()->greaterThan($payment->expires_at);
    }

    private function formatAmount(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
