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
     * Get all dynamic scan quota and trial policies (Admin only).
     */
    public function getPolicies(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->scanQuotaService->getAllPolicies(),
        ]);
    }

    /**
     * Update dynamic scan quota and trial policies (Admin only).
     */
    public function updatePolicies(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || $user->role?->slug !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Admin access required.',
            ], 403);
        }

        $validated = $request->validate([
            'doctor_trial_days' => 'sometimes|integer|min:1|max:365',
            'doctor_trial_daily_scans' => 'sometimes|integer|min:1|max:1000',
            'doctor_unsubscribed_daily_scans' => 'sometimes|integer|min:0|max:1000',
            'patient_default_daily_scans' => 'sometimes|integer|min:1|max:1000',
            'patient_care_daily_scans' => 'sometimes|integer|min:1|max:1000',
            'patient_care_duration_days' => 'sometimes|integer|min:1|max:365',
        ]);

        if (isset($validated['doctor_trial_days'])) {
            $this->scanQuotaService->setDoctorTrialDays((int) $validated['doctor_trial_days']);
        }
        if (isset($validated['doctor_trial_daily_scans'])) {
            $this->scanQuotaService->setDoctorTrialDailyQuota((int) $validated['doctor_trial_daily_scans']);
        }
        if (isset($validated['doctor_unsubscribed_daily_scans'])) {
            $this->scanQuotaService->setDoctorUnsubscribedDailyQuota((int) $validated['doctor_unsubscribed_daily_scans']);
        }
        if (isset($validated['patient_default_daily_scans'])) {
            $this->scanQuotaService->setPatientDefaultDailyQuota((int) $validated['patient_default_daily_scans']);
        }
        if (isset($validated['patient_care_daily_scans'])) {
            $this->scanQuotaService->setPatientCareDailyQuota((int) $validated['patient_care_daily_scans']);
        }
        if (isset($validated['patient_care_duration_days'])) {
            $this->scanQuotaService->setPatientCareDurationDays((int) $validated['patient_care_duration_days']);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Scan quota and trial policies updated successfully.',
            'data' => $this->scanQuotaService->getAllPolicies(),
        ]);
    }

    /**
     * Legacy: Get the system-wide default daily scan quota.
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
     * Legacy: Update the system-wide default daily scan quota (Admin only).
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
     * Legacy: Get dynamic grace periods for doctor appointments and doctor-created accounts.
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
     * Legacy: Update dynamic grace periods for doctor appointments and doctor-created accounts (Admin only).
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
