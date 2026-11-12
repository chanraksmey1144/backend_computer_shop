<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductImageController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(string $product)
    {
        $productModel = Product::findOrFail($product);

        $images = $productModel->images()
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

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
            'image' => [
                'required_without:image_url',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
            'image_url' => [
                'required_without:image',
                'nullable',
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
        $storedPath = null;

        if ($request->hasFile('image')) {
            $storedPath = $request->file('image')->storePublicly('products', 'public');
            if (!$storedPath) {
                throw new \RuntimeException('The product image could not be stored.');
            }

            $validated['image_url'] = Storage::disk('public')->url($storedPath);
            $validated['storage_path'] = $storedPath;
            $validated['cloudinary_public_id'] = null;
            unset($validated['image']);
        }

        try {
            $image = DB::transaction(function () use ($productModel, &$validated) {
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
                $validated['sort_order'] ??= (int) $productModel->images()->max('sort_order') + 1;

                return $productModel->images()->create($validated);
            });
        } catch (\Throwable $error) {
            if ($storedPath) {
                Storage::disk('public')->delete($storedPath);
            }

            throw $error;
        }

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
            'image' => [
                'sometimes',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
            'image_url' => [
                'sometimes',
                'required_without:image',
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

        $previousStoragePath = $imageModel->storage_path;
        $storedPath = null;

        if ($request->hasFile('image')) {
            $storedPath = $request->file('image')->storePublicly('products', 'public');
            if (!$storedPath) {
                throw new \RuntimeException('The product image could not be stored.');
            }

            $validated['image_url'] = Storage::disk('public')->url($storedPath);
            $validated['storage_path'] = $storedPath;
            $validated['cloudinary_public_id'] = null;
            unset($validated['image']);
        } elseif (array_key_exists('image_url', $validated)) {
            $validated['storage_path'] = null;
        }

        try {
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
        } catch (\Throwable $error) {
            if ($storedPath) {
                Storage::disk('public')->delete($storedPath);
            }

            throw $error;
        }

        if ($previousStoragePath && $previousStoragePath !== $updatedImage->storage_path) {
            Storage::disk('public')->delete($previousStoragePath);
        }

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

        $wasPrimary = $imageModel->is_primary;
        $storagePath = $imageModel->storage_path;

        DB::transaction(function () use ($productModel, $imageModel, $wasPrimary) {
            $imageModel->delete();

            if ($wasPrimary) {
                $nextPrimary = $productModel->images()
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->first();

                $nextPrimary?->update(['is_primary' => true]);
            }
        });

        if ($storagePath) {
            Storage::disk('public')->delete($storagePath);
        }

        return response()->json([
            'success' => true,
            'message' => 'Product image deleted successfully.',
        ], 200);
    }
}
