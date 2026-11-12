<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Service\Payments\AbaPayWayService;
use App\Service\Payments\PaymentVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        AbaPayWayService $abaPayWay,
        PaymentVerificationService $verification
    ): JsonResponse {
        if (! $request->isJson()) {
            return response()->json(['success' => false, 'message' => 'JSON is required.'], 415);
        }

        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload) || empty($payload['tran_id'])) {
            return response()->json(['success' => false, 'message' => 'Invalid callback payload.'], 422);
        }

        try {
            $signature = (string) $request->header('X-PayWay-Hmac-Sha512', '');

            if ($signature === '' || ! $abaPayWay->isValidCallbackSignature($payload, $signature)) {
                Log::warning('ABA PayWay callback signature validation failed.');

                return response()->json(['success' => false, 'message' => 'Invalid callback signature.'], 401);
            }
        } catch (Throwable $exception) {
            Log::error('ABA PayWay callback validation failed.', [
                'exception' => $exception->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Payment callback is unavailable.'], 503);
        }

        $payment = Payment::where('provider', 'aba_payway')
            ->where('provider_transaction_id', (string) $payload['tran_id'])
            ->first();

        if (! $payment) {
            Log::warning('ABA PayWay callback references an unknown transaction.');

            return response()->json(['success' => true, 'message' => 'Callback received.']);
        }

        if ($payment->payment_mode === 'mock') {
            Log::warning('Ignored a live callback for a mock ABA PayWay payment.', [
                'payment_id' => $payment->id,
            ]);

            return response()->json(['success' => true, 'message' => 'Callback received.']);
        }

        try {
            $result = $verification->verify($payment);

            if (! in_array($result['payment']->status, ['paid', 'failed', 'cancelled', 'expired', 'refunded'], true)) {
                Log::info('ABA PayWay callback acknowledged while payment is still pending.', [
                    'payment_id' => $payment->id,
                    'provider_status' => $result['provider_status'],
                ]);
            }
        } catch (Throwable $exception) {
            Log::error('ABA PayWay callback verification failed.', [
                'payment_id' => $payment->id,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Payment verification is unavailable.'], 502);
        }

        return response()->json(['success' => true, 'message' => 'Callback received.']);
    }
}
