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
     * Get associate doctors and seat usage quota.
     * If user is the owner, returns their delegated seats.
     * If user is an associate doctor, returns the Practice Head details and all colleague doctors in the clinic group.
     */
    public function getClinicDoctors(User $user): JsonResponse
    {
        $this->ensureDoctorRole($user);

        // 1. If user is the owner with a multi-doctor plan:
        if ($user->canAddDoctor()) {
            $seatUsage = $user->getDoctorSeatUsage();
            $doctors = $this->clinicDoctorRepository->getDoctorsForOwner($user->id);

            return response()->json([
                'status' => 'success',
                'is_owner' => true,
                'owner' => null,
                'sponsoring_clinic' => null,
                'seat_usage' => $seatUsage,
                'data' => ClinicDoctorResource::collection($doctors),
            ]);
        }

        // 2. Check if user is an associate in any active clinic membership
        $clinicMemberships = $user->clinicMemberships()
            ->wherePivot('status', 'active')
            ->with(['owner.subscriptions.plan'])
            ->get();

        $activeMembership = $clinicMemberships->first(fn ($c) => $c->owner && $c->owner->getActiveSubscription() !== null)
            ?: $clinicMemberships->first();

        if ($activeMembership && $activeMembership->owner) {
            $owner = $activeMembership->owner;
            $ownerSeatUsage = $owner->getDoctorSeatUsage();
            // Get all associate doctors across this owner's clinics
            $associatedDoctors = $this->clinicDoctorRepository->getDoctorsForOwner($owner->id);

            return response()->json([
                'status' => 'success',
                'is_owner' => false,
                'owner' => [
                    'id' => $owner->id,
                    'uuid' => $owner->uuid,
                    'first_name' => $owner->first_name,
                    'last_name' => $owner->last_name,
                    'full_name' => trim($owner->first_name.' '.$owner->last_name),
                    'email' => $owner->email,
                    'prc_number' => $owner->prc_number,
                    'affiliation' => $owner->affiliation,
                    'avatar_path' => $owner->avatar_path,
                    'plan_name' => $owner->getActiveSubscription()?->plan?->name ?? 'Clinic Group Plan',
                ],
                'sponsoring_clinic' => [
                    'id' => $activeMembership->id,
                    'uuid' => $activeMembership->uuid,
                    'name' => $activeMembership->name,
                    'address' => $activeMembership->address,
                    'role' => $activeMembership->pivot->role ?? 'associate',
                ],
                'seat_usage' => $ownerSeatUsage,
                'data' => ClinicDoctorResource::collection($associatedDoctors),
            ]);
        }

        // 3. Fallback for solo doctor (single seat, no associates)
        return response()->json([
            'status' => 'success',
            'is_owner' => true,
            'owner' => null,
            'sponsoring_clinic' => null,
            'seat_usage' => $user->getDoctorSeatUsage(),
            'data' => [],
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
        $pivotId = $this->clinicDoctorRepository->assignDoctorToClinic($clinic->id, $doctor->id, $role, 'pending');

        $freshSeatUsage = $user->fresh()->getDoctorSeatUsage();

        return response()->json([
            'status' => 'success',
            'message' => "An invitation has been sent to Dr. {$doctor->first_name} {$doctor->last_name} for {$clinic->name}.",
            'seat_usage' => $freshSeatUsage,
            'data' => [
                'pivot_id' => $pivotId,
                'status' => 'pending',
                'seat_usage' => $freshSeatUsage,
            ],
        ], 201);
    }

    /**
     * Get pending clinic seat invitations for the authenticated doctor.
     */
    public function getPendingInvitations(User $user): JsonResponse
    {
        $this->ensureDoctorRole($user);

        $invitations = $this->clinicDoctorRepository->getPendingInvitationsForDoctor($user->id);

        return response()->json([
            'status' => 'success',
            'data' => $invitations,
        ]);
    }

    /**
     * Accept a clinic seat invitation.
     */
    public function acceptInvitation(User $user, int $pivotId): JsonResponse
    {
        $this->ensureDoctorRole($user);

        $accepted = $this->clinicDoctorRepository->acceptInvitation($pivotId, $user->id);

        if (! $accepted) {
            abort(404, 'Pending invitation not found or already processed.');
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Clinic seat invitation accepted successfully. You now have full access under this clinic group.',
            'data' => [
                'subscription' => $user->fresh()->getActiveSubscription(),
            ],
        ]);
    }

    /**
     * Decline a clinic seat invitation.
     */
    public function declineInvitation(User $user, int $pivotId): JsonResponse
    {
        $this->ensureDoctorRole($user);

        $declined = $this->clinicDoctorRepository->declineInvitation($pivotId, $user->id);

        if (! $declined) {
            abort(404, 'Pending invitation not found or already processed.');
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Clinic seat invitation declined.',
        ]);
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
