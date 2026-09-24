<?php

namespace App\Service;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ModelTrainingService
{
    private string $disk = 'public';

    private string $datasetDir = 'dataset';

    private string $aiUrl;

    public function __construct()
    {
        $this->aiUrl = rtrim(config('services.ai.url', 'http://127.0.0.1:8001'), '/');
    }

    /**
     * Get statistics on baseline dataset and newly gathered clinical scan dataset.
     */
    public function getStats(): JsonResponse
    {
        // 1. Gather counts from local Laravel dataset storage
        $gatheredCounts = [
            'acne' => 0,
            'eczema' => 0,
            'herpes' => 0,
        ];
        $totalGathered = 0;

        foreach (array_keys($gatheredCounts) as $cat) {
            $dir = $this->datasetDir.'/'.$cat;
            if (Storage::disk($this->disk)->exists($dir)) {
                $count = count(Storage::disk($this->disk)->files($dir));
                $gatheredCounts[$cat] = $count;
                $totalGathered += $count;
            }
        }

        // 2. Query AI Python service for baseline dataset stats and active model
        $aiStats = [];
        try {
            $response = Http::timeout(5)->get($this->aiUrl.'/model/stats');
            if ($response->successful()) {
                $aiStats = $response->json();
            }
        } catch (\Exception $e) {
            $aiStats = [
                'error' => 'AI Service unreachable: '.$e->getMessage(),
                'total_baseline_images' => 0,
                'classes' => [],
                'active_architecture' => 'swin_transformer',
                'models_available' => [],
            ];
        }

        return response()->json([
            'gathered_dataset' => [
                'total' => $totalGathered,
                'by_category' => $gatheredCounts,
                'untrained_count' => $this->getUntrainedImageCount(),
                'last_trained_at' => $this->getLastTrainedAt()?->toIso8601String(),
            ],
            'ai_service' => $aiStats,
        ]);
    }

    /**
     * Record the current timestamp as the last successful training time.
     */
    public function markTrainingCompleted(): JsonResponse
    {
        Storage::disk('local')->put('last_trained_at', now()->timestamp);

        return response()->json(['message' => 'Training completion recorded.']);
    }

    /**
     * Get the last training completion timestamp.
     */
    private function getLastTrainedAt(): ?Carbon
    {
        if (! Storage::disk('local')->exists('last_trained_at')) {
            return null;
        }

        $timestamp = (int) Storage::disk('local')->get('last_trained_at');

        return $timestamp > 0 ? Carbon::createFromTimestamp($timestamp) : null;
    }

    /**
     * Count gathered dataset images that were added after the last training run.
     */
    private function getUntrainedImageCount(): int
    {
        $lastTrained = $this->getLastTrainedAt();

        if (! $lastTrained) {
            // Never trained — all gathered images are untrained
            $count = 0;
            foreach (['acne', 'eczema', 'herpes'] as $cat) {
                $dir = $this->datasetDir.'/'.$cat;
                if (Storage::disk($this->disk)->exists($dir)) {
                    $count += count(Storage::disk($this->disk)->files($dir));
                }
            }

            return $count;
        }

        $lastTrainedTimestamp = $lastTrained->timestamp;
        $count = 0;
        $basePath = Storage::disk($this->disk)->path('');

        foreach (['acne', 'eczema', 'herpes'] as $cat) {
            $dir = $this->datasetDir.'/'.$cat;
            if (! Storage::disk($this->disk)->exists($dir)) {
                continue;
            }
            $files = Storage::disk($this->disk)->files($dir);
            foreach ($files as $file) {
                $fullPath = $basePath.DIRECTORY_SEPARATOR.$file;
                if (file_exists($fullPath) && filemtime($fullPath) > $lastTrainedTimestamp) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Trigger background retraining of the AI model.
     *
     * @param  array{architecture?: string, epochs?: int, sync_dataset?: bool, learning_rate?: float}  $options
     */
    public function startTraining(array $options): JsonResponse
    {
        try {
            $response = Http::timeout(10)->post($this->aiUrl.'/train/start', [
                'architecture' => $options['architecture'] ?? 'ensemble',
                'epochs' => (int) ($options['epochs'] ?? 5),
                'sync_dataset' => $options['sync_dataset'] ?? true,
                'learning_rate' => $options['learning_rate'] ?? null,
            ]);

            return response()->json($response->json(), $response->status());
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to connect to AI training service: '.$e->getMessage(),
            ], 503);
        }
    }

    /**
     * Get live status and progress of ongoing training.
     */
    public function getStatus(): JsonResponse
    {
        try {
            $response = Http::timeout(5)->get($this->aiUrl.'/train/status');

            return response()->json($response->json(), $response->status());
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'offline',
                'message' => 'AI Training Service is unreachable.',
                'progress' => 0,
                'error' => $e->getMessage(),
            ], 503);
        }
    }

    /**
     * Cancel the ongoing training session.
     */
    public function cancelTraining(): JsonResponse
    {
        try {
            $response = Http::timeout(5)->post($this->aiUrl.'/train/cancel');

            return response()->json($response->json(), $response->status());
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to cancel training: '.$e->getMessage(),
            ], 503);
        }
    }

    /**
     * Manually trigger sync of newly gathered scans to the training raw dataset.
     */
    public function syncDataset(): JsonResponse
    {
        try {
            $response = Http::timeout(10)->post($this->aiUrl.'/dataset/sync');

            return response()->json($response->json(), $response->status());
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to trigger dataset sync: '.$e->getMessage(),
            ], 503);
        }
    }
}
