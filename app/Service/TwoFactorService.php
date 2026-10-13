<?php

namespace App\Service;

use App\Http\Resources\UserResource;
use App\Models\BlockedDevice;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorService
{
    protected Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA;
    }

    /**
     * Start 2FA enrollment: generate secret and QR code SVG.
     */
    public function enable(User $user): JsonResponse
    {
        $secret = $this->google2fa->generateSecretKey();
        $company = config('app.name', 'DermAssist');
        $otpauthUrl = $this->google2fa->getQRCodeUrl($company, $user->email, $secret);

        $renderer = new ImageRenderer(
            new RendererStyle(192),
            new SvgImageBackEnd
        );
        $writer = new Writer($renderer);
        $svg = $writer->writeString($otpauthUrl);
        $svg = trim((string) preg_replace('/<\?xml.*?\?>/i', '', $svg));

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json([
            'secret' => $secret,
            'qr_code_svg' => $svg,
            'otpauth_url' => $otpauthUrl,
        ], 200);
    }

    /**
     * Confirm 2FA enrollment using a 6-digit TOTP code.
     */
    public function confirm(User $user, string $code): JsonResponse
    {
        if (empty($user->two_factor_secret)) {
            return response()->json([
                'message' => 'Two-factor authentication has not been initiated.',
            ], 422);
        }

        $isValid = $this->google2fa->verifyKey($user->two_factor_secret, $code, 1);
        if (! $isValid) {
            return response()->json([
                'message' => 'Invalid verification code. Please check your authenticator app and try again.',
            ], 422);
        }

        $recoveryCodes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $recoveryCodes,
        ])->save();

        return response()->json([
            'message' => 'Two-factor authentication enabled successfully.',
            'recovery_codes' => $recoveryCodes,
        ], 200);
    }

    /**
     * Verify a 2FA challenge during login or Google SSO.
     */
    public function verifyChallenge(string $challenge, ?string $code, ?string $recoveryCode, ?string $deviceId = null): JsonResponse
    {
        $cacheKey = "2fa_challenge_{$challenge}";
        $cached = Cache::get($cacheKey);

        if (! $cached || empty($cached['user_id'])) {
            return response()->json([
                'message' => 'Authentication challenge has expired or is invalid. Please sign in again.',
            ], 401);
        }

        $user = User::with('role', 'doctor')->find($cached['user_id']);
        if (! $user) {
            return response()->json([
                'message' => 'User account not found.',
            ], 404);
        }

        if ($user->account_status === 'disabled') {
            return response()->json([
                'message' => 'Account has been disabled by your doctor.',
            ], 403);
        }

        $resolvedDeviceId = $deviceId ?? $cached['device_id'] ?? request()?->cookie('da_device_id') ?? request()?->header('X-Device-Id');
        if ($resolvedDeviceId) {
            if (BlockedDevice::where('device_id', $resolvedDeviceId)->exists()) {
                return response()->json([
                    'message' => 'Access denied: This device has been restricted.',
                ], 403);
            }
            if (! $user->device_token) {
                $user->update(['device_token' => $resolvedDeviceId]);
            }
        }

        $authenticated = false;

        if (! empty($code)) {
            $cleanedCode = trim((string) $code);
            $isValid = $this->google2fa->verifyKey($user->two_factor_secret, $cleanedCode, 1);

            if ($isValid) {
                $replayKey = "2fa_used_{$user->id}_{$cleanedCode}";
                $notReplayed = Cache::add($replayKey, true, 90);
                if (! $notReplayed) {
                    return response()->json([
                        'message' => 'This code has already been used. Please wait for the next code.',
                    ], 422);
                }
                $authenticated = true;
            }
        } elseif (! empty($recoveryCode)) {
            $normalizedCode = strtoupper(trim((string) $recoveryCode));
            $recoveryCodes = $user->two_factor_recovery_codes ?? [];
            $foundIndex = null;

            foreach ($recoveryCodes as $idx => $storedCode) {
                if (strtoupper(trim($storedCode)) === $normalizedCode) {
                    $foundIndex = $idx;
                    break;
                }
            }

            if ($foundIndex !== null) {
                unset($recoveryCodes[$foundIndex]);
                $user->forceFill([
                    'two_factor_recovery_codes' => array_values($recoveryCodes),
                ])->save();
                $authenticated = true;
            }
        }

        if (! $authenticated) {
            return response()->json([
                'message' => 'Invalid one-time passcode or recovery code.',
            ], 422);
        }

        Cache::forget($cacheKey);

        $token = $user->createToken($user->email)->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ], 200);
    }

    /**
     * Disable 2FA for the authenticated user (requires current password).
     */
    public function disable(User $user, string $password): JsonResponse
    {
        if (! Hash::check($password, $user->password)) {
            return response()->json([
                'message' => 'Incorrect password provided.',
            ], 422);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json([
            'message' => 'Two-factor authentication has been disabled.',
        ], 200);
    }

    /**
     * Regenerate 8 fresh recovery codes for the authenticated user.
     */
    public function regenerateRecoveryCodes(User $user, string $password): JsonResponse
    {
        if (! $user->hasTwoFactorEnabled()) {
            return response()->json([
                'message' => 'Two-factor authentication is not enabled.',
            ], 422);
        }

        if (! Hash::check($password, $user->password)) {
            return response()->json([
                'message' => 'Incorrect password provided.',
            ], 422);
        }

        $recoveryCodes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => $recoveryCodes,
        ])->save();

        return response()->json([
            'message' => 'Recovery codes regenerated successfully.',
            'recovery_codes' => $recoveryCodes,
        ], 200);
    }

    /**
     * Generate an array of 8 unique alphanumeric recovery codes.
     */
    protected function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = Str::upper(Str::random(5)).'-'.Str::upper(Str::random(5));
        }

        return $codes;
    }
}
