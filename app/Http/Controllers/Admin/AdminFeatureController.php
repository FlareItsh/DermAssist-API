<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Service\FeatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminFeatureController extends Controller
{
    public function __construct(private FeatureService $featureService) {}

    /**
     * Display a listing of features.
     */
    public function index(Request $request): JsonResponse
    {
        $onlyActive = $request->boolean('active_only', false);

        return $this->featureService->listFeatures($onlyActive);
    }

    /**
     * Store a newly created feature.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:255|unique:features,code',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        return $this->featureService->createFeature($validated);
    }

    /**
     * Display the specified feature.
     */
    public function show(Feature $feature): JsonResponse
    {
        return $this->featureService->getFeature($feature);
    }

    /**
     * Update the specified feature.
     */
    public function update(Request $request, Feature $feature): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => 'nullable|string|max:255|unique:features,code,'.$feature->id,
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        return $this->featureService->updateFeature($feature, $validated);
    }

    /**
     * Toggle active state.
     */
    public function toggleActive(Feature $feature): JsonResponse
    {
        return $this->featureService->toggleActive($feature);
    }

    /**
     * Remove the specified feature.
     */
    public function destroy(Feature $feature): JsonResponse
    {
        return $this->featureService->deleteFeature($feature);
    }
}
