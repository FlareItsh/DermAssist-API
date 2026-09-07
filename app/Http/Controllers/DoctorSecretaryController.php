<?php

namespace App\Http\Controllers;

use App\Service\UserService;
use Illuminate\Http\Request;

class DoctorSecretaryController extends Controller
{
    public function __construct(private UserService $userService) {}

    public function index(Request $request)
    {
        return $this->userService->listDoctorSecretaries($request->user());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'firstName' => ['required', 'string', 'max:255'],
            'middleName' => ['nullable', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        return $this->userService->createDoctorSecretary($request->user(), $validated);
    }

    public function destroy(Request $request, string $uuid)
    {
        return $this->userService->deleteDoctorSecretary($request->user(), $uuid);
    }
}
