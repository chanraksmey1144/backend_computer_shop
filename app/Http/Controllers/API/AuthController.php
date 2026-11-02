<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Service\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function resetPassword(Request $request, OtpService $otpService)
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        DB::transaction(function () use ($validated, $otpService) {
            $userId = $otpService->consumeVerified(
                $validated['email'],
                'password_reset'
            );

            $user = User::whereKey($userId)
                ->where('email', $validated['email'])
                ->lockForUpdate()
                ->first();

            if (!$user) {
                throw ValidationException::withMessages([
                    'otp' => ['The reset code is invalid. Request a new code and try again.'],
                ]);
            }

            $user->forceFill(['password' => $validated['password']])->save();
            $user->tokens()->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Your password has been reset successfully.',
        ]);
    }

    public function register(Request $request)
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
            'phone' => $request->filled('phone')
                ? trim((string) $request->input('phone'))
                : null,
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $customerRole = Role::firstOrCreate(
            ['name' => 'customer'],
            ['description' => 'Customer account']
        );

        $user = User::create([
            'role_id' => $customerRole->id,
            'name' => trim($validated['name']),
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => $validated['password'],
            'status' => 'active',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Account created successfully.',
            'data' => $user->load('role'),
        ], 201);
    }

    public function login(Request $request)
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $user = User::with('role')
            ->where('email', $validated['email'])
            ->first();

        if (!$user || $user->status !== 'active' || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $expiresAt = ($validated['remember'] ?? false)
            ? null
            : now()->addHours(12);
        $token = $user->createToken('ComputerShop frontend', ['*'], $expiresAt);

        return response()->json([
            'success' => true,
            'message' => 'Signed in successfully.',
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt,
                'user' => $user,
            ],
        ], 200);
    }

    public function user(Request $request)
    {
        return response()->json([
            'success' => true,
            'message' => 'User retrieved successfully.',
            'data' => $request->user()->load('role'),
        ], 200);
    }

    public function logout(Request $request)
    {
        $token = $request->user()->currentAccessToken();
        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Signed out successfully.',
        ], 200);
    }
}
