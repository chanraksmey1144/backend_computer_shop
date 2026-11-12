<?php

namespace App\Service\Payments;

use App\Models\Payment;

class PaymentVerificationService
{
    public function __construct(
        private PaymentGatewayManager $gateways,
        private PaymentReconciler $reconciler
    ) {}

    public function verify(Payment $payment): array
    {
        $payment->refresh();

        if (in_array($payment->status, ['paid', 'failed', 'cancelled', 'expired', 'refunded'], true)) {
            return [
                'payment' => $payment,
                'provider_status' => $this->statusToProviderStatus($payment->status),
                'verified' => $payment->status === 'paid',
                'verification' => null,
            ];
        }

        $gateway = $this->gateways->forPayment($payment);
        $verification = $gateway->verifyPayment($payment);
        $providerStatus = strtoupper((string) ($verification['provider_status'] ?? 'PENDING'));

        if (
            in_array($providerStatus, ['PENDING', 'NOT_FOUND'], true)
            && $payment->expires_at !== null
            && now()->greaterThan($payment->expires_at)
        ) {
            $providerStatus = 'EXPIRED';
            $verification['provider_status'] = 'EXPIRED';
        }

        $updated = $this->reconciler->apply($payment, $verification);

        return [
            'payment' => $updated,
            'provider_status' => $providerStatus,
            'verified' => $updated->status === 'paid',
            'verification' => $verification,
        ];
    }

    private function statusToProviderStatus(string $status): string
    {
        return match ($status) {
            'paid' => 'APPROVED',
            'failed' => 'DECLINED',
            'cancelled' => 'CANCELLED',
            'expired' => 'EXPIRED',
            'refunded' => 'REFUNDED',
            default => 'PENDING',
        };
    }
}
