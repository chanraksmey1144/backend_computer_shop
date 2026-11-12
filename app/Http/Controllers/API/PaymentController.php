<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Service\Payments\AbaPaymentSimulator;
use App\Service\Payments\AbaPayWayService;
use App\Service\Payments\PaymentGatewayManager;
use App\Service\Payments\PaymentPresenter;
use App\Service\Payments\PaymentReconciler;
use App\Service\Payments\PaymentVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class PaymentController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | 1. GET ALL MY PAYMENTS
    |--------------------------------------------------------------------------
    */

    public function index(Request $request)
    {
        $payments = Payment::whereHas('order', function ($query) use ($request) {

            $query->where('user_id', $request->user()->id);

        })
        ->with('order:id,order_number,status,total_amount')
        ->latest()
        ->paginate(10);

        return response()->json([
            'success' => true,
            'message' => 'Payments retrieved successfully.',
            'data' => $payments
        ], 200);
    }


    /*
    |--------------------------------------------------------------------------
    | 2. CREATE PAYMENT
    |--------------------------------------------------------------------------
    */

    public function store(Request $request)
    {
        $validated = $request->validate([

            'order_id' => [
                'required',
                'integer',
                'exists:orders,id'
            ],

            'provider' => [
                'required',
                Rule::in([
                    'cash_on_delivery',
                    'aba_payway',
                    'bakong',
                    'bank_transfer',
                    'other'
                ])
            ],

            'payment_method' => [
                'nullable',
                'string',
                'max:80'
            ],

        ]);

        if ($validated['provider'] === 'aba_payway') {
            return response()->json([
                'success' => false,
                'message' => 'Use the ABA PayWay QR endpoint to create this payment.',
            ], 409);
        }

        $user = $request->user();

        $payment = DB::transaction(function () use ($validated, $user) {

            // Find and lock customer's order
            $order = Order::where('id', $validated['order_id'])
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                return null;
            }

            // Check order status
            if (in_array($order->status, [
                'cancelled',
                'refunded'
            ])) {
                return 'invalid_order';
            }

            // Check payment status
            if (in_array($order->payment_status, [
                'paid',
                'refunded'
            ])) {
                return 'already_paid';
            }

            // Prevent another active payment attempt
            $existingPayment = Payment::where('order_id', $order->id)
                ->whereIn('status', [
                    'pending',
                    'processing'
                ])
                ->lockForUpdate()
                ->first();

            if ($existingPayment) {
                return $existingPayment;
            }

            // Create payment record
            $payment = Payment::create([

                'order_id' => $order->id,

                'provider' => $validated['provider'],

                'payment_method' => $validated['payment_method'] ?? null,

                // Amount must come from database, not Postman
                'amount' => $order->total_amount,

                'currency' => $order->currency,

                'status' => 'pending',

            ]);

            // Update order payment status
            $order->update([
                'payment_status' => 'pending'
            ]);

            return $payment;
        });

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.'
            ], 404);
        }

        if ($payment === 'invalid_order') {
            return response()->json([
                'success' => false,
                'message' => 'This order cannot be paid.'
            ], 422);
        }

        if ($payment === 'already_paid') {
            return response()->json([
                'success' => false,
                'message' => 'This order has already been paid.'
            ], 422);
        }

        if ($payment instanceof Payment && $payment->exists) {

            // Existing active payment
            if (
                $payment->wasRecentlyCreated === false &&
                $payment->status !== 'pending'
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'An active payment already exists.',
                    'data' => $payment
                ], 409);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment created successfully.',
            'data' => $payment
        ], 201);
    }


    /*
    |--------------------------------------------------------------------------
    | 3. GET PAYMENT BY ID
    |--------------------------------------------------------------------------
    */

    public function show(Request $request,int $id)
    {
        $payment = Payment::whereHas('order', function ($query) use ($request) {

            $query->where('user_id', $request->user()->id);

        })
        ->with('order')
        ->find($id);

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment retrieved successfully.',
            'data' => $payment
        ], 200);
    }


    /*
    |--------------------------------------------------------------------------
    | 4. GET PAYMENTS BY ORDER
    |--------------------------------------------------------------------------
    */

    public function orderPayments(Request $request,int $orderId)
    {
        $order = $request->user()
            ->orders()
            ->find($orderId);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.'
            ], 404);
        }

        $payments = $order->payments()
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $payments
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | 5. CREATE ABA PAYWAY QR PAYMENT
    |--------------------------------------------------------------------------
    */

    public function createAbaQr(
        Request $request,
        PaymentGatewayManager $gateways,
        PaymentPresenter $presenter
    ): JsonResponse {
        $validated = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
        ]);

        $mode = $gateways->mode();

        try {
            $gateway = $gateways->forMode($mode);
        } catch (Throwable $exception) {
            Log::error('ABA QR mode is unavailable.', [
                'mode' => $mode,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'ABA QR payments are unavailable.',
            ], 503);
        }

        $attempt = DB::transaction(function () use ($request, $validated, $mode) {
            $order = Order::whereKey($validated['order_id'])
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                return ['error' => 'not_found'];
            }

            if ($order->payment_status === 'paid') {
                return ['error' => 'already_paid'];
            }

            if (
                in_array($order->status, ['cancelled', 'refunded'], true)
                || $order->payment_status === 'refunded'
            ) {
                return ['error' => 'invalid_order'];
            }

            if (
                (float) $order->total_amount <= 0
                || ! in_array(strtoupper((string) $order->currency), ['USD', 'KHR'], true)
            ) {
                return ['error' => 'invalid_amount'];
            }

            $activePayments = Payment::where('order_id', $order->id)
                ->where('provider', 'aba_payway')
                ->whereIn('status', ['pending', 'processing'])
                ->lockForUpdate()
                ->latest('id')
                ->get();

            $reusable = null;

            foreach ($activePayments as $active) {
                if ($active->payment_method === 'aba_qr') {
                    if ($active->expires_at !== null && $active->expires_at->isPast()) {
                        $active->update(['status' => 'expired']);

                        continue;
                    }

                    if ($active->qr_string && $active->qr_image) {
                        if ($reusable === null) {
                            $reusable = $active;
                        } else {
                            $active->update(['status' => 'expired']);
                        }

                        continue;
                    }

                    return ['error' => 'qr_pending', 'payment' => $active];
                }

                if (
                    $active->created_at !== null
                    && $active->created_at->greaterThan(now()->subMinutes(15))
                ) {
                    return ['error' => 'active_payment', 'payment' => $active];
                }

                $active->update(['status' => 'failed']);
            }

            if ($reusable) {
                return ['order' => $order, 'payment' => $reusable, 'reused' => true];
            }

            $payment = Payment::create([
                'order_id' => $order->id,
                'provider' => 'aba_payway',
                'provider_transaction_id' => Payment::generateAbaTransactionId(),
                'payment_method' => 'aba_qr',
                'payment_mode' => $mode,
                'amount' => $order->total_amount,
                'currency' => $order->currency,
                'status' => 'pending',
                'expires_at' => now()->addMinutes(
                    max(3, (int) config('services.aba_payway.qr_lifetime_minutes', 15))
                ),
            ]);

            $order->update(['payment_status' => 'pending']);

            return ['order' => $order, 'payment' => $payment, 'reused' => false];
        });

        if (isset($attempt['error'])) {
            if ($attempt['error'] === 'active_payment') {
                return response()->json([
                    'success' => false,
                    'message' => 'An ABA PayWay payment is already in progress.',
                    'data' => [
                        'payment' => $presenter->qr($attempt['payment']),
                    ],
                ], 409);
            }

            return match ($attempt['error']) {
                'not_found' => response()->json([
                    'success' => false,
                    'message' => 'Order not found.',
                ], 404),
                'already_paid' => response()->json([
                    'success' => false,
                    'message' => 'This order has already been paid.',
                ], 409),
                'invalid_order' => response()->json([
                    'success' => false,
                    'message' => 'This order cannot be paid.',
                ], 409),
                'invalid_amount' => response()->json([
                    'success' => false,
                    'message' => 'This order total or currency is not supported by ABA PayWay.',
                ], 409),
                'qr_pending' => response()->json([
                    'success' => false,
                    'message' => 'The ABA PayWay QR is being prepared. Please retry in a moment.',
                    'data' => ['payment' => $presenter->qr($attempt['payment'])],
                ], 409),
                default => response()->json([
                    'success' => false,
                    'message' => 'Unable to create an ABA PayWay QR payment.',
                ], 409),
            };
        }

        /** @var Order $order */
        $order = $attempt['order'];
        /** @var Payment $payment */
        $payment = $attempt['payment'];

        if ($attempt['reused']) {
            return response()->json([
                'success' => true,
                'message' => 'ABA PayWay QR is ready.',
                'data' => [
                    'payment' => $presenter->qr($payment),
                ],
            ]);
        }

        try {
            $result = $gateway->createQrPayment($order, $payment);

            $payment->update([
                'qr_string' => $result['qr_string'],
                'qr_image' => $result['qr_image'],
                'provider_response' => [
                    'request' => $result['request'] ?? [],
                    'response' => $result['response'] ?? [],
                    'result' => [
                        'abapay_deeplink' => $result['abapay_deeplink'] ?? null,
                        'app_store' => $result['app_store'] ?? null,
                        'play_store' => $result['play_store'] ?? null,
                    ],
                ],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'ABA PayWay QR is ready.',
                'data' => [
                    'payment' => $presenter->qr($payment->fresh()),
                ],
            ], 201);
        } catch (Throwable $exception) {
            Log::error('ABA PayWay QR creation failed.', [
                'payment_id' => $payment->id,
                'mode' => $mode,
                'exception' => $exception->getMessage(),
            ]);

            DB::transaction(function () use ($payment) {
                $lockedPayment = Payment::whereKey($payment->id)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedPayment || $lockedPayment->status === 'paid') {
                    return;
                }

                $lockedPayment->update(['status' => 'failed']);

                Order::whereKey($lockedPayment->order_id)
                    ->where('payment_status', 'pending')
                    ->update(['payment_status' => 'failed']);
            });

            $notConfigured = $exception instanceof RuntimeException
                && str_contains($exception->getMessage(), 'not configured');

            return response()->json([
                'success' => false,
                'message' => $notConfigured
                    ? 'ABA PayWay is not configured.'
                    : 'Unable to create an ABA PayWay QR payment. Please retry.',
            ], $notConfigured ? 503 : 502);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 6. GET ABA PAYWAY QR PAYMENT STATUS
    |--------------------------------------------------------------------------
    */

    public function status(
        Request $request,
        int $paymentId,
        PaymentVerificationService $verification,
        PaymentPresenter $presenter
    ): JsonResponse {
        $payment = $this->findAbaPayment($request, $paymentId);

        if (! $payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found.',
            ], 404);
        }

        $providerStatus = $this->providerStatusFor($payment->status);

        if (! in_array($payment->status, ['paid', 'failed', 'cancelled', 'expired', 'refunded'], true)) {
            try {
                $result = $verification->verify($payment);
                $payment = $result['payment'];
                $providerStatus = $result['provider_status'];
            } catch (Throwable $exception) {
                Log::warning('ABA PayWay QR status check failed.', [
                    'payment_id' => $payment->id,
                    'exception' => $exception->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Unable to check ABA PayWay status. Please retry.',
                    'data' => [
                        'payment' => $presenter->qr($payment->fresh()),
                    ],
                ], 502);
            }
        }

        return response()->json([
            'success' => true,
            'message' => $this->messageForStatus($payment->status),
            'data' => [
                'payment' => $presenter->qr($payment, $providerStatus),
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 7. CANCEL ABA PAYWAY QR PAYMENT
    |--------------------------------------------------------------------------
    */

    public function cancel(
        Request $request,
        int $paymentId,
        AbaPaymentSimulator $simulator,
        AbaPayWayService $abaPayWay,
        PaymentVerificationService $verification,
        PaymentReconciler $reconciler,
        PaymentPresenter $presenter
    ): JsonResponse {
        $payment = $this->findAbaPayment($request, $paymentId);

        if (! $payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found.',
            ], 404);
        }

        if ($payment->status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'This payment has already been paid.',
                'data' => ['payment' => $presenter->qr($payment)],
            ], 409);
        }

        if (in_array($payment->status, ['failed', 'cancelled', 'expired', 'refunded'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This payment can no longer be cancelled.',
                'data' => ['payment' => $presenter->qr($payment)],
            ], 409);
        }

        if (($payment->payment_mode ?: 'sandbox') === 'mock') {
            try {
                $simulator->simulate($payment, 'cancelled');
                $result = $verification->verify($payment);
                $payment = $result['payment'];
                $providerStatus = $result['provider_status'];
            } catch (RuntimeException $exception) {
                return response()->json([
                    'success' => false,
                    'message' => 'This payment can no longer be cancelled.',
                    'data' => ['payment' => $presenter->qr($payment->fresh())],
                ], 409);
            } catch (Throwable $exception) {
                Log::error('Mock ABA PayWay cancellation failed.', [
                    'payment_id' => $payment->id,
                    'exception' => $exception->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Unable to cancel this payment. Please retry.',
                ], 502);
            }
        } else {
            try {
                $check = $verification->verify($payment);
                $payment = $check['payment'];

                if ($payment->status === 'paid') {
                    return response()->json([
                        'success' => false,
                        'message' => 'This payment has already been paid.',
                        'data' => ['payment' => $presenter->qr($payment)],
                    ], 409);
                }

                if (in_array($payment->status, ['failed', 'cancelled', 'expired', 'refunded'], true)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This payment can no longer be cancelled.',
                        'data' => ['payment' => $presenter->qr($payment)],
                    ], 409);
                }

                $response = $abaPayWay->closeTransaction((string) $payment->provider_transaction_id);
                $statusCode = (string) data_get($response, 'status.code', '');

                if (! in_array($statusCode, ['0', '00'], true)) {
                    Log::warning('ABA PayWay did not close the transaction.', [
                        'payment_id' => $payment->id,
                        'provider_status' => $response['status'] ?? null,
                    ]);

                    throw new RuntimeException('ABA PayWay did not close the transaction.');
                }

                $payment = $reconciler->apply($payment->fresh(), [
                    'provider_status' => 'CANCELLED',
                    'amount' => null,
                    'currency' => null,
                    'transaction_id' => $payment->provider_transaction_id,
                    'raw' => [
                        'closed' => true,
                        'status' => $response['status'] ?? null,
                    ],
                ]);
                $providerStatus = 'CANCELLED';
            } catch (Throwable $exception) {
                Log::error('ABA PayWay cancellation failed.', [
                    'payment_id' => $payment->id,
                    'exception' => $exception->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Unable to cancel this payment with ABA PayWay. Please retry.',
                    'data' => ['payment' => $presenter->qr($payment->fresh())],
                ], 502);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment cancelled.',
            'data' => [
                'payment' => $presenter->qr($payment->fresh(), $providerStatus),
            ],
        ]);
    }

    private function findAbaPayment(Request $request, int $paymentId): ?Payment
    {
        return Payment::with('order')
            ->whereKey($paymentId)
            ->where('provider', 'aba_payway')
            ->whereHas('order', fn ($query) => $query->where('user_id', $request->user()->id))
            ->first();
    }

    private function providerStatusFor(string $status): string
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

    private function messageForStatus(string $status): string
    {
        return match ($status) {
            'paid' => 'Payment successful.',
            'failed' => 'Payment failed.',
            'cancelled' => 'Payment cancelled.',
            'expired' => 'Payment expired.',
            'refunded' => 'Payment refunded.',
            default => 'Payment is awaiting confirmation.',
        };
    }
}
