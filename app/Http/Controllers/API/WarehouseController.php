<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseController extends Controller
{
  /**
     * GET /api/warehouses
     * Display all warehouses.
     */
    public function index(Request $request)
    {
        $query = Warehouse::query();

        // Search by name or code
        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $warehouses = $query
            ->orderByDesc('id')
            ->paginate(10);

        return response()->json([
            'success' => true,
            'message' => 'Warehouses retrieved successfully.',
            'data' => $warehouses,
        ], 200);
    }

    /**
     * POST /api/warehouses
     * Create a warehouse.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:50', 'unique:warehouses,code'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $warehouse = Warehouse::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Warehouse created successfully.',
            'data' => $warehouse,
        ], 201);
    }

    /**
     * GET /api/warehouses/{warehouse}
     * Display one warehouse.
     */
    public function show(string $warehouse)
    {
        $warehouseModel = Warehouse::findOrFail($warehouse);

        return response()->json([
            'success' => true,
            'message' => 'Warehouse retrieved successfully.',
            'data' => $warehouseModel,
        ], 200);
    }

    /**
     * PUT/PATCH /api/warehouses/{warehouse}
     * Update a warehouse.
     */
    public function update(Request $request, string $warehouse)
    {
        $warehouseModel = Warehouse::findOrFail($warehouse);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('warehouses', 'code')
                    ->ignore($warehouseModel->id),
            ],
            'address' => ['sometimes', 'nullable', 'string'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $warehouseModel->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Warehouse updated successfully.',
            'data' => $warehouseModel->fresh(),
        ], 200);
    }

    /**
     * DELETE /api/warehouses/{warehouse}
     * Delete a warehouse.
     */
    public function destroy(string $warehouse)
    {
        $warehouseModel = Warehouse::findOrFail($warehouse);

        $warehouseModel->delete();

        return response()->json([
            'success' => true,
            'message' => 'Warehouse deleted successfully.',
        ], 200);
    }
}
