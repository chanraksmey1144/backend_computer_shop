<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ProductSerialNumber;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductSerialNumberController extends Controller
{
   /**
     * GET /api/product-serial-numbers
     * Get all serial numbers.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'product_id' => [
                'sometimes',
                'integer',
                'exists:products,id',
            ],

            'warehouse_id' => [
                'sometimes',
                'integer',
                'exists:warehouses,id',
            ],

            'status' => [
                'sometimes',
                Rule::in([
                    'in_stock',
                    'reserved',
                    'sold',
                    'returned',
                    'repair',
                    'written_off',
                ]),
            ],
                       'search' => [
                'sometimes',
                'string',
                'max:150',
            ],

            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $query = ProductSerialNumber::with([
            'product:id,name,sku',
            'variant:id,product_id,name,sku',
            'warehouse:id,name,code',
        ]);

        if (isset($validated['product_id'])) {
            $query->where(
                'product_id',
                $validated['product_id']
            );
        }

        if (isset($validated['warehouse_id'])) {
            $query->where(
                'warehouse_id',
                $validated['warehouse_id']
            );
        }

        if (isset($validated['status'])) {
            $query->where(
                'status',
                $validated['status']
            );
        }

        if (isset($validated['search'])) {
            $query->where(
                'serial_number',
                'like',
                '%' . $validated['search'] . '%'
            );
        }

        $serialNumbers = $query
            ->latest('id')
            ->paginate($validated['per_page'] ?? 10);

        return response()->json([
            'success' => true,
            'message' => 'Serial numbers retrieved successfully.',
            'data' => $serialNumbers,
        ], 200);
    }

    /**
     * POST /api/product-serial-numbers
     * Create a serial number.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
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

            'warehouse_id' => [
                'nullable',
                'integer',
                'exists:warehouses,id',
            ],

            'serial_number' => [
                'required',
                'string',
                'max:150',
                'unique:product_serial_numbers,serial_number',
            ],

            'status' => [
                'sometimes',
                Rule::in([
                    'in_stock',
                    'reserved',
                    'sold',
                    'returned',
                    'repair',
                    'written_off',
                ]),
            ],

            'order_item_id' => [
                'nullable',
                'integer',
                'min:1',
            ],
        ]);

        // Ensure variant belongs to the selected product.
        if (!empty($validated['variant_id'])) {

            $variantBelongsToProduct =
                \App\Models\ProductVariant::where(
                    'id',
                    $validated['variant_id']
                )
                ->where(
                    'product_id',
                    $validated['product_id']
                )
                ->exists();

            if (!$variantBelongsToProduct) {
                throw ValidationException::withMessages([
                    'variant_id' => [
                        'The selected variant does not belong to this product.',
                    ],
                ]);
            }
        }

        $serialNumber = ProductSerialNumber::create($validated);

        $serialNumber->load([
            'product:id,name,sku',
            'variant:id,product_id,name,sku',
            'warehouse:id,name,code',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Serial number created successfully.',
            'data' => $serialNumber,
        ], 201);
    }

    /**
     * GET /api/product-serial-numbers/{serialNumber}
     * Get one serial number.
     */
    public function show(string $serialNumber)
    {
        $serial = ProductSerialNumber::with([
            'product:id,name,sku',
            'variant:id,product_id,name,sku',
            'warehouse:id,name,code',
        ])->findOrFail($serialNumber);

        return response()->json([
            'success' => true,
            'message' => 'Serial number retrieved successfully.',
            'data' => $serial,
        ], 200);
    }

    /**
     * PATCH /api/product-serial-numbers/{serialNumber}
     * Update serial number.
     */
    public function update(Request $request, string $serialNumber)
    {
        $serial = ProductSerialNumber::findOrFail($serialNumber);

        $validated = $request->validate([
            'product_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:products,id',
            ],

            'variant_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:product_variants,id',
            ],

            'warehouse_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:warehouses,id',
            ],

            'serial_number' => [
                'sometimes',
                'required',
                'string',
                'max:150',
                Rule::unique(
                    'product_serial_numbers',
                    'serial_number'
                )->ignore($serial->id),
            ],

            'status' => [
                'sometimes',
                Rule::in([
                    'in_stock',
                    'reserved',
                    'sold',
                    'returned',
                    'repair',
                    'written_off',
                ]),
            ],

            'order_item_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
            ],
        ]);

        $productId = $validated['product_id'] ?? $serial->product_id;
        $variantId = array_key_exists('variant_id', $validated)
            ? $validated['variant_id']
            : $serial->variant_id;

        // Validate variant and product relationship.
        if ($variantId !== null) {

            $variantBelongsToProduct =
                \App\Models\ProductVariant::where(
                    'id',
                    $variantId
                )
                ->where(
                    'product_id',
                    $productId
                )
                ->exists();

            if (!$variantBelongsToProduct) {
                throw ValidationException::withMessages([
                    'variant_id' => [
                        'The selected variant does not belong to this product.',
                    ],
                ]);
            }
        }

        $serial->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Serial number updated successfully.',
            'data' => $serial->fresh()->load([
                'product:id,name,sku',
                'variant:id,product_id,name,sku',
                'warehouse:id,name,code',
            ]),
        ], 200);
    }

    /**
     * DELETE /api/product-serial-numbers/{serialNumber}
     * Delete a serial number.
     */
    public function destroy(string $serialNumber)
    {
        $serial = ProductSerialNumber::findOrFail($serialNumber);

        // Do not delete sold or reserved devices.
        if (in_array($serial->status, ['sold', 'reserved'])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a sold or reserved serial number.',
            ], 422);
        }

        $serial->delete();

        return response()->json([
            'success' => true,
            'message' => 'Serial number deleted successfully.',
        ], 200);
    }
}
