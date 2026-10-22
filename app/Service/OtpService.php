<?php

namespace App\Service;

use App\Models\OtpVerification;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OtpService
{
    /**
     * Create a six-digit OTP.
     *
     * @return array{verification: OtpVerification, code: string}
     */
    public function create(
        ?User $user,
        string $destination,
        string $purpose
    ): array {
        return DB::transaction(function () use (
            $user,
            $destination,
            $purpose
        ) {
            // Retain expired challenges briefly for diagnostics, then prune them.
            OtpVerification::where('expires_at', '<', now()->subDay())->delete();

            // Invalidate previous unused OTPs for this destination and purpose.
            OtpVerification::where('destination', $destination)
                ->where('purpose', $purpose)
                ->whereNull('verified_at')
                ->delete();

            // Generate a cryptographically secure six-digit OTP.
            $code = (string) random_int(100000, 999999);

            $verification = OtpVerification::create([
                'user_id' => $user?->id,
                'destination' => $destination,
                'purpose' => $purpose,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(5),
                'attempts' => 0,
                'created_at' => now(),
            ]);

            return [
                'verification' => $verification,
                'code' => $code,
            ];
        });
    }

    /**
     * Remove an OTP that could not be delivered.
     */
    public function discard(int $verificationId): void
    {
        OtpVerification::whereKey($verificationId)
            ->whereNull('verified_at')
            ->delete();
    }

    /**
     * Verify an OTP.
     */
    public function verify(
        string $destination,
        string $purpose,
        string $code
    ): OtpVerification {
        $incorrectCode = false;

        $otp = DB::transaction(function () use (
            $destination,
            $purpose,
            $code,
            &$incorrectCode
        ) {
            $otp = OtpVerification::where('destination', $destination)
                ->where('purpose', $purpose)
                ->whereNull('verified_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (!$otp) {
                throw ValidationException::withMessages([
                    'otp' => ['OTP is invalid or has already been used.'],
                ]);
            }

            if (now()->greaterThanOrEqualTo($otp->expires_at)) {
                throw ValidationException::withMessages([
                    'otp' => ['OTP has expired. Please request a new one.'],
                ]);
            }

            if ($otp->attempts >= 5) {
                throw ValidationException::withMessages([
                    'otp' => ['Too many attempts. Please request a new OTP.'],
                ]);
            }

            $otp->attempts++;

            if (!Hash::check($code, $otp->code_hash)) {
                $otp->save();
                $incorrectCode = true;

                return $otp;
            }

            $otp->verified_at = now();
            $otp->save();

            return $otp;
        });

        // Throw after the transaction so failed attempts are persisted.
        if ($incorrectCode) {
            throw ValidationException::withMessages([
                'otp' => ['Incorrect OTP.'],
            ]);
        }

        return $otp;
    }
}
