<?php

namespace App\Service\Payments;

use App\Models\Order;
use App\Models\Payment;

interface PaymentGatewayInterface
{
    public function mode(): string;

    /**
     * Create a scannable QR payment with the provider.
     *
     * @return array{
     *     qr_string: string,
     *     qr_image: string|null,
     *     abapay_deeplink: string|null,
     *     app_store: string|null,
     *     play_store: string|null,
     *     request: array,
     *     response: array
     * }
     */
    public function createQrPayment(Order $order, Payment $payment): array;

    /**
     * Verify the transaction directly with the provider.
     *
     * @return array{
     *     provider_status: string,
     *     amount: mixed,
     *     currency: string|null,
     *     transaction_id: string|null,
     *     raw: array
     * }
     */
    public function verifyPayment(Payment $payment): array;

    public function isValidCallbackSignature(array $payload, string $signature): bool;
}
