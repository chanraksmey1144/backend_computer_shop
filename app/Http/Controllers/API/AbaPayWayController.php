<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Service\Payments\AbaPayWayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

class AbaPayWayController extends Controller
{
    private const CHECKOUT_TTL_MINUTES = 15;

    public function __construct(private AbaPayWayService $abaPayWay) {}

    public function createPayment(Request $request, int $orderId)
    {
        $order = $request->user()->orders()->find($orderId);

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        $attempt = DB::transaction(function () use ($orderId, $request) {
            $order = Order::whereKey($orderId)
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                return ['error' => 'not_found'];
            }

            if ($order->payment_status === 'paid') {
                return ['error' => 'already_paid'];
            }

            if (in_array($order->status, ['cancelled', 'refunded'], true)) {
                return ['error' => 'invalid_order'];
            }

            if ((float) $order->total_amount <= 0 || ! in_array(strtoupper($order->currency), ['USD', 'KHR'], true)) {
                return ['error' => 'invalid_amount'];
            }

            Payment::where('order_id', $order->id)
                ->where('provider', 'aba_payway')
                ->whereIn('status', ['pending', 'processing'])
                ->whereNull('provider_transaction_id')
                ->update(['status' => 'failed']);

            $existing = Payment::where('order_id', $order->id)
                ->where('provider', 'aba_payway')
                ->whereIn('status', ['pending', 'processing'])
                ->whereNotNull('provider_transaction_id')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return ['error' => 'active_payment', 'payment' => $existing];
            }

            $payment = Payment::create([
                'order_id' => $order->id,
                'provider' => 'aba_payway',
                'provider_transaction_id' => $this->newTransactionId(),
                'payment_method' => 'online_checkout',
                'amount' => $order->total_amount,
                'currency' => $order->currency,
                'status' => 'pending',
            ]);

            $order->update(['payment_status' => 'pending']);

            return ['order' => $order, 'payment' => $payment];
        });

        if (isset($attempt['error'])) {
            if ($attempt['error'] === 'active_payment') {
                $activePayment = $attempt['payment'];
                $cachedHtml = Cache::get($this->checkoutCacheKey($activePayment->id));

                if (is_string($cachedHtml) && $cachedHtml !== '') {
                    $expiresAt = now()->addMinutes(self::CHECKOUT_TTL_MINUTES);
                    Cache::put($this->checkoutCacheKey($activePayment->id), $cachedHtml, $expiresAt);

                    return response()->json([
                        'success' => true,
                        'message' => 'ABA PayWay checkout is ready.',
                        'data' => [
                            'payment' => $this->paymentData($activePayment),
                            'checkout_url' => URL::temporarySignedRoute(
                                'payway.checkout',
                                $expiresAt,
                                ['payment' => $activePayment->id]
                            ),
                        ],
                    ]);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'An ABA PayWay payment is already in progress.',
                    'data' => ['payment' => $this->paymentData($activePayment)],
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
                default => response()->json([
                    'success' => false,
                    'message' => 'An ABA PayWay payment is already in progress.',
                    'data' => ['payment' => $this->paymentData($attempt['payment'])],
                ], 409),
            };
        }

        /** @var Order $order */
        $order = $attempt['order']->load('items');
        /** @var Payment $payment */
        $payment = $attempt['payment'];
        $user = $request->user();
        $nameParts = preg_split('/\s+/', trim((string) $user->name), 2) ?: [];
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $successUrl = $frontendUrl.'/payment/result?payment_id='.$payment->id;
        $cancelUrl = $frontendUrl.'/payment/result?payment_id='.$payment->id.'&result=cancelled';
        $callbackUrl = config('services.aba_payway.callback_url') ?: route('payway.callback');

        try {
            $result = $this->abaPayWay->createPayment([
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'tran_id' => $payment->provider_transaction_id,
                'amount' => $payment->amount,
                'shipping' => $order->delivery_fee,
                'currency' => $payment->currency,
                'firstname' => $nameParts[0] ?? 'Customer',
                'lastname' => $nameParts[1] ?? '',
                'email' => $user->email,
                'phone' => $order->recipient_phone ?: $user->phone,
                'items' => $order->items->map(fn ($item) => [
                    'name' => $item->product_name,
                    'quantity' => (int) $item->quantity,
                    'price' => number_format((float) $item->unit_price, 2, '.', ''),
                ])->values()->all(),
                'return_url' => $callbackUrl,
                'continue_success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
            ]);

            $expiresAt = now()->addMinutes(self::CHECKOUT_TTL_MINUTES);
            Cache::put($this->checkoutCacheKey($payment->id), $result['checkout_html'], $expiresAt);
            $checkoutUrl = URL::temporarySignedRoute(
                'payway.checkout',
                $expiresAt,
                ['payment' => $payment->id]
            );

            $payment->update([
                'status' => 'processing',
                'provider_response' => ['request' => $result['request_data']],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'ABA PayWay checkout is ready.',
                'data' => [
                    'payment' => $this->paymentData($payment->fresh()),
                    'checkout_url' => $checkoutUrl,
                ],
            ], 201);
        } catch (Throwable $exception) {
            Log::error('ABA PayWay checkout creation failed.', [
                'payment_id' => $payment->id,
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

            return response()->json([
                'success' => false,
                'message' => 'Unable to start ABA PayWay checkout. Please retry.',
            ], 502);
        }
    }

    public function checkPayment(Request $request, int $paymentId)
    {
        $payment = Payment::whereKey($paymentId)
            ->where('provider', 'aba_payway')
            ->whereHas('order', fn ($query) => $query->where('user_id', $request->user()->id))
            ->first();

        if (! $payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found.',
            ], 404);
        }

        if ($payment->status === 'paid') {
            return response()->json([
                'success' => true,
                'data' => ['payment' => $this->paymentData($payment)],
            ]);
        }

        try {
            $result = $this->abaPayWay->checkTransaction($payment->provider_transaction_id);
            $gatewayData = $result['data'] ?? [];
            $gatewayStatus = strtoupper((string) ($gatewayData['payment_status'] ?? 'PENDING'));
            $approved = $gatewayStatus === 'APPROVED'
                && (string) ($gatewayData['payment_status_code'] ?? '') === '0';

            if ($approved) {
                if (
                    ! $this->moneyMatches($gatewayData['total_amount'] ?? null, $payment->amount)
                    || strtoupper((string) ($gatewayData['payment_currency'] ?? '')) !== strtoupper($payment->currency)
                ) {
                    Log::warning('ABA PayWay amount or currency mismatch.', [
                        'payment_id' => $payment->id,
                        'gateway_transaction_id' => $payment->provider_transaction_id,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Payment details could not be verified.',
                    ], 409);
                }

                $this->applyPaymentResult($payment, 'paid', $gatewayData);
            } elseif (in_array($gatewayStatus, ['DECLINED', 'CANCELLED'], true)) {
                $this->applyPaymentResult(
                    $payment,
                    $gatewayStatus === 'CANCELLED' ? 'cancelled' : 'failed',
                    $gatewayData
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'ABA PayWay status retrieved successfully.',
                'data' => [
                    'gateway_status' => $gatewayStatus,
                    'payment' => $this->paymentData($payment->fresh()),
                ],
            ]);
        } catch (Throwable $exception) {
            Log::error('ABA PayWay status check failed.', [
                'payment_id' => $payment->id,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to check ABA PayWay status. Please try again.',
            ], 502);
        }
    }

    public function callback(Request $request)
    {
        if (! $request->isJson()) {
            return response()->json(['success' => false, 'message' => 'JSON is required.'], 415);
        }

        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload) || empty($payload['tran_id'])) {
            return response()->json(['success' => false, 'message' => 'Invalid callback payload.'], 422);
        }

        try {
            $signature = (string) $request->header('X-PayWay-Hmac-Sha512', '');

            if ($signature === '' || ! $this->abaPayWay->isValidCallbackSignature($payload, $signature)) {
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

        if ((string) ($payload['status'] ?? '') === '0') {
            if (
                ! $this->moneyMatches($payload['total_amount'] ?? null, $payment->amount)
                || strtoupper((string) ($payload['payment_currency'] ?? '')) !== strtoupper($payment->currency)
            ) {
                Log::warning('ABA PayWay callback amount or currency mismatch.', [
                    'payment_id' => $payment->id,
                ]);

                return response()->json(['success' => false, 'message' => 'Payment details could not be verified.'], 409);
            }

            $this->applyPaymentResult($payment, 'paid', $payload);
        } else {
            $this->applyPaymentResult($payment, 'failed', $payload);
        }

        return response()->json(['success' => true, 'message' => 'Callback received.']);
    }

    public function checkoutPage(int $payment)
    {
        $paymentRecord = Payment::whereKey($payment)
            ->where('provider', 'aba_payway')
            ->first();
        $html = Cache::get($this->checkoutCacheKey($payment));

        if (
            ! $paymentRecord
            || in_array($paymentRecord->status, ['paid', 'failed', 'cancelled', 'refunded'], true)
            || ! is_string($html)
            || $html === ''
        ) {
            return response('This ABA PayWay checkout has expired. Return to the shop and try again.', 410)
                ->header('Content-Type', 'text/plain; charset=UTF-8')
                ->header('Cache-Control', 'no-store');
        }

        return response($html)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header(
                'Content-Security-Policy',
                'sandbox allow-scripts allow-forms allow-top-navigation allow-popups; '.
                "default-src 'none'; script-src 'unsafe-inline' https:; ".
                "style-src 'unsafe-inline' https:; img-src https: data:; ".
                'connect-src https: wss:; font-src https: data:; '.
                "frame-src https:; form-action https:; base-uri 'none'"
            );
    }

    private function applyPaymentResult(Payment $payment, string $status, array $providerData): void
    {
        DB::transaction(function () use ($payment, $status, $providerData) {
            $lockedPayment = Payment::whereKey($payment->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedPayment || $lockedPayment->status === 'paid') {
                return;
            }

            $order = Order::whereKey($lockedPayment->order_id)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                return;
            }

            $providerResponse = (array) $lockedPayment->provider_response;
            $providerResponse['result'] = array_intersect_key($providerData, array_flip([
                'status',
                'payment_status',
                'payment_status_code',
                'total_amount',
                'payment_currency',
                'apv',
                'bank_ref',
                'payment_type',
                'transaction_date',
            ]));

            $lockedPayment->update([
                'status' => $status,
                'paid_at' => $status === 'paid' ? now() : null,
                'provider_response' => $providerResponse,
            ]);

            if ($status === 'paid') {
                $order->update([
                    'payment_status' => 'paid',
                    'status' => $order->status === 'pending' ? 'confirmed' : $order->status,
                ]);
            } elseif ($order->payment_status !== 'paid') {
                $order->update(['payment_status' => 'failed']);
            }
        });

        Cache::forget($this->checkoutCacheKey($payment->id));
    }

    private function paymentData(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'order_id' => $payment->order_id,
            'transaction_id' => $payment->provider_transaction_id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'status' => $payment->status,
            'paid_at' => $payment->paid_at,
        ];
    }

    private function moneyMatches(mixed $actual, mixed $expected): bool
    {
        return is_numeric($actual)
            && number_format((float) $actual, 2, '.', '')
                === number_format((float) $expected, 2, '.', '');
    }

    private function checkoutCacheKey(int $paymentId): string
    {
        return 'aba-payway-checkout:'.$paymentId;
    }

    private function newTransactionId(): string
    {
        return 'ABA'.now()->format('ymdHis').strtoupper(bin2hex(random_bytes(2)));
    }
}
