<?php

namespace App\Service\Payments;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PaymentReconciler
{
    public function apply(Payment $payment, array $verification): Payment
    {
        $target = $this->targetStatus((string) ($verification['provider_status'] ?? ''));

        if ($target === null) {
            return $payment->fresh() ?? $payment;
        }

        return DB::transaction(function () use ($payment, $target, $verification) {
            $locked = Payment::whereKey($payment->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new RuntimeException('Payment not found.');
            }

            if ($locked->status === 'paid') {
                return $locked;
            }

            if ($target === 'paid') {
                if (in_array($locked->status, ['failed', 'cancelled', 'expired', 'refunded'], true)) {
                    Log::warning('Refusing to mark a terminal ABA payment as paid.', [
                        'payment_id' => $locked->id,
                        'current_status' => $locked->status,
                    ]);

                    return $locked;
                }

                $this->assertVerifiedAmount($locked, $verification);
            }

            $order = Order::whereKey($locked->order_id)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                throw new RuntimeException('Order not found.');
            }

            $providerResponse = (array) $locked->provider_response;
            $providerResponse['verification'] = [
                'provider_status' => strtoupper((string) ($verification['provider_status'] ?? '')),
                'amount' => $verification['amount'] ?? null,
                'currency' => $verification['currency'] ?? null,
                'transaction_id' => $verification['transaction_id'] ?? null,
                'verified_at' => now()->toIso8601String(),
                'raw' => $verification['raw'] ?? [],
            ];

            $locked->update([
                'status' => $target,
                'paid_at' => $target === 'paid' ? ($locked->paid_at ?? now()) : null,
                'provider_response' => $providerResponse,
            ]);

            if ($target === 'paid') {
                $order->update([
                    'payment_status' => 'paid',
                    'status' => $order->status === 'pending' ? 'confirmed' : $order->status,
                ]);
            } elseif (in_array($target, ['failed', 'cancelled', 'expired'], true)) {
                if ($order->payment_status !== 'paid') {
                    $order->update(['payment_status' => 'failed']);
                }
            } elseif ($target === 'refunded') {
                $order->update(['payment_status' => 'refunded']);
            }

            return $locked->fresh();
        });
    }

    public function targetStatus(string $providerStatus): ?string
    {
        return match (strtoupper($providerStatus)) {
            'APPROVED' => 'paid',
            'DECLINED' => 'failed',
            'CANCELLED' => 'cancelled',
            'EXPIRED' => 'expired',
            'REFUNDED' => 'refunded',
            default => null,
        };
    }

    private function assertVerifiedAmount(Payment $payment, array $verification): void
    {
        $amount = $verification['amount'] ?? null;
        $currency = strtoupper((string) ($verification['currency'] ?? ''));
        $expectedAmount = number_format((float) $payment->amount, 2, '.', '');
        $actualAmount = is_numeric($amount)
            ? number_format((float) $amount, 2, '.', '')
            : null;

        if (
            $actualAmount === null
            || $actualAmount !== $expectedAmount
            || $currency !== strtoupper((string) $payment->currency)
        ) {
            Log::warning('ABA PayWay amount or currency mismatch.', [
                'payment_id' => $payment->id,
                'expected_amount' => $expectedAmount,
                'actual_amount' => $actualAmount,
                'expected_currency' => $payment->currency,
                'actual_currency' => $currency,
            ]);

            throw new RuntimeException('The payment amount or currency could not be verified.');
        }
    }
}
