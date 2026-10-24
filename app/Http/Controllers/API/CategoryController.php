<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request) { 

        $query = Category::with(['parent', 'children']); 

        // Filter by status. 
        if ($request->filled('status')) { 
            $query->where('status', $request->status); 
        } 
        // Search by category name. 
        if ($request->filled('search')) { 
            $query->where( 'name', 'like', '%' . $request->search . '%' ); 
        } 
        // Filter root categories. 
        if ($request->boolean('root_only')) { 
            $query->whereNull('parent_id'); 
        } 
        
        $categories = $query ->orderBy('id', 'desc') ->paginate(10); 
            return response()->json([ 
                 'success' => true, 
                 'message' => 'Categories retrieved successfully', 
                 'data' => $categories, 
            ], 200); 
    }
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'parent_id' => 'nullable|exists:categories,id',
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:categories,slug',
            'description' => 'nullable|string',
            'image' => 'nullable|string|max:255',
            'status' => 'required|in:active,inactive',
        ]);

        $category = Category::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Category created successfully',
            'data' => $category
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $category = Category::with(['parent', 'children'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'message' => 'Category retrieved successfully',
            'data' => $category
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $category = Category::findOrFail($id);

        $validated = $request->validate([
            'parent_id' => 'sometimes|nullable|exists:categories,id',
            'name' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|required|string|max:255|unique:categories,slug,' . $category->id,
            'description' => 'sometimes|nullable|string',
            'image' => 'sometimes|nullable|string|max:255',
            'status' => 'sometimes|required|in:active,inactive',
        ]);

        $category->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Category updated successfully',
            'data' => $category
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $category = Category::findOrFail($id);
        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully'
        ], 200);
    }
}
