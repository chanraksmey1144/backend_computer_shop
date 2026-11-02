<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController extends Controller
{
    /**
     * Redirect user to Google Login.
     */
    public function redirect()
    {
        if (
            ! config('services.google.client_id')
            || ! config('services.google.client_secret')
        ) {
            return redirect()->away($this->callbackUrl('error=google_auth_unavailable'));
        }

        return Socialite::driver('google')->redirect();
    }

    /**
     * Handle Google OAuth callback.
     */
    public function callback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();

            $email = Str::lower(trim((string) $googleUser->getEmail()));

            $emailVerified = filter_var(
                $googleUser->user['email_verified'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );

            if (!$email || !$emailVerified) {
                return redirect()->away($this->callbackUrl('error=google_email_unverified'));
            }

            // Find existing user by Google ID.
            $user = User::where('google_id', $googleUser->getId())
                ->first();

            if (!$user) {

                // Find existing account by email.
                $user = User::where('email', $email)->first();

                if ($user) {

                    // Link only after Google has confirmed ownership of the email.
                    $user->google_id = $googleUser->getId();
                    $user->avatar = $googleUser->getAvatar();
                    $user->email_verified_at ??= now();
                    $user->save();

                } else {
                    $customerRole = Role::firstOrCreate(
                        ['name' => 'customer'],
                        ['description' => 'Customer account']
                    );

                    $user = User::create([
                        'name' => $googleUser->getName()
                            ?? 'Google User',

                        'email' => $email,
                        // The User model hashes passwords through its cast.
                        'password' => Str::random(40),
                        'google_id' => $googleUser->getId(),
                        'avatar' => $googleUser->getAvatar(),
                        'role_id' => $customerRole->id,
                        'status' => 'active',
                    ]);
                    $user->email_verified_at = now();
                    $user->save();
                }
            } else {

                // Update Google profile information.
                $user->update([
                    'name' => $googleUser->getName() ?? $user->name,
                    'avatar' => $googleUser->getAvatar(),
                ]);
                $user->email_verified_at ??= now();
                $user->save();
            }

            if ($user->status !== 'active') {
                return redirect()->away($this->callbackUrl('error=account_inactive'));
            }

            // Generate Sanctum API Token.
            $token = $user->createToken(
                'computer-shop-api',
                ['*'],
                now()->addHours(12)
            )->plainTextToken;

            return redirect()->away($this->callbackUrl('token=' . urlencode($token)));

        } catch (Throwable $e) {

            report($e);

            return redirect()->away($this->callbackUrl('error=google_auth_failed'));
        }
    }

    private function callbackUrl(string $fragment): string
    {
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

        return $frontendUrl . '/auth/google/callback#' . $fragment;
    }
}
