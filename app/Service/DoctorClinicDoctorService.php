<?php

namespace App\Service;

use App\Http\Resources\ClinicDoctorResource;
use App\Models\User;
use App\Repository\ClinicDoctorRepository;
use Illuminate\Http\JsonResponse;

class DoctorClinicDoctorService
{
    public function __construct(
        protected ClinicDoctorRepository $clinicDoctorRepository
    ) {}

    /**
     * Get all associate doctors and seat usage quota for the authenticated owner doctor.
     */
    public function getClinicDoctors(User $user): JsonResponse
    {
        $this->ensureDoctorRole($user);

        $seatUsage = $user->getDoctorSeatUsage();
        $doctors = $this->clinicDoctorRepository->getDoctorsForOwner($user->id);

        return response()->json([
            'status' => 'success',
            'seat_usage' => $seatUsage,
            'data' => ClinicDoctorResource::collection($doctors),
        ]);
    }

    /**
     * Search verified doctors eligible to be added to a clinic seat.
     */
    public function searchEligibleDoctors(User $user, string $query): JsonResponse
    {
        $this->ensureDoctorRole($user);

        if (strlen(trim($query)) < 2) {
            return response()->json([
                'status' => 'success',
                'data' => [],
            ]);
        }

        $results = $this->clinicDoctorRepository->searchEligibleDoctors($query, $user->id);

        $formatted = $results->map(function (User $doctor) {
            return [
                'id' => $doctor->id,
                'uuid' => $doctor->uuid,
                'full_name' => trim($doctor->first_name.' '.$doctor->last_name),
                'email' => $doctor->email,
                'prc_number' => $doctor->prc_number,
                'affiliation' => $doctor->affiliation,
                'avatar_path' => $doctor->avatar_path,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $formatted,
        ]);
    }

    /**
     * Assign a doctor to a clinic branch under the owner's multi-doctor plan.
     */
    public function assignDoctor(User $user, array $payload): JsonResponse
    {
        $this->ensureDoctorRole($user);

        if (! $user->canAddDoctor()) {
            abort(403, 'Your current subscription plan does not allow multiple doctor seats. Please upgrade to a Clinic Group Plan.');
        }

        $seatUsage = $user->getDoctorSeatUsage();
        if (! $seatUsage['can_add']) {
            abort(422, "You have reached your subscription limit of {$seatUsage['max_doctors']} doctor seat(s). Please upgrade to add more doctor seats.");
        }

        $clinicIdentifier = $payload['clinic_id'] ?? $payload['clinic_uuid'] ?? null;
        if (! $clinicIdentifier) {
            abort(422, 'Clinic selection is required.');
        }

        $clinic = $this->clinicDoctorRepository->findOwnedClinic($user->id, $clinicIdentifier);
        if (! $clinic) {
            abort(404, 'Selected clinic branch was not found or is not owned by your account.');
        }

        $doctorIdentifier = $payload['doctor_id'] ?? $payload['doctor_uuid'] ?? $payload['email'] ?? null;
        if (! $doctorIdentifier) {
            abort(422, 'Doctor selection or email is required.');
        }

        $doctor = $this->clinicDoctorRepository->findDoctorUser($doctorIdentifier);
        if (! $doctor) {
            abort(404, 'No registered doctor account was found with the specified details.');
        }

        if ($doctor->id === $user->id) {
            abort(422, 'You cannot assign yourself as an associate doctor.');
        }

        if ($this->clinicDoctorRepository->isDoctorInClinic($clinic->id, $doctor->id)) {
            abort(422, "Dr. {$doctor->first_name} {$doctor->last_name} is already assigned to {$clinic->name}.");
        }

        $role = $payload['role'] ?? 'associate';
        $pivotId = $this->clinicDoctorRepository->assignDoctorToClinic($clinic->id, $doctor->id, $role, 'active');

        $freshSeatUsage = $user->fresh()->getDoctorSeatUsage();

        return response()->json([
            'status' => 'success',
            'message' => "Dr. {$doctor->first_name} {$doctor->last_name} has been assigned to {$clinic->name}.",
            'seat_usage' => $freshSeatUsage,
            'data' => [
                'pivot_id' => $pivotId,
                'seat_usage' => $freshSeatUsage,
            ],
        ], 201);
    }

    /**
     * Remove an associate doctor from a clinic seat.
     */
    public function removeDoctor(User $user, int $pivotId): JsonResponse
    {
        $this->ensureDoctorRole($user);

        $deleted = $this->clinicDoctorRepository->removeDoctorSeat($user->id, $pivotId);

        if (! $deleted) {
            abort(404, 'Clinic doctor assignment not found or unauthorized.');
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Associate doctor seat removed successfully.',
            'seat_usage' => $user->fresh()->getDoctorSeatUsage(),
        ]);
    }

    protected function ensureDoctorRole(User $user): void
    {
        if ($user->role?->slug !== 'doctor') {
            abort(403, 'Unauthorized. Doctor account required.');
        }
    }
}
