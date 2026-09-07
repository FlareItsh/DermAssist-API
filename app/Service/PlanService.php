<?php

namespace App\Service;

use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Repository\PlanRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class PlanService
{
    public function __construct(private PlanRepository $planRepository) {}

    public function listPlans(): JsonResponse
    {
        $plans = $this->planRepository->getAll();

        return response()->json([
            'status' => 'success',
            'data' => PlanResource::collection($plans),
        ]);
    }

    public function createPlan(array $payload): JsonResponse
    {
        if (empty($payload['slug'])) {
            $payload['slug'] = Str::slug($payload['name']);
        }

        $plan = $this->planRepository->create($payload);

        return response()->json([
            'status' => 'success',
            'message' => 'Plan created successfully',
            'data' => new PlanResource($plan),
        ], 201);
    }

    public function getPlan(Plan $plan): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => new PlanResource($plan),
        ]);
    }

    public function updatePlan(Plan $plan, array $payload): JsonResponse
    {
        $updated = $this->planRepository->update($plan, $payload);

        return response()->json([
            'status' => 'success',
            'message' => 'Plan updated successfully',
            'data' => new PlanResource($updated),
        ]);
    }

    public function toggleActive(Plan $plan): JsonResponse
    {
        $toggled = $this->planRepository->toggleActive($plan);

        return response()->json([
            'status' => 'success',
            'message' => 'Plan status updated successfully',
            'data' => new PlanResource($toggled),
        ]);
    }

    public function deletePlan(Plan $plan): JsonResponse
    {
        if ($this->planRepository->hasSubscriptions($plan)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot delete plan with existing subscriptions. Deactivate it instead.',
            ], 422);
        }

        $this->planRepository->delete($plan);

        return response()->json([
            'status' => 'success',
            'message' => 'Plan deleted successfully',
        ]);
    }
}
