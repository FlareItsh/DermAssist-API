<?php

namespace App\Http\Controllers;

use App\Service\UserService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    private UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function login(Request $request)
    {
        return $this->userService->loginUser($request);
    }

    public function logout(Request $request)
    {
        return $this->userService->logoutUser($request->user());
    }

    public function register(Request $request)
    {
        return $this->userService->createUser($request->all());
    }
}
