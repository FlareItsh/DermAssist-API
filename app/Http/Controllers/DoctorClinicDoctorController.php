<?php

namespace App\Http\Controllers;

use App\Service\DoctorClinicDoctorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DoctorClinicDoctorController extends Controller
{
    public function __construct(
        protected DoctorClinicDoctorService $doctorClinicDoctorService
    ) {}

    /**
     * Get all assigned associate doctors and seat usage quota for the authenticated owner doctor.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->doctorClinicDoctorService->getClinicDoctors($request->user());
    }

    /**
     * Search verified candidate doctors.
     */
    public function search(Request $request): JsonResponse
    {
        $query = (string) $request->input('query', $request->input('q', ''));

        return $this->doctorClinicDoctorService->searchEligibleDoctors($request->user(), $query);
    }

    /**
     * Assign a doctor to a clinic branch seat.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'clinic_id' => 'required_without:clinic_uuid|integer',
            'clinic_uuid' => 'required_without:clinic_id|string',
            'doctor_id' => 'required_without_all:doctor_uuid,email|integer',
            'doctor_uuid' => 'required_without_all:doctor_id,email|string',
            'email' => 'required_without_all:doctor_id,doctor_uuid|email',
            'role' => 'nullable|string|in:associate,resident,consultant',
        ]);

        return $this->doctorClinicDoctorService->assignDoctor($request->user(), $validated);
    }

    /**
     * Remove an associate doctor from a clinic seat.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->doctorClinicDoctorService->removeDoctor($request->user(), $id);
    }

    /**
     * Get pending invitations for the authenticated doctor.
     */
    public function invitations(Request $request): JsonResponse
    {
        return $this->doctorClinicDoctorService->getPendingInvitations($request->user());
    }

    /**
     * Accept a pending clinic seat invitation.
     */
    public function acceptInvitation(Request $request, int $id): JsonResponse
    {
        return $this->doctorClinicDoctorService->acceptInvitation($request->user(), $id);
    }

    /**
     * Decline a pending clinic seat invitation.
     */
    public function declineInvitation(Request $request, int $id): JsonResponse
    {
        return $this->doctorClinicDoctorService->declineInvitation($request->user(), $id);
    }
}
