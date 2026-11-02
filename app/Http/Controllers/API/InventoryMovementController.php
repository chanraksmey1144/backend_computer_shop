<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryMovementController extends Controller
{
   /**
     * GET /api/inventory-movements
     * Display inventory movement history.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => [
                'sometimes',
                'integer',
                'exists:warehouses,id',
            ],
            'product_id' => [
                'sometimes',
                'integer',
                'exists:products,id',
            ],
            'movement_type' => [
                'sometimes',
                'in:stock_in,stock_out,sale,return,adjustment,reservation,release',
            ],
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $query = InventoryMovement::with([
            'warehouse:id,name,code',
            'product:id,name,sku',
            'variant:id,product_id,name,sku',
            'user:id,name,email',
        ]);

        if (isset($validated['warehouse_id'])) {
            $query->where(
                'warehouse_id',
                $validated['warehouse_id']
            );
        }

        if (isset($validated['product_id'])) {
            $query->where(
                'product_id',
                $validated['product_id']
            );
        }

        if (isset($validated['movement_type'])) {
            $query->where(
                'movement_type',
                $validated['movement_type']
            );
        }

        $movements = $query
            ->latest('created_at')
            ->paginate($validated['per_page'] ?? 10);

        return response()->json([
            'success' => true,
            'message' => 'Inventory movements retrieved successfully.',
            'data' => $movements,
        ], 200);
    }

    /**
     * POST /api/inventory-movements
     * Create a movement and update inventory.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => [
                'required',
                'integer',
                'exists:warehouses,id',
            ],

            'product_id' => [
                'required',
                'integer',
                'exists:products,id',
            ],

            'variant_id' => [
                'nullable',
                'integer',
                'exists:product_variants,id',
            ],

            'movement_type' => [
                'required',
                'in:stock_in,stock_out,sale,return,adjustment,reservation,release',
            ],

            'quantity_change' => [
                'required',
                'integer',
                'not_in:0',
            ],

            'reference_type' => [
                'nullable',
                'string',
                'max:80',
            ],

            'reference_id' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'notes' => [
                'nullable',
                'string',
                'max:500',
            ],
        ]);

        $type = $validated['movement_type'];
        $change = $validated['quantity_change'];

        // Validate quantity sign.
        if (in_array($type, [
            'stock_in',
            'return',
            'reservation',
            'release',
        ]) && $change < 0) {
            throw ValidationException::withMessages([
                'quantity_change' => [
                    'This movement type requires a positive quantity.',
                ],
            ]);
        }

        if (in_array($type, [
            'stock_out',
            'sale',
        ]) && $change > 0) {
            throw ValidationException::withMessages([
                'quantity_change' => [
                    'This movement type requires a negative quantity.',
                ],
            ]);
        }

        if ($type === 'adjustment' && $change === 0) {
            throw ValidationException::withMessages([
                'quantity_change' => [
                    'Adjustment quantity cannot be zero.',
                ],
            ]);
        }

        // Make sure the variant belongs to the selected product.
        if (!empty($validated['variant_id'])) {
            $variantExists = DB::table('product_variants')
                ->where('id', $validated['variant_id'])
                ->where('product_id', $validated['product_id'])
                ->exists();

            if (!$variantExists) {
                throw ValidationException::withMessages([
                    'variant_id' => [
                        'The selected variant does not belong to this product.',
                    ],
                ]);
            }
        }

        return DB::transaction(function () use ($validated, $request, $type, $change) {

            // Find and lock the inventory record.
            $query = Inventory::where(
                'warehouse_id',
                $validated['warehouse_id']
            )->where(
                'product_id',
                $validated['product_id']
            );

            if (!empty($validated['variant_id'])) {
                $query->where(
                    'variant_id',
                    $validated['variant_id']
                );
            } else {
                $query->whereNull('variant_id');
            }

            $inventory = $query->lockForUpdate()->first();

            if (!$inventory) {
                throw ValidationException::withMessages([
                    'inventory' => [
                        'Inventory record does not exist. Create inventory first.',
                    ],
                ]);
            }

            // Update inventory based on movement type.
            switch ($type) {

                case 'stock_in':
                case 'return':
                    $inventory->quantity += $change;
                    break;

                case 'stock_out':
                case 'sale':
                    if (abs($change) > (
                        $inventory->quantity -
                        $inventory->reserved_quantity
                    )) {
                        throw ValidationException::withMessages([
                            'quantity_change' => [
                                'Insufficient available stock.',
                            ],
                        ]);
                    }

                    $inventory->quantity += $change;
                    break;

                case 'adjustment':
                    $newQuantity = $inventory->quantity + $change;

                    if ($newQuantity < $inventory->reserved_quantity) {
                        throw ValidationException::withMessages([
                            'quantity_change' => [
                                'Adjusted stock cannot be less than reserved quantity.',
                            ],
                        ]);
                    }

                    if ($newQuantity < 0) {
                        throw ValidationException::withMessages([
                            'quantity_change' => [
                                'Stock quantity cannot be negative.',
                            ],
                        ]);
                    }

                    $inventory->quantity = $newQuantity;
                    break;

                case 'reservation':
                    $available = $inventory->quantity -
                        $inventory->reserved_quantity;

                    if ($change > $available) {
                        throw ValidationException::withMessages([
                            'quantity_change' => [
                                'Insufficient available stock for reservation.',
                            ],
                        ]);
                    }

                    $inventory->reserved_quantity += $change;
                    break;

                case 'release':
                    if ($change > $inventory->reserved_quantity) {
                        throw ValidationException::withMessages([
                            'quantity_change' => [
                                'Release quantity exceeds reserved quantity.',
                            ],
                        ]);
                    }

                    $inventory->reserved_quantity -= $change;
                    break;
            }

            $inventory->save();

            // Create movement history.
            $movement = InventoryMovement::create([
                'warehouse_id' => $validated['warehouse_id'],
                'product_id' => $validated['product_id'],
                'variant_id' => $validated['variant_id'] ?? null,

                'user_id' => $request->user()?->id,

                'movement_type' => $type,
                'quantity_change' => $change,

                'reference_type' => $validated['reference_type'] ?? null,
                'reference_id' => $validated['reference_id'] ?? null,

                'notes' => $validated['notes'] ?? null,
            ]);

            $movement->load([
                'warehouse:id,name,code',
                'product:id,name,sku',
                'variant:id,product_id,name,sku',
                'user:id,name,email',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Inventory movement created successfully.',

                'data' => [
                    'movement' => $movement,

                    'inventory' => [
                        'quantity' => $inventory->quantity,

                        'reserved_quantity' => $inventory->reserved_quantity,

                        'available_quantity' =>
                            $inventory->quantity -
                            $inventory->reserved_quantity,

                        'reorder_level' => $inventory->reorder_level,
                    ],
                ],
            ], 201);
        });
    }

    /**
     * GET /api/inventory-movements/{movement}
     * Display one movement.
     */
    public function show(string $movement)
    {
        $movementModel = InventoryMovement::with([
            'warehouse:id,name,code',
            'product:id,name,sku',
            'variant:id,product_id,name,sku',
            'user:id,name,email',
        ])->findOrFail($movement);

        return response()->json([
            'success' => true,
            'message' => 'Inventory movement retrieved successfully.',
            'data' => $movementModel,
        ], 200);
    }
}
