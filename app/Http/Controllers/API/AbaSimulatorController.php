<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Service\Payments\AbaPaymentSimulator;
use App\Service\Payments\PaymentPresenter;
use App\Service\Payments\PaymentVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class AbaSimulatorController extends Controller
{
    public function simulate(
        Request $request,
        int $paymentId,
        AbaPaymentSimulator $simulator,
        PaymentVerificationService $verification,
        PaymentPresenter $presenter
    ): JsonResponse {
        $validated = $request->validate([
            'result' => ['required', Rule::in(['success', 'failed', 'cancelled'])],
        ]);

        $payment = Payment::with('order')
            ->whereKey($paymentId)
            ->where('provider', 'aba_payway')
            ->where('payment_mode', 'mock')
            ->whereHas('order', fn ($query) => $query->where('user_id', $request->user()->id))
            ->first();

        if (! $payment) {
            return response()->json([
                'success' => false,
                'message' => 'Mock payment not found.',
            ], 404);
        }

        try {
            $simulator->simulate($payment, $validated['result']);
            $result = $verification->verify($payment);
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'data' => ['payment' => $presenter->qr($payment->fresh())],
            ], 409);
        } catch (Throwable $exception) {
            Log::error('ABA simulator failed.', [
                'payment_id' => $payment->id,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to simulate this payment.',
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' => 'Mock payment simulated successfully.',
            'data' => [
                'payment' => $presenter->qr($result['payment'], $result['provider_status']),
            ],
        ]);
    }
}
