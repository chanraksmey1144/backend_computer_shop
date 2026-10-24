<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
   /**
     * GET /api/products
     * List products with search, filtering, and pagination.
     */
    public function index(Request $request)
    {
        $query = Product::with(['category', 'brand']);

        // Search by product name
        if ($request->filled('search')) {
            $search = $request->input('search');

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        // Filter by category
        if ($request->filled('category_id')) {
            $query->where(
                'category_id',
                $request->input('category_id')
            );
        }

        // Filter by brand
        if ($request->filled('brand_id')) {
            $query->where(
                'brand_id',
                $request->input('brand_id')
            );
        }

        // Filter by product type
        if ($request->filled('product_type')) {
            $query->where(
                'product_type',
                $request->input('product_type')
            );
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where(
                'status',
                $request->input('status')
            );
        }

        $products = $query
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        return response()->json([
            'success' => true,
            'message' => 'Products retrieved successfully.',
            'data' => $products,
        ], 200);
    }

    /**
     * POST /api/products
     * Create a new product.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id' => [
                'required',
                'integer',
                'exists:categories,id',
            ],

            'brand_id' => [
                'nullable',
                'integer',
                'exists:brands,id',
            ],

            'name' => [
                'required',
                'string',
                'max:200',
            ],

            'slug' => [
                'nullable',
                'string',
                'max:220',
                'unique:products,slug',
            ],

            'sku' => [
                'required',
                'string',
                'max:100',
                'unique:products,sku',
            ],

            'short_description' => [
                'nullable',
                'string',
                'max:500',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'product_type' => [
                'sometimes',
                Rule::in([
                    'laptop',
                    'desktop',
                    'monitor',
                    'component',
                    'accessory',
                    'other',
                ]),
            ],

            'price' => [
                'required',
                'numeric',
                'min:0',
            ],

            'compare_at_price' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'cost_price' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'currency' => [
                'sometimes',
                'required',
                'string',
                'size:3',
            ],

            'warranty_months' => [
                'sometimes',
                'integer',
                'min:0',
                'max:65535',
            ],

            'specifications' => [
                'nullable',
                'array',
            ],

            'track_inventory' => [
                'sometimes',
                'boolean',
            ],

            'allow_backorder' => [
                'sometimes',
                'boolean',
            ],

            'status' => [
                'sometimes',
                Rule::in(['draft', 'active', 'inactive']),
            ],
        ]);

        // Generate a slug if the client did not provide one.
        $slug = Str::slug($validated['slug'] ?? $validated['name']);

        // Ensure the generated slug is unique.
        $baseSlug = $slug;
        $counter = 1;

        while (Product::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        $validated['slug'] = $slug;

        $product = Product::create($validated);

        $product->load(['category', 'brand']);

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully.',
            'data' => $product,
        ], 201);
    }

    /**
     * GET /api/products/{id}
     * Show one product.
     */
    public function show(string $id)
    {
        $product = Product::with(['category', 'brand'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'message' => 'Product retrieved successfully.',
            'data' => $product,
        ], 200);
    }

    /**
     * PATCH/PUT /api/products/{id}
     * Update a product.
     */
    public function update(Request $request, string $id)
    {
        $product = Product::findOrFail($id);

        $validated = $request->validate([
            'category_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:categories,id',
            ],

            'brand_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:brands,id',
            ],

            'name' => [
                'sometimes',
                'required',
                'string',
                'max:200',
            ],

            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:220',
                Rule::unique('products', 'slug')->ignore($product->id),
            ],

            'sku' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('products', 'sku')->ignore($product->id),
            ],

            'short_description' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
            ],

            'description' => [
                'sometimes',
                'nullable',
                'string',
            ],

            'product_type' => [
                'sometimes',
                Rule::in([
                    'laptop',
                    'desktop',
                    'monitor',
                    'component',
                    'accessory',
                    'other',
                ]),
            ],

            'price' => [
                'sometimes',
                'required',
                'numeric',
                'min:0',
            ],

            'compare_at_price' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
            ],

            'cost_price' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
            ],

            'currency' => [
                'sometimes',
                'required',
                'string',
                'size:3',
            ],

            'warranty_months' => [
                'sometimes',
                'integer',
                'min:0',
                'max:65535',
            ],

            'specifications' => [
                'sometimes',
                'nullable',
                'array',
            ],

            'track_inventory' => [
                'sometimes',
                'boolean',
            ],

            'allow_backorder' => [
                'sometimes',
                'boolean',
            ],

            'status' => [
                'sometimes',
                Rule::in(['draft', 'active', 'inactive']),
            ],
        ]);

        // If the name changes without a slug, generate a new slug.
        if (
            array_key_exists('name', $validated)
            && !array_key_exists('slug', $validated)
        ) {
            $baseSlug = Str::slug($validated['name']);
            $slug = $baseSlug;
            $counter = 1;

            while (
                Product::where('slug', $slug)
                    ->where('id', '!=', $product->id)
                    ->exists()
            ) {
                $slug = $baseSlug . '-' . $counter;
                $counter++;
            }

            $validated['slug'] = $slug;
        }

        $product->update($validated);

        $product->load(['category', 'brand']);

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully.',
            'data' => $product,
        ], 200);
    }

    /**
     * DELETE /api/products/{id}
     * Delete a product.
     */
    public function destroy(string $id)
    {
        $product = Product::findOrFail($id);

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully.',
        ], 200);
    }
}
