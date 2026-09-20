<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Service\ModelTrainingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminModelTrainingController extends Controller
{
    public function __construct(private ModelTrainingService $trainingService) {}

    /**
     * Get dataset statistics and active model information.
     */
    public function stats(): JsonResponse
    {
        return $this->trainingService->getStats();
    }

    /**
     * Start background model retraining.
     */
    public function retrain(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'architecture' => 'nullable|string|in:ensemble,all,swin_transformer,resnet50,efficientnet_v2',
            'epochs' => 'nullable|integer|min:1|max:50',
            'sync_dataset' => 'nullable|boolean',
            'learning_rate' => 'nullable|numeric|min:0.000001|max:0.01',
        ]);

        return $this->trainingService->startTraining($validated);
    }

    /**
     * Get real-time training progress, metrics, and logs.
     */
    public function status(): JsonResponse
    {
        return $this->trainingService->getStatus();
    }

    /**
     * Cancel the active training session.
     */
    public function cancel(): JsonResponse
    {
        return $this->trainingService->cancelTraining();
    }

    /**
     * Synchronize newly gathered scan images to the training pipeline.
     */
    public function sync(): JsonResponse
    {
        return $this->trainingService->syncDataset();
    }

    /**
     * Mark training completion timestamp.
     */
    public function markCompleted(): JsonResponse
    {
        return $this->trainingService->markTrainingCompleted();
    }
}
