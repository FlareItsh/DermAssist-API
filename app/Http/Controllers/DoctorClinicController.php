<?php

namespace App\Http\Controllers;

use App\Http\Resources\ClinicResource;
use App\Service\DoctorClinicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DoctorClinicController extends Controller
{
    public function __construct(
        protected DoctorClinicService $doctorClinicService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $clinics = $this->doctorClinicService->listClinics($request->user());

        return response()->json([
            'data' => ClinicResource::collection($clinics),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'geo_latitude' => 'nullable|numeric|between:-90,90',
            'geo_longitude' => 'nullable|numeric|between:-180,180',
            'is_active' => 'sometimes|boolean',
        ]);

        $clinic = $this->doctorClinicService->createClinic($request->user(), $validated);

        return response()->json([
            'message' => 'Clinic created successfully.',
            'data' => new ClinicResource($clinic),
        ], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'geo_latitude' => 'nullable|numeric|between:-90,90',
            'geo_longitude' => 'nullable|numeric|between:-180,180',
            'is_active' => 'sometimes|boolean',
        ]);

        $clinic = $this->doctorClinicService->updateClinic($request->user(), $uuid, $validated);

        return response()->json([
            'message' => 'Clinic updated successfully.',
            'data' => new ClinicResource($clinic),
        ]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->doctorClinicService->deleteClinic($request->user(), $uuid);

        return response()->json([
            'message' => 'Clinic deleted successfully.',
        ]);
    }
}
