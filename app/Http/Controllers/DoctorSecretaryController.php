<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DoctorSecretaryController extends Controller
{
    public function index(Request $request)
    {
        $doctor = $request->user();

        if ($doctor->role->slug !== 'doctor') {
            return response()->json(['message' => 'Unauthorized. Doctor role required.'], 403);
        }

        $secretaries = User::where('doctor_id', $doctor->id)
            ->whereHas('role', function ($q) {
                $q->where('slug', 'secretary');
            })
            ->latest()
            ->get();

        return UserResource::collection($secretaries);
    }

    public function store(Request $request)
    {
        $doctor = $request->user();

        if ($doctor->role->slug !== 'doctor') {
            return response()->json(['message' => 'Unauthorized. Doctor role required.'], 403);
        }

        $validated = $request->validate([
            'firstName' => ['required', 'string', 'max:255'],
            'middleName' => ['nullable', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        return DB::transaction(function () use ($validated, $doctor) {
            $secretaryRole = Role::where('slug', 'secretary')->firstOrFail();

            $secretary = User::create([
                'first_name' => $validated['firstName'],
                'middle_name' => $validated['middleName'] ?? null,
                'last_name' => $validated['lastName'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role_id' => $secretaryRole->id,
                'doctor_id' => $doctor->id,
                'uuid' => (string) Str::uuid(),
            ]);

            $secretary->load('role', 'doctor');

            return response()->json([
                'message' => 'Secretary created successfully.',
                'data' => new UserResource($secretary),
            ], 201);
        });
    }

    public function destroy(Request $request, string $uuid)
    {
        $doctor = $request->user();

        if ($doctor->role->slug !== 'doctor') {
            return response()->json(['message' => 'Unauthorized. Doctor role required.'], 403);
        }

        $secretary = User::where('uuid', $uuid)
            ->where('doctor_id', $doctor->id)
            ->firstOrFail();

        $secretary->delete();

        return response()->json([
            'message' => 'Secretary removed successfully.',
        ], 200);
    }
}
