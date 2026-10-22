<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\OtpVerification;
use App\Service\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class OtpController extends Controller
{
    public function send(Request $request, OtpService $otpService)
    {
        $validated = $request->validate([
            'destination' => ['required', 'email', 'max:190'],
            'purpose' => [
                'required',
                Rule::in([
                    'registration',
                    'login',
                    'password_reset',
                    'change_contact',
                ]),
            ],
        ]);

        $destination = Str::lower(trim($validated['destination']));
        $purpose = $validated['purpose'];
        $privacySensitive = in_array($purpose, ['login', 'password_reset'], true);

        $user = User::where('email', $destination)->first();

        // Do not reveal whether an account exists for sensitive flows.
        $genericResponse = [
            'success' => true,
            'message' => 'If the account is eligible, an OTP email will be sent.',
        ];

        if ($privacySensitive && !$user) {
            return response()->json($genericResponse, 202);
        }

        $challenge = $otpService->create($user, $destination, $purpose);
        $verification = $challenge['verification'];
        $code = $challenge['code'];

        try {
            Mail::raw(
                "Your verification code is {$code}. It expires in 5 minutes. If you did not request it, you can ignore this email.",
                function ($message) use ($destination) {
                    $message->to($destination)
                        ->subject('Your verification code');
                }
            );
        } catch (Throwable $exception) {
            report($exception);
            $otpService->discard($verification->id);

            if ($privacySensitive) {
                return response()->json($genericResponse, 202);
            }

            return response()->json([
                'success' => false,
                'message' => 'Unable to send the OTP email. Please try again later.',
            ], 503);
        }

        return response()->json(
            $privacySensitive
                ? $genericResponse
                : ['success' => true, 'message' => 'OTP sent to the email address.'],
            202
        );
    }

    public function verify(Request $request, OtpService $otpService)
    {
        $validated = $request->validate([
            'destination' => ['required', 'email', 'max:190'],
            'purpose' => [
                'required',
                Rule::in([
                    'registration',
                    'login',
                    'password_reset',
                    'change_contact',
                ]),
            ],
            'otp' => ['required', 'digits:6'],
        ]);

        $otpService->verify(
            Str::lower(trim($validated['destination'])),
            $validated['purpose'],
            $validated['otp']
        );

        return response()->json([
            'success' => true,
            'message' => 'OTP verified successfully.',
        ], 200);
    }

    /*
     * Admin-only endpoint.
     * Add authentication and authorization middleware before enabling it.
     */
    public function show(string $id)
    {
        $otp = OtpVerification::find($id);

        if (!$otp) {
            return response()->json([
                'success' => false,
                'message' => 'OTP record not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $otp->id,
                'user_id' => $otp->user_id,
                'destination' => $otp->destination,
                'purpose' => $otp->purpose,
                'expires_at' => $otp->expires_at,
                'verified_at' => $otp->verified_at,
                'attempts' => $otp->attempts,
                'created_at' => $otp->created_at,
            ],
        ], 200);
    }
}
