<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
}
