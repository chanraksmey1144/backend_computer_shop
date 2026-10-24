<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductVariantController extends Controller
{
    /**
     * Display a listing of the resource.
     */
   public function index(string $product)
    {
        $productModel = Product::findOrFail($product);

        $variants = $productModel->variants()
            ->orderByDesc('id')
            ->paginate(10);

        return response()->json([
            'success' => true,
            'message' => 'Product variants retrieved successfully.',
            'data' => $variants,
        ], 200);
    }

    /**
     * POST /api/products/{product}/variants
     * Create a variant for a product.
     */
    public function store(Request $request, string $product)
    {
        $productModel = Product::findOrFail($product);

        $validated = $request->validate([
            'sku' => [
                'required',
                'string',
                'max:100',
                'unique:product_variants,sku',
            ],

            'name' => [
                'required',
                'string',
                'max:200',
            ],

            'attributes' => [
                'nullable',
                'array',
            ],

            'price' => [
                'required',
                'numeric',
                'min:0',
            ],

            'cost_price' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'status' => [
                'sometimes',
                Rule::in(['active', 'inactive']),
            ],
        ]);

        $variant = $productModel->variants()->create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Product variant created successfully.',
            'data' => $variant,
        ], 201);
    }

    /**
     * GET /api/products/{product}/variants/{variant}
     * Show one variant.
     */
    public function show(string $product, string $variant)
    {
        $productModel = Product::findOrFail($product);

        $variantModel = $productModel->variants()
            ->whereKey($variant)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'message' => 'Product variant retrieved successfully.',
            'data' => $variantModel,
        ], 200);
    }

    /**
     * PATCH /api/products/{product}/variants/{variant}
     * Update a variant.
     */
    public function update(
        Request $request,
        string $product,
        string $variant
    ) {
        $productModel = Product::findOrFail($product);

        $variantModel = $productModel->variants()
            ->whereKey($variant)
            ->firstOrFail();

        $validated = $request->validate([
            'sku' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('product_variants', 'sku')
                    ->ignore($variantModel->id),
            ],

            'name' => [
                'sometimes',
                'required',
                'string',
                'max:200',
            ],

            'attributes' => [
                'sometimes',
                'nullable',
                'array',
            ],

            'price' => [
                'sometimes',
                'required',
                'numeric',
                'min:0',
            ],

            'cost_price' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
            ],

            'status' => [
                'sometimes',
                Rule::in(['active', 'inactive']),
            ],
        ]);

        $variantModel->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Product variant updated successfully.',
            'data' => $variantModel->fresh(),
        ], 200);
    }

    /**
     * DELETE /api/products/{product}/variants/{variant}
     * Delete a variant.
     */
    public function destroy(string $product, string $variant)
    {
        $productModel = Product::findOrFail($product);

        $variantModel = $productModel->variants()
            ->whereKey($variant)
            ->firstOrFail();

        $variantModel->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product variant deleted successfully.',
        ], 200);
    }
}
