<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class BrandController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Brand::query();

        // Search by brand name
        if ($request->filled('search')) {
            $query->where(
                'name',
                'like',
                '%' . $request->search . '%'
            );
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $brands = $query
            ->orderBy('id', 'desc')
            ->paginate(10);

        return response()->json([
            'success' => true,
            'message' => 'Brands retrieved successfully.',
            'data' => $brands,
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:brands,name'],
            'slug' => ['nullable', 'string', 'max:150', 'unique:brands,slug'],
            'logo_url' => ['nullable', 'url', 'max:1000'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = $this->uniqueSlug($validated['name']);
        }

        $brand = Brand::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Brand created successfully.',
            'data' => $brand,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
       $brand = Brand::findOrFail($id);

        return response()->json([
            'success' => true,
            'message' => 'Brand retrieved successfully.',
            'data' => $brand,
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $brand = Brand::findOrFail($id);

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:120',
                Rule::unique('brands', 'name')->ignore($brand->id),
            ],
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:150',
                Rule::unique('brands', 'slug')->ignore($brand->id),
            ],
            'logo_url' => [
                'sometimes',
                'nullable',
                'url',
                'max:1000',
            ],
            'description' => [
                'sometimes',
                'nullable',
                'string',
            ],
            'status' => [
                'sometimes',
                Rule::in(['active', 'inactive']),
            ],
        ]);

        if (array_key_exists('slug', $validated) && empty($validated['slug'])) {
            $slugSource = $validated['name'] ?? $brand->name;
            $validated['slug'] = $this->uniqueSlug($slugSource, $brand->id);
        } elseif (! array_key_exists('slug', $validated) && array_key_exists('name', $validated)) {
            $validated['slug'] = $this->uniqueSlug($validated['name'], $brand->id);
        }

        $brand->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Brand updated successfully.',
            'data' => $brand,
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $brand = Brand::findOrFail($id);
        $brand->delete();

        return response()->json([
            'success' => true,
            'message' => 'Brand deleted successfully.',
        ], 200);
    }

    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($source);
        $slug = $baseSlug;
        $counter = 1;

        while (Brand::where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}
