<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CartItemController extends Controller
{
    // 1. Get all items in authenticated user's cart
    public function index(Request $request)
    {
        $cart = $request->user()->cart()->first();

        if (!$cart) {
            return response()->json([
                'success' => true,
                'message' => 'Your cart is empty.',
                'data' => []
            ], 200);
        }

        $items = $cart->items()
            ->with(['product', 'variant'])
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $items
        ], 200);
    }

    // 2. Add product to cart
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'variant_id' => 'nullable|exists:product_variants,id',
            'quantity' => 'required|integer|min:1',
        ]);

        // Check variant belongs to product
        if (!empty($validated['variant_id'])) {

            $variantExists = DB::table('product_variants')
                ->where('id', $validated['variant_id'])
                ->where('product_id', $validated['product_id'])
                ->exists();

            if (!$variantExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'This variant does not belong to this product.'
                ], 422);
            }
        }

        $cart = $request->user()->cart()->firstOrCreate([]);

        $item = DB::transaction(function () use ($cart, $validated) {

            $query = $cart->items()
                ->where('product_id', $validated['product_id']);

            if ($validated['variant_id'] === null) {
                $query->whereNull('variant_id');
            } else {
                $query->where('variant_id', $validated['variant_id']);
            }

            $item = $query->lockForUpdate()->first();

            if ($item) {
                $item->quantity += $validated['quantity'];
                $item->save();
            } else {
                $item = $cart->items()->create($validated);
            }

            return $item;
        });

        return response()->json([
            'success' => true,
            'message' => 'Product added to cart successfully.',
            'data' => $item->load(['product', 'variant'])
        ], 201);
    }

    // 3. Update cart item quantity
    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $cart = $request->user()->cart()->first();

        if (!$cart) {
            return response()->json([
                'success' => false,
                'message' => 'Cart not found.'
            ], 404);
        }

        $item = $cart->items()->find($id);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Cart item not found.'
            ], 404);
        }

        $item->update([
            'quantity' => $validated['quantity']
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Cart item updated successfully.',
            'data' => $item->load(['product', 'variant'])
        ], 200);
    }

    // 4. Remove one product from cart
    public function destroy(Request $request, int $id)
    {
        $cart = $request->user()->cart()->first();

        if (!$cart) {
            return response()->json([
                'success' => false,
                'message' => 'Cart not found.'
            ], 404);
        }

        $item = $cart->items()->find($id);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Cart item not found.'
            ], 404);
        }

        $item->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product removed from cart successfully.'
        ], 200);
    }

    // 5. Clear all items from cart
    public function clear(Request $request)
    {
        $cart = $request->user()->cart()->first();

        if (!$cart) {
            return response()->json([
                'success' => true,
                'message' => 'Cart is already empty.'
            ], 200);
        }

        $cart->items()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Cart cleared successfully.'
        ], 200);
    }
}
