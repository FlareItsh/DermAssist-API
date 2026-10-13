<?php

namespace App\Http\Controllers;

use App\Http\Requests\TwoFactor\ConfirmTwoFactorRequest;
use App\Http\Requests\TwoFactor\DisableTwoFactorRequest;
use App\Http\Requests\TwoFactor\RegenerateRecoveryCodesRequest;
use App\Http\Requests\TwoFactor\TwoFactorChallengeRequest;
use App\Service\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwoFactorController extends Controller
{
    public function __construct(
        protected TwoFactorService $twoFactorService
    ) {}

    /**
     * Start 2FA setup and get QR code SVG + secret key.
     */
    public function enable(Request $request): JsonResponse
    {
        return $this->twoFactorService->enable($request->user());
    }

    /**
     * Confirm 2FA setup with a valid TOTP code.
     */
    public function confirm(ConfirmTwoFactorRequest $request): JsonResponse
    {
        return $this->twoFactorService->confirm(
            $request->user(),
            (string) $request->input('code')
        );
    }

    /**
     * Solve a 2FA challenge using TOTP code or recovery code.
     */
    public function challenge(TwoFactorChallengeRequest $request): JsonResponse
    {
        return $this->twoFactorService->verifyChallenge(
            (string) $request->input('challenge'),
            $request->input('code'),
            $request->input('recovery_code'),
            $request->input('device_id') ?? $request->input('device_token')
        );
    }

    /**
     * Disable 2FA (requires current password).
     */
    public function disable(DisableTwoFactorRequest $request): JsonResponse
    {
        return $this->twoFactorService->disable(
            $request->user(),
            (string) $request->input('password')
        );
    }

    /**
     * Regenerate emergency recovery codes (requires current password).
     */
    public function regenerateRecoveryCodes(RegenerateRecoveryCodesRequest $request): JsonResponse
    {
        return $this->twoFactorService->regenerateRecoveryCodes(
            $request->user(),
            (string) $request->input('password')
        );
    }
}
