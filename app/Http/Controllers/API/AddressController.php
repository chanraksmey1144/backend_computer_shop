<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AddressController extends Controller
{
    private function resolveUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /**
     * GET /api/addresses
     * Get addresses of logged-in user.
     */
    public function index(Request $request)
    {
        $addresses = $this->resolveUser($request)
            ->addresses()
            ->orderByDesc('is_default')
            ->latest()
            ->paginate(10);

        return response()->json([
            'success' => true,
            'message' => 'Addresses retrieved successfully.',
            'data' => $addresses,
        ], 200);
    }

    /**
     * POST /api/addresses
     * Create a new address.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'recipient_name' => [
                'required',
                'string',
                'max:150',
            ],

            'phone' => [
                'required',
                'string',
                'max:30',
            ],

            'province' => [
                'required',
                'string',
                'max:120',
            ],

            'district' => [
                'nullable',
                'string',
                'max:120',
            ],

            'commune' => [
                'nullable',
                'string',
                'max:120',
            ],

            'village' => [
                'nullable',
                'string',
                'max:120',
            ],

            'address_line' => [
                'required',
                'string',
                'max:500',
            ],

            'delivery_note' => [
                'nullable',
                'string',
                'max:500',
            ],

            'is_default' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $user = $this->resolveUser($request);

        $isDefault = $validated['is_default'] ?? false;

        $address = DB::transaction(function () use (
            $user,
            $validated,
            $isDefault
        ) {
            // Lock the user's address records while changing default.
            $user->addresses()->lockForUpdate()->get();

            // First address becomes default automatically.
            $makeDefault = $isDefault ||
                ! $user->addresses()->exists();

            if ($makeDefault) {
                $user->addresses()->update([
                    'is_default' => false,
                ]);
            }

            $validated['is_default'] = $makeDefault;

            return $user->addresses()->create($validated);
        });

        return response()->json([
            'success' => true,
            'message' => 'Address created successfully.',
            'data' => $address,
        ], 201);
    }

    /**
     * GET /api/addresses/{address}
     * Get one address belonging to logged-in user.
     */
    public function show(Request $request, string $address)
    {
        $addressModel = $this->resolveUser($request)
            ->addresses()
            ->findOrFail($address);

        return response()->json([
            'success' => true,
            'message' => 'Address retrieved successfully.',
            'data' => $addressModel,
        ], 200);
    }

    /**
     * PATCH /api/addresses/{address}
     * Update an address.
     */
    public function update(
        Request $request,
        string $address
    ) {
        $user = $this->resolveUser($request);

        $addressModel = $user->addresses()->findOrFail($address);

        $validated = $request->validate([
            'recipient_name' => [
                'sometimes',
                'required',
                'string',
                'max:150',
            ],

            'phone' => [
                'sometimes',
                'required',
                'string',
                'max:30',
            ],

            'province' => [
                'sometimes',
                'required',
                'string',
                'max:120',
            ],

            'district' => [
                'sometimes',
                'nullable',
                'string',
                'max:120',
            ],

            'commune' => [
                'sometimes',
                'nullable',
                'string',
                'max:120',
            ],

            'village' => [
                'sometimes',
                'nullable',
                'string',
                'max:120',
            ],

            'address_line' => [
                'sometimes',
                'required',
                'string',
                'max:500',
            ],

            'delivery_note' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
            ],

            'is_default' => [
                'sometimes',
                'boolean',
            ],
        ]);

        DB::transaction(function () use (
            $user,
            $addressModel,
            $validated
        ) {
            $user->addresses()->lockForUpdate()->get();

            if (($validated['is_default'] ?? false) === true) {
                $user->addresses()->update([
                    'is_default' => false,
                ]);
            }

            $addressModel->update($validated);
        });

        return response()->json([
            'success' => true,
            'message' => 'Address updated successfully.',
            'data' => $addressModel->fresh(),
        ], 200);
    }

    /**
     * PATCH /api/addresses/{address}/default
     * Set default address.
     */
    public function setDefault(
        Request $request,
        string $address
    ) {
        $user = $this->resolveUser($request);

        $addressModel = $user->addresses()->findOrFail($address);

        DB::transaction(function () use ($user, $addressModel) {

            $user->addresses()->lockForUpdate()->get();

            $user->addresses()->update([
                'is_default' => false,
            ]);

            $addressModel->update([
                'is_default' => true,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Default address updated successfully.',
            'data' => $addressModel->fresh(),
        ], 200);
    }

    /**
     * DELETE /api/addresses/{address}
     * Delete an address.
     */
    public function destroy(
        Request $request,
        string $address
    ) {
        $user = $this->resolveUser($request);
        $addressModel = $user
            ->addresses()
            ->findOrFail($address);

        $wasDefault = $addressModel->is_default;

        DB::transaction(function () use (
            $user,
            $addressModel,
            $wasDefault
        ) {
            $user->addresses()->lockForUpdate()->get();

            $addressModel->delete();

            // Choose another address as default if needed.
            if ($wasDefault) {
                $nextAddress = $user->addresses()
                    ->oldest('id')
                    ->first();

                if ($nextAddress) {
                    $nextAddress->update([
                        'is_default' => true,
                    ]);
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Address deleted successfully.',
        ], 200);
    }
}
