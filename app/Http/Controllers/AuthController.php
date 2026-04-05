<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Service\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(protected UserService $userService)
    {
    }

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
}
