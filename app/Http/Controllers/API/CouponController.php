<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CouponController extends Controller
{
  // 1. Get all coupons
public function index()
    {
        $coupons = Coupon::latest()->paginate(10);

        return response()->json([
            'success' => true,
            'data' => $coupons
        ], 200);
    }

    // 2. Create coupon
    public function store(Request $request)
    {
        $validated = $request->validate([

            'code' => 'required|string|max:80|unique:coupons,code',

            'description' => 'nullable|string|max:255',

            'discount_type' => 'required|in:fixed,percentage',

            'discount_value' => 'required|numeric|gt:0',

            'minimum_order_amount' => 'nullable|numeric|min:0',

            'maximum_discount_amount' => 'nullable|numeric|gt:0',

            'usage_limit' => 'nullable|integer|min:1',

            'starts_at' => 'nullable|date',

            'expires_at' => 'nullable|date|after:starts_at',

            'status' => 'nullable|in:active,inactive',
        ]);

        if (
            $validated['discount_type'] === 'percentage' &&
            $validated['discount_value'] > 100
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Percentage discount cannot exceed 100%.'
            ], 422);
        }

        $validated['code'] = Str::upper(trim($validated['code']));

        $validated['minimum_order_amount'] =
            $validated['minimum_order_amount'] ?? 0;

        $coupon = Coupon::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Coupon created successfully.',
            'data' => $coupon
        ], 201);
    }

    // 3. Get coupon by ID
    public function show( int $id)
    {
        $coupon = Coupon::find($id);

        if (!$coupon) {
            return response()->json([
                'success' => false,
                'message' => 'Coupon not found.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $coupon
        ], 200);
    }

    // 4. Update coupon
    public function update(Request $request, int $id)
    {
        $coupon = Coupon::find($id);

        if (!$coupon) {
            return response()->json([
                'success' => false,
                'message' => 'Coupon not found.'
            ], 404);
        }

        $validated = $request->validate([

            'code' => [
                'sometimes',
                'required',
                'string',
                'max:80',
                Rule::unique('coupons', 'code')->ignore($coupon->id)
            ],

            'description' => 'sometimes|nullable|string|max:255',

            'discount_type' => 'sometimes|required|in:fixed,percentage',

            'discount_value' => 'sometimes|required|numeric|gt:0',

            'minimum_order_amount' => 'sometimes|required|numeric|min:0',

            'maximum_discount_amount' => 'sometimes|nullable|numeric|gt:0',

            'usage_limit' => 'sometimes|nullable|integer|min:1',

            'starts_at' => 'sometimes|nullable|date',

            'expires_at' => 'sometimes|nullable|date|after:starts_at',

            'status' => 'sometimes|required|in:active,inactive',
        ]);

        $discountType = $validated['discount_type']
            ?? $coupon->discount_type;

        $discountValue = $validated['discount_value']
            ?? $coupon->discount_value;

        if (
            $discountType === 'percentage' &&
            $discountValue > 100
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Percentage discount cannot exceed 100%.'
            ], 422);
        }

        if (isset($validated['code'])) {
            $validated['code'] = Str::upper(trim($validated['code']));
        }

        $coupon->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Coupon updated successfully.',
            'data' => $coupon
        ], 200);
    }

    // 5. Delete coupon
    public function destroy(int $id)
    {
        $coupon = Coupon::find($id);

        if (!$coupon) {
            return response()->json([
                'success' => false,
                'message' => 'Coupon not found.'
            ], 404);
        }

        $coupon->delete();

        return response()->json([
            'success' => true,
            'message' => 'Coupon deleted successfully.'
        ], 200);
    }

    // 6. Validate coupon
    public function validateCoupon(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:80',
            'order_amount' => 'required|numeric|gt:0',
        ]);

        $coupon = Coupon::where(
            'code',
            Str::upper(trim($validated['code']))
        )->first();

        if (!$coupon) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid coupon code.'
            ], 404);
        }

        $now = Carbon::now();

        if ($coupon->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Coupon is inactive.'
            ], 422);
        }

        if ($coupon->starts_at && $now->lt($coupon->starts_at)) {
            return response()->json([
                'success' => false,
                'message' => 'Coupon is not available yet.'
            ], 422);
        }

        if ($coupon->expires_at && $now->gt($coupon->expires_at)) {
            return response()->json([
                'success' => false,
                'message' => 'Coupon has expired.'
            ], 422);
        }

        if (
            $coupon->usage_limit !== null &&
            $coupon->usage_count >= $coupon->usage_limit
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Coupon usage limit reached.'
            ], 422);
        }

        $orderAmount = (float) $validated['order_amount'];

        if ($orderAmount < (float) $coupon->minimum_order_amount) {
            return response()->json([
                'success' => false,
                'message' => 'Order amount does not meet minimum requirement.',
                'minimum_order_amount' => $coupon->minimum_order_amount
            ], 422);
        }

        // Calculate discount
        if ($coupon->discount_type === 'percentage') {

            $discount = $orderAmount *
                ((float) $coupon->discount_value / 100);

            if ($coupon->maximum_discount_amount !== null) {
                $discount = min(
                    $discount,
                    (float) $coupon->maximum_discount_amount
                );
            }

        } else {

            $discount = (float) $coupon->discount_value;
        }

        // Discount cannot exceed order amount
        $discount = min($discount, $orderAmount);

        $finalAmount = $orderAmount - $discount;

        return response()->json([
            'success' => true,
            'message' => 'Coupon applied successfully.',
            'data' => [
                'coupon_code' => $coupon->code,
                'discount_type' => $coupon->discount_type,
                'discount_value' => $coupon->discount_value,
                'order_amount' => number_format($orderAmount, 2, '.', ''),
                'discount_amount' => number_format($discount, 2, '.', ''),
                'final_amount' => number_format($finalAmount, 2, '.', '')
            ]
        ], 200);
    }
}
