<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Service\UserService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            'affiliation' => ['nullable'],
        ]);

        return $this->userService->createDoctorSecretary($request->user(), $validated);
    }

    public function update(Request $request, string $uuid)
    {
        $secretary = User::where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'firstName' => ['sometimes', 'required', 'string', 'max:50', 'regex:/^[\pL\s\-\'.]+$/u'],
            'middleName' => ['nullable', 'string', 'max:50', 'regex:/^[\pL\s\-\'.]+$/u'],
            'lastName' => ['sometimes', 'required', 'string', 'max:50', 'regex:/^[\pL\s\-\'.]+$/u'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($secretary->id)],
            'affiliation' => ['nullable'],
            'age' => ['nullable', 'integer', 'min:0', 'max:130'],
            'gender' => ['nullable', 'string', 'max:50'],
            'password' => ['nullable', 'string', 'min:8'],
        ], [
            'firstName.regex' => 'First name cannot contain numbers or invalid symbols.',
            'middleName.regex' => 'Middle name cannot contain numbers or invalid symbols.',
            'lastName.regex' => 'Last name cannot contain numbers or invalid symbols.',
        ]);

        return $this->userService->updateDoctorSecretary($request->user(), $uuid, $validated);
    }

    public function destroy(Request $request, string $uuid)
    {
        return $this->userService->deleteDoctorSecretary($request->user(), $uuid);
    }
}
