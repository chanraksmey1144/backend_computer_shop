<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductImageController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(string $product)
    {
        $productModel = Product::findOrFail($product);

        $images = $productModel->images()->get();

        return response()->json([
            'success' => true,
            'message' => 'Product images retrieved successfully.',
            'data' => $images,
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, string $product)
    {
        $productModel = Product::findOrFail($product);

        $validated = $request->validate([
            'image_url' => [
                'required',
                'url',
                'max:1000',
            ],
            'cloudinary_public_id' => [
                'nullable',
                'string',
                'max:255',
            ],
            'alt_text' => [
                'nullable',
                'string',
                'max:255',
            ],
            'is_primary' => [
                'sometimes',
                'boolean',
            ],
            'sort_order' => [
                'sometimes',
                'integer',
                'min:0',
                'max:4294967295',
            ],
        ]);
                $image = DB::transaction(function () use (
            $productModel,
            $validated
        ) {
            $isPrimary = $validated['is_primary'] ?? false;

            // If this is the first image, make it primary.
            $hasImages = $productModel->images()->exists();

            if (!$hasImages) {
                $isPrimary = true;
            }

            // Only one image should be primary per product.
            if ($isPrimary) {
                $productModel->images()
                    ->update(['is_primary' => false]);
            }

            $validated['is_primary'] = $isPrimary;

            return $productModel->images()->create($validated);
        });

        return response()->json([
            'success' => true,
            'message' => 'Product image created successfully.',
            'data' => $image,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $product, string $image)
    {
        $productModel = Product::findOrFail($product);
        $imageModel = $productModel->images()
            ->whereKey($image)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'message' => 'Product image retrieved successfully.',
            'data' => $imageModel,
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $product, string $image)
    {
        $productModel = Product::findOrFail($product);

        $imageModel = $productModel->images()
            ->whereKey($image)
            ->firstOrFail();

        $validated = $request->validate([
            'image_url' => [
                'sometimes',
                'required',
                'url',
                'max:1000',
            ],
            'cloudinary_public_id' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],
            'alt_text' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],
            'is_primary' => [
                'sometimes',
                'boolean',
            ],
                        'sort_order' => [
                'sometimes',
                'integer',
                'min:0',
                'max:4294967295',
            ],
        ]);

        $updatedImage = DB::transaction(function () use (
            $productModel,
            $imageModel,
            $validated
        ) {
            if (($validated['is_primary'] ?? false) === true) {
                $productModel->images()
                    ->whereKeyNot($imageModel->id)
                    ->update(['is_primary' => false]);
            }

            $imageModel->update($validated);

            return $imageModel->fresh();
        });
        return response()->json([
            'success' => true,
            'message' => 'Product image updated successfully.',
            'data' => $updatedImage,
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $product, string $image)
    {
        $productModel = Product::findOrFail($product);

        $imageModel = $productModel->images()
            ->whereKey($image)
            ->firstOrFail();

        $imageModel->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product image deleted successfully.',
        ], 200);
    }
}
