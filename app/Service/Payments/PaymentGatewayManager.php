<?php

namespace App\Service\Payments;

use App\Models\Payment;
use RuntimeException;

class PaymentGatewayManager
{
    public function __construct(
        private AbaPayWayService $abaPayWay,
        private AbaPaymentSimulator $simulator
    ) {}

    public function mode(): string
    {
        return strtolower((string) config('services.aba_payway.mode', 'sandbox'));
    }

    public function forMode(string $mode): PaymentGatewayInterface
    {
        $mode = strtolower($mode);
        $this->assertModeAllowed($mode);

        return match ($mode) {
            'mock' => $this->simulator,
            'sandbox', 'production' => $this->abaPayWay,
            default => throw new RuntimeException('Unsupported payment mode.'),
        };
    }

    public function forPayment(Payment $payment): PaymentGatewayInterface
    {
        $mode = strtolower((string) ($payment->payment_mode ?? ''));

        if ($mode === '') {
            $mode = $this->legacyMode();
        }

        return $this->forMode($mode);
    }

    private function legacyMode(): string
    {
        $mode = $this->mode();

        return $mode === 'mock' ? 'sandbox' : $mode;
    }

    private function assertModeAllowed(string $mode): void
    {
        if (! in_array($mode, ['mock', 'sandbox', 'production'], true)) {
            throw new RuntimeException('Unsupported payment mode.');
        }

        if ($mode === 'mock' && app()->environment('production')) {
            throw new RuntimeException('Mock ABA payments are not allowed in production.');
        }
    }
}
