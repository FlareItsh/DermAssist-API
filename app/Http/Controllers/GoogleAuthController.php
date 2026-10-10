<?php

namespace App\Http\Controllers;

use App\Models\BlockedDevice;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    /**
     * Redirect the user to the Google authentication page.
     */
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    /**
     * Handle the callback from Google.
     */
    public function callback(Request $request): RedirectResponse
    {
        $frontendUrl = rtrim((string) (config('app.frontend_url') ?? env('FRONTEND_URL', 'http://localhost:3000')), '/');

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Throwable $e) {
            Log::error('Google OAuth callback failed: '.$e->getMessage());

            return redirect("{$frontendUrl}/login?error=".urlencode('Google authentication failed. Please try again.'));
        }

        $email = strtolower(trim((string) $googleUser->getEmail()));
        $googleId = (string) $googleUser->getId();

        if (empty($email) || empty($googleId)) {
            return redirect("{$frontendUrl}/login?error=".urlencode('Unable to retrieve email from Google account.'));
        }

        // Check if device or IP is blocked
        $deviceId = $request->cookie('da_device_id') ?? $request->header('X-Device-Id');
        if ($deviceId && BlockedDevice::where('device_id', $deviceId)->exists()) {
            return redirect("{$frontendUrl}/login?error=".urlencode('Access denied: This device has been restricted.'));
        }

        $user = User::where('google_id', $googleId)->first();

        if (! $user) {
            $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

            if ($user) {
                $user->update([
                    'google_id' => $googleId,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ]);
            } else {
                $patientRole = Role::where('slug', 'patient')->first();
                if (! $patientRole) {
                    Log::error('Patient role not found during Google registration.');

                    return redirect("{$frontendUrl}/login?error=".urlencode('System configuration error: Patient role missing.'));
                }

                $rawUser = $googleUser->user ?? [];
                $firstName = ! empty($rawUser['given_name']) ? trim((string) $rawUser['given_name']) : '';
                $lastName = ! empty($rawUser['family_name']) ? trim((string) $rawUser['family_name']) : '';

                if (empty($firstName)) {
                    $nameParts = explode(' ', trim((string) $googleUser->getName()));
                    $firstName = $nameParts[0] ?? 'Google';
                    $lastName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : 'Patient';
                }

                if (empty($lastName)) {
                    $lastName = 'Patient';
                }

                $user = User::create([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'password' => Hash::make(Str::random(32)),
                    'role_id' => $patientRole->id,
                    'uuid' => (string) Str::uuid(),
                    'google_id' => $googleId,
                    'account_status' => 'active',
                    'email_verified_at' => now(),
                    'terms_accepted_at' => now(),
                    'cookies_accepted_at' => now(),
                    'device_token' => $deviceId,
                ]);
            }
        }

        if ($user->account_status === 'disabled') {
            return redirect("{$frontendUrl}/login?error=".urlencode('Your account has been disabled by your doctor.'));
        }

        // Link device token if missing
        if ($deviceId && ! $user->device_token) {
            $user->update(['device_token' => $deviceId]);
        }

        $token = $user->createToken('google-auth-token')->plainTextToken;
        $user->load('role', 'doctor');

        $userPayload = urlencode(base64_encode(json_encode([
            'uuid' => $user->uuid,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'role' => $user->role?->slug ?? 'patient',
            'account_status' => $user->account_status ?? 'active',
            'doctor_uuid' => $user->doctor?->uuid,
            'verification_deadline' => $user->verification_deadline?->toIso8601String(),
        ])));

        return redirect("{$frontendUrl}/auth/callback?token={$token}&user={$userPayload}");
    }
}
