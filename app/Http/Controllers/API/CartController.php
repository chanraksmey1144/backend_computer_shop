<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    public function index(Request $request)
    {
        $cart = $request->user()
            ->cart()
            ->with(['items.product.images', 'items.variant'])
            ->first();

        return response()->json([
            'success' => true,
            'message' => $cart ? 'Cart retrieved successfully.' : 'Cart is empty.',
            'data' => $cart ? $this->cartData($cart) : $this->emptyCartData(),
        ]);
    }

    public function store(Request $request)
    {
        $relation = $request->user()->cart();
        $cart = $relation->firstOrCreate();
        $created = $cart->wasRecentlyCreated;
        $cart->load(['items.product.images', 'items.variant']);

        return response()->json([
            'success' => true,
            'message' => $created ? 'Cart created successfully.' : 'Cart already exists.',
            'data' => $this->cartData($cart),
        ], $created ? 201 : 200);
    }

    public function addItem(Request $request)
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ]);
        $validated['quantity'] = $validated['quantity'] ?? 1;

        if (!empty($validated['variant_id'])) {
            $variantMatchesProduct = ProductVariant::query()
                ->whereKey($validated['variant_id'])
                ->where('product_id', $validated['product_id'])
                ->exists();

            if (!$variantMatchesProduct) {
                throw ValidationException::withMessages([
                    'variant_id' => ['The selected variant does not belong to this product.'],
                ]);
            }
        }

        $user = $request->user();
        $cart = $user->cart()->firstOrCreate();
        $itemQuery = $cart->items()->where('product_id', $validated['product_id']);

        if (!empty($validated['variant_id'])) {
            $itemQuery->where('variant_id', $validated['variant_id']);
        } else {
            $itemQuery->whereNull('variant_id');
        }

        $item = $itemQuery->first();

        if ($item) {
            $item->quantity += $validated['quantity'];
            $item->save();
        } else {
            $cart->items()->create([
                'product_id' => $validated['product_id'],
                'variant_id' => $validated['variant_id'] ?? null,
                'quantity' => $validated['quantity'],
            ]);
        }

        $cart->load(['items.product.images', 'items.variant']);

        return response()->json([
            'success' => true,
            'message' => 'Item added to cart.',
            'data' => $this->cartData($cart),
        ], 201);
    }

    public function updateItem(Request $request, int $item)
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        $cart = $request->user()->cart()->first();
        $cartItem = $cart?->items()->whereKey($item)->first();

        if (!$cartItem) {
            return response()->json([
                'success' => false,
                'message' => 'Cart item not found.',
            ], 404);
        }

        $cartItem->update(['quantity' => $validated['quantity']]);
        $cart->load(['items.product.images', 'items.variant']);

        return response()->json([
            'success' => true,
            'message' => 'Cart item updated.',
            'data' => $this->cartData($cart),
        ]);
    }

    public function removeItem(Request $request, int $item)
    {
        $cart = $request->user()->cart()->first();
        $cartItem = $cart?->items()->whereKey($item)->first();

        if (!$cartItem) {
            return response()->json([
                'success' => false,
                'message' => 'Cart item not found.',
            ], 404);
        }

        $cartItem->delete();
        $cart->load(['items.product.images', 'items.variant']);

        return response()->json([
            'success' => true,
            'message' => 'Cart item removed.',
            'data' => $this->cartData($cart),
        ]);
    }

    public function destroy(Request $request)
    {
        $cart = $request->user()->cart()->first();

        if (!$cart) {
            return response()->json([
                'success' => true,
                'message' => 'Cart is already empty.',
                'data' => $this->emptyCartData(),
            ]);
        }

        $cart->delete();

        return response()->json([
            'success' => true,
            'message' => 'Cart cleared successfully.',
            'data' => $this->emptyCartData(),
        ]);
    }

    private function cartData(Cart $cart): array
    {
        $items = $cart->items->map(function (CartItem $item) {
            $product = $item->product;
            $image = $product?->images->firstWhere('is_primary', true)
                ?? $product?->images->sortBy('sort_order')->first();
            $unitPrice = (float) ($item->variant?->price ?? $product?->price ?? 0);

            return [
                'id' => $item->id,
                'cart_id' => $item->cart_id,
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'quantity' => $item->quantity,
                'unit_price' => $unitPrice,
                'product' => $product ? [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'price' => (float) $product->price,
                    'image_url' => $image?->image_url,
                ] : null,
                'variant' => $item->variant ? [
                    'id' => $item->variant->id,
                    'sku' => $item->variant->sku,
                    'name' => $item->variant->name,
                    'price' => (float) $item->variant->price,
                ] : null,
            ];
        })->values();

        $subtotal = $items->sum(fn (array $item) => $item['unit_price'] * $item['quantity']);

        return [
            'id' => $cart->id,
            'user_id' => $cart->user_id,
            'items' => $items,
            'item_count' => $items->sum('quantity'),
            'subtotal' => round($subtotal, 2),
            'discount' => 0,
            'delivery_fee' => 0,
            'total' => round($subtotal, 2),
        ];
    }

    private function emptyCartData(): array
    {
        return [
            'id' => null,
            'items' => [],
            'item_count' => 0,
            'subtotal' => 0,
            'discount' => 0,
            'delivery_fee' => 0,
            'total' => 0,
        ];
    }
}
