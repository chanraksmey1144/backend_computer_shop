<?php

namespace App\Service\Payments;

use App\Models\Payment;

class PaymentPresenter
{
    public function qr(Payment $payment, ?string $providerStatus = null): array
    {
        $payment->loadMissing('order');
        $providerResponse = (array) ($payment->provider_response ?? []);
        $result = (array) ($providerResponse['result'] ?? []);

        return [
            'id' => $payment->id,
            'order_id' => $payment->order_id,
            'order_number' => $payment->order?->order_number,
            'provider' => $payment->provider,
            'payment_method' => $payment->payment_method,
            'payment_mode' => $payment->payment_mode,
            'transaction_id' => $payment->provider_transaction_id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'status' => $payment->status,
            'provider_status' => $providerStatus,
            'expires_at' => $payment->expires_at?->toIso8601String(),
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'qr_string' => $payment->qr_string,
            'qr_image' => $payment->qr_image,
            'is_mock' => $payment->payment_mode === 'mock',
            'abapay_deeplink' => $result['abapay_deeplink'] ?? null,
            'app_store' => $result['app_store'] ?? null,
            'play_store' => $result['play_store'] ?? null,
        ];
    }
}
