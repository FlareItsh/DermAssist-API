<?php

namespace App\Http\Controllers;

use App\Http\Resources\DoctorAvailabilityResource;
use App\Http\Resources\UserResource;
use App\Models\DoctorAvailability;
use App\Service\DoctorAvailabilityService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DoctorAvailabilityController extends Controller
{
    private DoctorAvailabilityService $service;

    public function __construct(DoctorAvailabilityService $service)
    {
        $this->service = $service;
    }

    public function index(string $doctorUuid): JsonResponse
    {
        $availabilities = $this->service->getAvailabilitiesByDoctorUuid($doctorUuid);

        return response()->json(DoctorAvailabilityResource::collection($availabilities));
    }

    public function store(Request $request, string $doctorUuid): JsonResponse
    {
        $validated = $request->validate([
            'available_date' => 'required|date|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'is_available' => 'sometimes|boolean',
        ]);

        $availability = $this->service->createAvailability($request->user(), $validated);

        return response()->json(new DoctorAvailabilityResource($availability), 201);
    }

    public function update(Request $request, DoctorAvailability $availability): JsonResponse
    {
        $validated = $request->validate([
            'available_date' => 'sometimes|date|after_or_equal:today',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i|after:start_time',
            'is_available' => 'sometimes|boolean',
        ]);

        $updated = $this->service->updateAvailability(
            $availability,
            $validated,
            $request->user()
        );

        return response()->json(new DoctorAvailabilityResource($updated));
    }

    public function destroy(Request $request, DoctorAvailability $availability): JsonResponse
    {
        $this->service->deleteAvailability($availability, $request->user());

        return response()->json(['message' => 'Deleted successfully'], 200);
    }

    public function check(Request $request, string $doctorUuid): JsonResponse
    {
        $dateParam = $request->query('date');
        $date = $dateParam ? Carbon::parse($dateParam) : now();

        $result = $this->service->checkDoctorAvailabilityByUuid($doctorUuid, $date, $request->user());

        return response()->json([
            'checked_at' => $date->toDateTimeString(),
            'is_available' => $result['is_available'],
            'next_available' => $result['next_available'],
            'alternatives' => UserResource::collection($result['alternatives']),
        ]);
    }
}
