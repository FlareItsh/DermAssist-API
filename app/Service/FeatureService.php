<?php

namespace App\Service;

use App\Http\Resources\FeatureResource;
use App\Models\Feature;
use App\Models\Plan;
use App\Repository\FeatureRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class FeatureService
{
    public function __construct(private FeatureRepository $featureRepository) {}

    /**
     * Get all features.
     */
    public function listFeatures(bool $onlyActive = false): JsonResponse
    {
        $features = $onlyActive
            ? $this->featureRepository->getActive()
            : $this->featureRepository->getAll();

        return response()->json([
            'status' => 'success',
            'data' => FeatureResource::collection($features),
        ]);
    }

    /**
     * Create a new feature.
     */
    public function createFeature(array $payload): JsonResponse
    {
        if (empty($payload['code'])) {
            $payload['code'] = Str::snake($payload['name']);
        } else {
            $payload['code'] = Str::snake($payload['code']);
        }

        $feature = $this->featureRepository->create($payload);

        // Attach to all existing plans as unchecked by default
        $plans = Plan::all();
        foreach ($plans as $plan) {
            $plan->planFeatures()->syncWithoutDetaching([
                $feature->id => ['is_included' => false],
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Feature created successfully',
            'data' => new FeatureResource($feature),
        ], 201);
    }

    /**
     * Get a specific feature.
     */
    public function getFeature(Feature $feature): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => new FeatureResource($feature),
        ]);
    }

    /**
     * Update an existing feature.
     */
    public function updateFeature(Feature $feature, array $payload): JsonResponse
    {
        if (isset($payload['code'])) {
            $payload['code'] = Str::snake($payload['code']);
        }

        $updated = $this->featureRepository->update($feature, $payload);

        return response()->json([
            'status' => 'success',
            'message' => 'Feature updated successfully',
            'data' => new FeatureResource($updated),
        ]);
    }

    /**
     * Toggle active state.
     */
    public function toggleActive(Feature $feature): JsonResponse
    {
        $feature->is_active = ! $feature->is_active;
        $feature->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Feature status updated',
            'data' => new FeatureResource($feature),
        ]);
    }

    /**
     * Delete a feature.
     */
    public function deleteFeature(Feature $feature): JsonResponse
    {
        $this->featureRepository->delete($feature);

        return response()->json([
            'status' => 'success',
            'message' => 'Feature deleted successfully',
        ]);
    }
}
