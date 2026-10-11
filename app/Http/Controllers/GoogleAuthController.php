<?php

namespace App\Http\Controllers;

use App\Mail\GoogleLoginConfirmMail;
use App\Models\BlockedDevice;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    /**
     * Redirect the user to the Google authentication page.
     */
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')
            ->scopes(['openid', 'profile', 'email'])
            ->stateless()
            ->redirect();
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
        $isNewUser = false;

        if (! $user) {
            $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

            if ($user) {
                $user->update([
                    'google_id' => $googleId,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ]);
            } else {
                $isNewUser = true;
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

        // Fetch & save Google profile photo if user doesn't have one
        if (empty($user->avatar_path) && ! empty($googleUser->getAvatar())) {
            $this->saveGoogleAvatar($user, (string) $googleUser->getAvatar());
        }

        // Recognized if existing user has device_token matching current deviceId
        $isRecognizedDevice = (! $isNewUser) && (! empty($user->device_token)) && $deviceId && ($user->device_token === $deviceId);

        // Option C: If new signup OR device is not recognized, require email confirmation first
        if (! $isRecognizedDevice) {
            $confirmUrl = URL::temporarySignedRoute(
                'auth.google.confirm',
                now()->addMinutes(15),
                ['uuid' => $user->uuid, 'device_id' => $deviceId]
            );

            try {
                Mail::to($user->email)->send(new GoogleLoginConfirmMail($user, $confirmUrl));
            } catch (\Throwable $e) {
                Log::error('Failed to send Google login confirmation email: '.$e->getMessage());
            }

            return redirect("{$frontendUrl}/auth/check-email?email=".urlencode($user->email).'&type=google');
        }

        // Device is recognized: Issue one-time authorization code
        $code = Str::random(40);
        Cache::put("google_auth_code_{$code}", [
            'user_id' => $user->id,
            'device_id' => $deviceId,
        ], now()->addMinutes(2));

        return redirect("{$frontendUrl}/auth/callback?code={$code}");
    }

    /**
     * Confirm Google sign-in from email link.
     */
    public function confirm(Request $request, string $uuid): RedirectResponse
    {
        $frontendUrl = rtrim((string) (config('app.frontend_url') ?? env('FRONTEND_URL', 'http://localhost:3000')), '/');

        if (! $request->hasValidSignature()) {
            return redirect("{$frontendUrl}/login?error=".urlencode('Sign in link has expired or is invalid. Please sign in again.'));
        }

        $user = User::where('uuid', $uuid)->first();
        if (! $user) {
            return redirect("{$frontendUrl}/login?error=".urlencode('User account not found.'));
        }

        $deviceId = $request->query('device_id');
        if ($deviceId) {
            $user->update(['device_token' => $deviceId]);
        }

        $code = Str::random(40);
        Cache::put("google_auth_code_{$code}", [
            'user_id' => $user->id,
            'device_id' => $deviceId,
        ], now()->addMinutes(2));

        return redirect("{$frontendUrl}/auth/callback?code={$code}");
    }

    /**
     * Exchange one-time authorization code for Sanctum token and user payload.
     */
    public function exchange(Request $request): JsonResponse
    {
        $code = (string) $request->input('code');
        if (empty($code)) {
            return response()->json(['message' => 'Authorization code is required.'], 422);
        }

        $cached = Cache::pull("google_auth_code_{$code}");
        if (! $cached || empty($cached['user_id'])) {
            return response()->json(['message' => 'Invalid or expired authorization code.'], 401);
        }

        $user = User::with('role', 'doctor')->find($cached['user_id']);
        if (! $user) {
            return response()->json(['message' => 'User account not found.'], 404);
        }

        if ($user->account_status === 'disabled') {
            return response()->json(['message' => 'Your account has been disabled by your doctor.'], 403);
        }

        $deviceId = $cached['device_id'] ?? $request->cookie('da_device_id') ?? $request->header('X-Device-Id');
        if ($deviceId && ! $user->device_token) {
            $user->update(['device_token' => $deviceId]);
        }

        $token = $user->createToken('google-auth-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'uuid' => $user->uuid,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'role' => $user->role?->slug ?? 'patient',
                'account_status' => $user->account_status ?? 'active',
                'doctor_uuid' => $user->doctor?->uuid,
                'verification_deadline' => $user->verification_deadline?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Resend Google login confirmation email to user.
     */
    public function resendConfirm(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email')));
        if (empty($email)) {
            return response()->json(['message' => 'Email is required.'], 422);
        }

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if ($user) {
            $deviceId = $request->cookie('da_device_id') ?? $request->header('X-Device-Id');
            $confirmUrl = URL::temporarySignedRoute(
                'auth.google.confirm',
                now()->addMinutes(15),
                ['uuid' => $user->uuid, 'device_id' => $deviceId]
            );

            try {
                Mail::to($user->email)->send(new GoogleLoginConfirmMail($user, $confirmUrl));
            } catch (\Throwable $e) {
                Log::error('Failed to resend Google login confirmation email: '.$e->getMessage());
            }
        }

        return response()->json(['message' => 'A new confirmation email has been sent if an account exists.']);
    }

    /**
     * Download and store Google profile avatar to public disk.
     */
    protected function saveGoogleAvatar(User $user, string $avatarUrl): void
    {
        try {
            $response = Http::timeout(5)->get($avatarUrl);
            if ($response->successful()) {
                $filename = 'avatars/google_'.Str::slug($user->first_name.'_'.$user->last_name).'_'.time().'.jpg';
                Storage::disk('public')->put($filename, $response->body());
                $user->update(['avatar_path' => $filename]);
            } else {
                $user->update(['avatar_path' => $avatarUrl]);
            }
        } catch (\Throwable $e) {
            Log::warning("Could not download Google avatar for user {$user->id}: ".$e->getMessage());
            $user->update(['avatar_path' => $avatarUrl]);
        }
    }
}
