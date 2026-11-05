<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Coupon;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
   public function index(Request $request)
    {
        $orders = $request->user()
            ->orders()
            ->with('items')
            ->latest()
            ->paginate(10);

        return response()->json([
            'success' => true,
            'message' => 'Orders retrieved successfully.',
            'data' => $orders
        ], 200);
    }


    /*
    |--------------------------------------------------------------------------
    | 2. CREATE ORDER
    |--------------------------------------------------------------------------
    | Create order and order items from customer's cart.
    */

    public function store(Request $request)
    {
        // Validate request
        $validated = $request->validate([

            'address_id' => [
                'nullable',
                'integer',
                'exists:addresses,id'
            ],

            'recipient_name' => [
                'required_without:address_id',
                'string',
                'max:150'
            ],

            'recipient_phone' => [
                'required_without:address_id',
                'string',
                'max:30'
            ],

            'shipping_address' => [
                'required_without:address_id',
                'string'
            ],

            'coupon_code' => [
                'nullable',
                'string',
                'max:80'
            ],

            'customer_note' => [
                'nullable',
                'string',
                'max:1000'
            ],

        ]);

        $user = $request->user();

        // Start database transaction
        $order = DB::transaction(function () use ($validated, $user) {

            /*
            |--------------------------------------------------------------------------
            | A. GET CUSTOMER CART
            |--------------------------------------------------------------------------
            */

            $cart = $user->cart()
                ->lockForUpdate()
                ->first();

            if (!$cart) {
                throw ValidationException::withMessages([
                    'cart' => ['Your cart does not exist.']
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | B. GET CART ITEMS
            |--------------------------------------------------------------------------
            */

            $cartItems = $cart->items()
                ->with(['product', 'variant'])
                ->lockForUpdate()
                ->get();

            if ($cartItems->isEmpty()) {
                throw ValidationException::withMessages([
                    'cart' => ['Your cart is empty.']
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | C. CALCULATE SUBTOTAL
            |--------------------------------------------------------------------------
            */

            $subtotal = 0;

            $orderItemsData = [];

            foreach ($cartItems as $item) {

                $product = $item->product;

                // Check product exists
                if (!$product) {
                    throw ValidationException::withMessages([
                        'product' => ['Product not found.']
                    ]);
                }

                // Check product status
                if ($product->status !== 'active') {
                    throw ValidationException::withMessages([
                        'product' => [
                            $product->name . ' is unavailable.'
                        ]
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | GET PRODUCT OR VARIANT INFORMATION
                |--------------------------------------------------------------------------
                */

                if ($item->variant_id !== null) {

                    $variant = $item->variant;

                    if (!$variant) {
                        throw ValidationException::withMessages([
                            'variant' => ['Product variant not found.']
                        ]);
                    }

                    if (
                        (int) $variant->product_id !==
                        (int) $product->id
                    ) {
                        throw ValidationException::withMessages([
                            'variant' => [
                                'Variant does not belong to this product.'
                            ]
                        ]);
                    }

                    if ($variant->status !== 'active') {
                        throw ValidationException::withMessages([
                            'variant' => [
                                'Product variant is inactive.'
                            ]
                        ]);
                    }

                    $unitPrice = (float) $variant->price;

                    // Assumes product_variants has sku and name columns
                    $sku = $variant->sku;

                    $productName = $product->name . ' - ' . $variant->name;

                } else {

                    $unitPrice = (float) $product->price;

                    $sku = $product->sku;

                    $productName = $product->name;
                }

                $quantity = (int) $item->quantity;

                if ($quantity < 1) {
                    throw ValidationException::withMessages([
                        'quantity' => ['Quantity must be at least 1.']
                    ]);
                }

                // Calculate item subtotal
                $lineSubtotal = round(
                    $unitPrice * $quantity,
                    2
                );

                $subtotal += $lineSubtotal;

                // Temporarily save item data
                $orderItemsData[] = [

                    'product_id' => $product->id,

                    'variant_id' => $item->variant_id,

                    'product_name' => $productName,

                    'sku' => $sku,

                    'quantity' => $quantity,

                    'unit_price' => $unitPrice,

                    'line_subtotal' => $lineSubtotal,

                ];
            }

            $subtotal = round($subtotal, 2);

            /*
            |--------------------------------------------------------------------------
            | D. GET SHIPPING ADDRESS
            |--------------------------------------------------------------------------
            */

            $addressId = null;

            $recipientName = $validated['recipient_name'] ?? null;

            $recipientPhone = $validated['recipient_phone'] ?? null;

            $shippingAddress = $validated['shipping_address'] ?? null;

            if (!empty($validated['address_id'])) {

                // Only allow customer's own address
                $address = Address::where('id', $validated['address_id'])
                    ->where('user_id', $user->id)
                    ->first();

                if (!$address) {
                    throw ValidationException::withMessages([
                        'address_id' => [
                            'This address does not belong to you.'
                        ]
                    ]);
                }

                $addressId = $address->id;

                $recipientName = $address->recipient_name;

                $recipientPhone = $address->phone;

                // Save address snapshot
                $shippingAddress = implode(', ', array_filter([

                    $address->address_line,

                    $address->village,

                    $address->commune,

                    $address->district,

                    $address->province,

                ]));
            }

            /*
            |--------------------------------------------------------------------------
            | E. APPLY COUPON
            |--------------------------------------------------------------------------
            */

            $coupon = null;

            $discount = 0;

            if (!empty($validated['coupon_code'])) {

                $coupon = Coupon::where(
                    'code',
                    Str::upper(trim($validated['coupon_code']))
                )
                    ->lockForUpdate()
                    ->first();

                if (!$coupon) {
                    throw ValidationException::withMessages([
                        'coupon_code' => ['Invalid coupon code.']
                    ]);
                }

                $now = now();

                // Check coupon status
                if ($coupon->status !== 'active') {
                    throw ValidationException::withMessages([
                        'coupon_code' => ['Coupon is inactive.']
                    ]);
                }

                // Check start date
                if (
                    $coupon->starts_at &&
                    $now->lt($coupon->starts_at)
                ) {
                    throw ValidationException::withMessages([
                        'coupon_code' => [
                            'Coupon is not available yet.'
                        ]
                    ]);
                }

                // Check expiration date
                if (
                    $coupon->expires_at &&
                    $now->gt($coupon->expires_at)
                ) {
                    throw ValidationException::withMessages([
                        'coupon_code' => ['Coupon has expired.']
                    ]);
                }

                // Check usage limit
                if (
                    $coupon->usage_limit !== null &&
                    $coupon->usage_count >= $coupon->usage_limit
                ) {
                    throw ValidationException::withMessages([
                        'coupon_code' => [
                            'Coupon usage limit reached.'
                        ]
                    ]);
                }

                // Check minimum order amount
                if (
                    $subtotal <
                    (float) $coupon->minimum_order_amount
                ) {
                    throw ValidationException::withMessages([
                        'coupon_code' => [
                            'Order amount does not meet minimum requirement.'
                        ]
                    ]);
                }

                // Calculate percentage discount
                if ($coupon->discount_type === 'percentage') {

                    $discount = $subtotal *
                        ((float) $coupon->discount_value / 100);

                    // Apply maximum discount
                    if ($coupon->maximum_discount_amount !== null) {

                        $discount = min(
                            $discount,
                            (float) $coupon->maximum_discount_amount
                        );
                    }

                } else {

                    // Fixed discount
                    $discount = (float) $coupon->discount_value;
                }

                // Discount cannot exceed subtotal
                $discount = min($discount, $subtotal);

                $discount = round($discount, 2);

                // Increase coupon usage count
                $coupon->increment('usage_count');
            }

            /*
            |--------------------------------------------------------------------------
            | F. DELIVERY FEE AND TAX
            |--------------------------------------------------------------------------
            */

            // Change according to your business rules
            $deliveryFee = 0;

            $taxAmount = 0;

            /*
            |--------------------------------------------------------------------------
            | G. CALCULATE FINAL TOTAL
            |--------------------------------------------------------------------------
            */

            $totalAmount = round(
                $subtotal - $discount + $deliveryFee + $taxAmount,
                2
            );

            /*
            |--------------------------------------------------------------------------
            | H. GENERATE ORDER NUMBER
            |--------------------------------------------------------------------------
            */

            $orderNumber = 'ORD-' .
                now()->format('YmdHis') .
                '-' .
                Str::upper(Str::random(6));

            /*
            |--------------------------------------------------------------------------
            | I. CREATE ORDER
            |--------------------------------------------------------------------------
            */

            $order = Order::create([

                'order_number' => $orderNumber,

                'user_id' => $user->id,

                'address_id' => $addressId,

                'coupon_id' => $coupon?->id,

                'status' => 'pending',

                'payment_status' => 'unpaid',

                'currency' => 'USD',

                'subtotal' => $subtotal,

                'discount_amount' => $discount,

                'delivery_fee' => $deliveryFee,

                'tax_amount' => $taxAmount,

                'total_amount' => $totalAmount,

                'recipient_name' => $recipientName,

                'recipient_phone' => $recipientPhone,

                'shipping_address' => $shippingAddress,

                'customer_note' => $validated['customer_note'] ?? null,

                'placed_at' => now(),

            ]);

            /*
            |--------------------------------------------------------------------------
            | J. CREATE ORDER ITEMS
            |--------------------------------------------------------------------------
            */

            $allocatedDiscount = 0;

            $lastIndex = count($orderItemsData) - 1;

            foreach ($orderItemsData as $index => $itemData) {

                /*
                 * Allocate order discount proportionally
                 * to each order item.
                 */

                if ($discount > 0) {

                    if ($index === $lastIndex) {

                        // Give remaining discount to last item
                        $itemDiscount = round(
                            $discount - $allocatedDiscount,
                            2
                        );

                    } else {

                        $itemDiscount = round(
                            $discount *
                            ($itemData['line_subtotal'] / $subtotal),
                            2
                        );

                        $allocatedDiscount += $itemDiscount;
                    }

                } else {

                    $itemDiscount = 0;
                }

                $lineTotal = round(
                    $itemData['line_subtotal'] - $itemDiscount,
                    2
                );

                $order->items()->create([

                    'product_id' => $itemData['product_id'],

                    'variant_id' => $itemData['variant_id'],

                    'product_name' => $itemData['product_name'],

                    'sku' => $itemData['sku'],

                    'quantity' => $itemData['quantity'],

                    'unit_price' => $itemData['unit_price'],

                    'discount_amount' => $itemDiscount,

                    'line_total' => $lineTotal,

                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | K. RETURN ORDER WITH ITEMS
            |--------------------------------------------------------------------------
            */

            return $order->load([
                'items.product',
                'items.variant',
                'coupon'
            ]);

        });

        /*
        |--------------------------------------------------------------------------
        | RETURN SUCCESS RESPONSE
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'success' => true,
            'message' => 'Order created successfully.',
            'data' => $order
        ], 201);
    }


    /*
    |--------------------------------------------------------------------------
    | 3. GET ORDER DETAIL
    |--------------------------------------------------------------------------
    | Customer can only see their own order.
    */

    public function show(Request $request,int $id)
    {
        $order = $request->user()
            ->orders()
            ->with([
                'items.product',
                'items.variant',
                'items.serialNumbers',
                'coupon',
                'address'
            ])
            ->find($id);

        if (!$order) {

            return response()->json([
                'success' => false,
                'message' => 'Order not found.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Order retrieved successfully.',
            'data' => $order
        ], 200);
    }


    /*
    |--------------------------------------------------------------------------
    | 4. CANCEL ORDER
    |--------------------------------------------------------------------------
    | Customer can cancel pending or confirmed orders.
    */

    public function cancel(Request $request,int $id)
    {
        $order = DB::transaction(function () use ($request, $id) {

            $order = $request->user()
                ->orders()
                ->lockForUpdate()
                ->find($id);

            if (!$order) {
                return null;
            }

            // Check order status
            if (!in_array($order->status, [
                'pending',
                'confirmed'
            ])) {

                throw ValidationException::withMessages([
                    'order' => [
                        'This order cannot be cancelled.'
                    ]
                ]);
            }

            // Do not directly cancel an already paid order
            if (in_array($order->payment_status, [
                'paid',
                'partially_refunded'
            ])) {

                throw ValidationException::withMessages([
                    'payment_status' => [
                        'Paid orders require a refund process before cancellation.'
                    ]
                ]);
            }

            $order->update([

                'status' => 'cancelled',

                'cancelled_at' => now(),

            ]);

            return $order;
        });

        if (!$order) {

            return response()->json([
                'success' => false,
                'message' => 'Order not found.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Order cancelled successfully.',
            'data' => $order
        ], 200);
    }
}
