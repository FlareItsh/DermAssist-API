<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Service\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPlanController extends Controller
{
    public function __construct(private PlanService $planService) {}

    /**
     * Display a listing of all subscription plans.
     */
    public function index(): JsonResponse
    {
        return $this->planService->listPlans();
    }

    /**
     * Store a newly created subscription plan.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:plans,slug',
            'tier_type' => 'required|string|in:individual,doctor_multi_clinic,clinic_multi_doctor',
            'price_monthly' => 'required|numeric|min:0',
            'price_annual' => 'required|numeric|min:0',
            'max_doctors' => 'nullable|integer|min:1',
            'max_clinics' => 'nullable|integer|min:1',
            'features' => 'nullable|array',
            'trial_period_days' => 'nullable|integer|min:0',
            'grace_period_days' => 'nullable|integer|min:0',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        return $this->planService->createPlan($validated);
    }

    /**
     * Display the specified subscription plan.
     */
    public function show(Plan $plan): JsonResponse
    {
        return $this->planService->getPlan($plan);
    }

    /**
     * Update the specified subscription plan.
     */
    public function update(Request $request, Plan $plan): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|required|string|max:255|unique:plans,slug,'.$plan->id,
            'tier_type' => 'sometimes|required|string|in:individual,doctor_multi_clinic,clinic_multi_doctor',
            'price_monthly' => 'sometimes|required|numeric|min:0',
            'price_annual' => 'sometimes|required|numeric|min:0',
            'max_doctors' => 'nullable|integer|min:1',
            'max_clinics' => 'nullable|integer|min:1',
            'features' => 'nullable|array',
            'trial_period_days' => 'nullable|integer|min:0',
            'grace_period_days' => 'nullable|integer|min:0',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        return $this->planService->updatePlan($plan, $validated);
    }

    /**
     * Toggle active status of a plan.
     */
    public function toggleActive(Plan $plan): JsonResponse
    {
        return $this->planService->toggleActive($plan);
    }

    /**
     * Remove the specified subscription plan.
     */
    public function destroy(Plan $plan): JsonResponse
    {
        return $this->planService->deletePlan($plan);
    }
}
