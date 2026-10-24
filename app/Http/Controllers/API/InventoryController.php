<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    /**
     * GET /api/inventories
     * Display inventories with optional filters.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => ['sometimes', 'integer', 'exists:warehouses,id'],
            'product_id' => ['sometimes', 'integer', 'exists:products,id'],
            'variant_id' => ['sometimes', 'integer', 'exists:product_variants,id'],
            'low_stock' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Inventory::with([
            'warehouse:id,name,code',
            'product:id,name,sku',
            'variant:id,product_id,name,sku',
        ]);

        if (isset($validated['warehouse_id'])) {
            $query->where('warehouse_id', $validated['warehouse_id']);
        }

        if (isset($validated['product_id'])) {
            $query->where('product_id', $validated['product_id']);
        }

        if (isset($validated['variant_id'])) {
            $query->where('variant_id', $validated['variant_id']);
        }

        if (($validated['low_stock'] ?? false) === true
            || ($validated['low_stock'] ?? null) === '1') {
            $query->whereColumn('quantity', '<=', 'reorder_level');
        }

        $inventories = $query
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 10);

        return response()->json([
            'success' => true,
            'message' => 'Inventories retrieved successfully.',
            'data' => $inventories,
        ], 200);
    }

    /**
     * POST /api/inventories
     * Create an inventory record.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'variant_id' => [
                'nullable',
                'integer',
                'exists:product_variants,id',
                Rule::exists('product_variants', 'id')
                    ->where('product_id', $request->input('product_id')),
            ],
            'quantity' => ['sometimes', 'integer', 'min:0'],
            'reserved_quantity' => ['sometimes', 'integer', 'min:0'],
            'reorder_level' => ['sometimes', 'integer', 'min:0'],
        ]);

        if (
            isset($validated['variant_id'])
            && $validated['variant_id'] !== null
            && !DB::table('product_variants')
                ->where('id', $validated['variant_id'])
                ->where('product_id', $validated['product_id'])
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'variant_id' => [
                    'The selected variant does not belong to this product.',
                ],
            ]);
        }

        $quantity = $validated['quantity'] ?? 0;
        $reservedQuantity = $validated['reserved_quantity'] ?? 0;

        if ($reservedQuantity > $quantity) {
            throw ValidationException::withMessages([
                'reserved_quantity' => [
                    'Reserved quantity cannot exceed total quantity.',
                ],
            ]);
        }

        // Check for an existing inventory record.
        $existing = Inventory::where('warehouse_id', $validated['warehouse_id'])
            ->where('product_id', $validated['product_id'])
            ->when(
                array_key_exists('variant_id', $validated)
                    && $validated['variant_id'] !== null,
                fn ($query) => $query->where('variant_id', $validated['variant_id']),
                fn ($query) => $query->whereNull('variant_id')
            )
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'Inventory record already exists for this warehouse, product, and variant.',
            ], 409);
        }

        $inventory = Inventory::create($validated);

        $inventory->load([
            'warehouse:id,name,code',
            'product:id,name,sku',
            'variant:id,product_id,name,sku',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Inventory created successfully.',
            'data' => $inventory,
        ], 201);
    }

    /**
     * GET /api/inventories/{inventory}
     * Display one inventory record.
     */
    public function show(string $inventory)
    {
        $inventoryModel = Inventory::with([
            'warehouse:id,name,code',
            'product:id,name,sku',
            'variant:id,product_id,name,sku',
        ])->findOrFail($inventory);

        return response()->json([
            'success' => true,
            'message' => 'Inventory retrieved successfully.',
            'data' => $inventoryModel,
        ], 200);
    }

    /**
     * PATCH /api/inventories/{inventory}
     * Update inventory quantities and reorder level.
     */
    public function update(Request $request, string $inventory)
    {
        $inventoryModel = Inventory::findOrFail($inventory);

        $validated = $request->validate([
            'quantity' => ['sometimes', 'required', 'integer', 'min:0'],
            'reserved_quantity' => ['sometimes', 'required', 'integer', 'min:0'],
            'reorder_level' => ['sometimes', 'required', 'integer', 'min:0'],
        ]);

        $quantity = $validated['quantity'] ?? $inventoryModel->quantity;
        $reservedQuantity = $validated['reserved_quantity']
            ?? $inventoryModel->reserved_quantity;

        if ($reservedQuantity > $quantity) {
            throw ValidationException::withMessages([
                'reserved_quantity' => [
                    'Reserved quantity cannot exceed total quantity.',
                ],
            ]);
        }

        $inventoryModel->update($validated);

        $inventoryModel->load([
            'warehouse:id,name,code',
            'product:id,name,sku',
            'variant:id,product_id,name,sku',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Inventory updated successfully.',
            'data' => $inventoryModel->fresh()->load([
                'warehouse:id,name,code',
                'product:id,name,sku',
                'variant:id,product_id,name,sku',
            ]),
        ], 200);
    }

    /**
     * DELETE /api/inventories/{inventory}
     * Delete an inventory record.
     */
    public function destroy(string $inventory)
    {
        $inventoryModel = Inventory::findOrFail($inventory);

        $inventoryModel->delete();

        return response()->json([
            'success' => true,
            'message' => 'Inventory deleted successfully.',
        ], 200);
    }
}
