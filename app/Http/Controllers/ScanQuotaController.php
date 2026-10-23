<?php

namespace App\Http\Controllers;

use App\Service\ScanQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScanQuotaController extends Controller
{
    public function __construct(private ScanQuotaService $scanQuotaService) {}

    /**
     * Get the current user's scan quota and countdown status.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $quota = $this->scanQuotaService->getQuota($user);

        return response()->json([
            'status' => 'success',
            'data' => $quota,
        ]);
    }

    /**
     * Get the system-wide default daily scan quota.
     */
    public function getDefaultQuota(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'default_daily_quota' => $this->scanQuotaService->getDefaultDailyQuota(),
            ],
        ]);
    }

    /**
     * Update the system-wide default daily scan quota (Admin only).
     */
    public function updateDefaultQuota(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || $user->role?->slug !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Admin access required.',
            ], 403);
        }

        $validated = $request->validate([
            'default_daily_quota' => 'required|integer|min:1|max:1000',
        ]);

        $newQuota = $this->scanQuotaService->setDefaultDailyQuota((int) $validated['default_daily_quota']);

        return response()->json([
            'status' => 'success',
            'message' => 'Default daily scan quota updated successfully.',
            'data' => [
                'default_daily_quota' => $newQuota,
            ],
        ]);
    }

    /**
     * Get dynamic grace periods for doctor appointments and doctor-created accounts.
     */
    public function getCareGracePeriods(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'appointment_unlimited_days' => $this->scanQuotaService->getAppointmentUnlimitedDays(),
                'doctor_created_unlimited_days' => $this->scanQuotaService->getDoctorCreatedUnlimitedDays(),
            ],
        ]);
    }

    /**
     * Update dynamic grace periods for doctor appointments and doctor-created accounts (Admin only).
     */
    public function updateCareGracePeriods(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || $user->role?->slug !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Admin access required.',
            ], 403);
        }

        $validated = $request->validate([
            'appointment_unlimited_days' => 'sometimes|integer|min:0|max:365',
            'doctor_created_unlimited_days' => 'sometimes|integer|min:0|max:365',
        ]);

        if (isset($validated['appointment_unlimited_days'])) {
            $this->scanQuotaService->setAppointmentUnlimitedDays((int) $validated['appointment_unlimited_days']);
        }

        if (isset($validated['doctor_created_unlimited_days'])) {
            $this->scanQuotaService->setDoctorCreatedUnlimitedDays((int) $validated['doctor_created_unlimited_days']);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Unlimited scan grace periods updated successfully.',
            'data' => [
                'appointment_unlimited_days' => $this->scanQuotaService->getAppointmentUnlimitedDays(),
                'doctor_created_unlimited_days' => $this->scanQuotaService->getDoctorCreatedUnlimitedDays(),
            ],
        ]);
    }
}
