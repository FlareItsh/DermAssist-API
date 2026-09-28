<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Service\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(protected UserService $userService) {}

    public function login(LoginRequest $request): JsonResponse
    {
        return $this->userService->login($request->all());
    }

    public function logout(Request $request)
    {
        return $this->userService->logout($request->user());
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        return $this->userService->createUser($request->all());
    }

    public function verifyAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string',
        ]);

        $result = $this->userService->verifyAccount($validated['token']);

        if (! $result['status']) {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json($result, 200);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $result = $this->userService->resendVerification($user);

        if (! $result['status']) {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json($result, 200);
    }

    public function blockDevice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'required|string',
            'user_id' => 'nullable|integer|exists:users,id',
            'reason' => 'nullable|string',
        ]);

        $block = $this->userService->blockDevice(
            $validated['device_id'],
            $validated['user_id'] ?? null,
            $validated['reason'] ?? null,
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Device successfully restricted.',
            'data' => $block,
        ], 200);
    }

    public function unblockDevice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'required|string',
        ]);

        $success = $this->userService->unblockDevice($validated['device_id']);

        return response()->json([
            'message' => $success ? 'Device restriction lifted.' : 'Device was not blocked.',
        ], 200);
    }
}
