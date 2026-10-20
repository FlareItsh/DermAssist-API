<?php

namespace App\Service;

use App\Events\ModelTrainingProgressUpdated;
use App\Models\Diagnosis;
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
            $seen = [];
            if (Storage::disk($this->disk)->exists($dir)) {
                foreach (Storage::disk($this->disk)->files($dir) as $f) {
                    $seen[basename($f)] = true;
                }
            }

            $diagScans = Diagnosis::query()
                ->whereNotNull('image_path')
                ->whereRaw('LOWER(label) = ?', [$cat])
                ->pluck('image_path');
            foreach ($diagScans as $ip) {
                if (! empty($ip)) {
                    $seen[basename($ip)] = true;
                }
            }

            $count = count($seen);
            $gatheredCounts[$cat] = $count;
            $totalGathered += $count;
        }

        // 2. Gather Out-of-Scope Research Dataset Candidates
        $oosCounts = [];
        $totalOos = 0;
        $defaultOosCategories = ['psoriasis', 'ringworm', 'vitiligo', 'melanoma', 'hives', 'warts', 'lupus', 'rosacea'];
        $existingOosDirs = Storage::disk($this->disk)->exists('out_of_scope_dataset')
            ? array_map('basename', Storage::disk($this->disk)->directories('out_of_scope_dataset'))
            : [];
        $oosCategories = array_unique(array_merge($defaultOosCategories, $existingOosDirs));
        foreach ($oosCategories as $oosCat) {
            $oosDir = 'out_of_scope_dataset/'.$oosCat;
            if (Storage::disk($this->disk)->exists($oosDir)) {
                $c = count(Storage::disk($this->disk)->files($oosDir));
                $oosCounts[$oosCat] = $c;
                $totalOos += $c;
            } else {
                $oosCounts[$oosCat] = 0;
            }
        }

        // 3. Query AI Python service for baseline dataset stats and active model
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
            'out_of_scope_candidates' => [
                'total' => $totalOos,
                'by_category' => $oosCounts,
            ],
            'ai_service' => $aiStats,
        ]);
    }

    /**
     * Get candidate clinical scans and dataset images with raw and local storage tangibility statuses.
     */
    public function getTrainingImages(?string $category = null): JsonResponse
    {
        $allowedCategories = ['acne', 'eczema', 'herpes'];
        $filterCategory = $category ? strtolower(trim($category)) : null;

        if ($filterCategory && ! in_array($filterCategory, $allowedCategories)) {
            return response()->json([
                'data' => [],
                'summary' => [
                    'total' => 0,
                    'tangible_count' => 0,
                    'in_local_storage_count' => 0,
                    'in_raw_count' => 0,
                ],
            ]);
        }

        $targetCategories = $filterCategory ? [$filterCategory] : $allowedCategories;

        // Path to Algorithm's data/raw directory
        $algorithmsRawPath = base_path('../DermAssist-Algorithms/data/raw');
        $hasAlgorithmsRaw = file_exists($algorithmsRawPath) && is_dir($algorithmsRawPath);

        // 1. Fetch diagnosis scans
        $query = Diagnosis::query()
            ->whereNotNull('image_path')
            ->where(function ($q) use ($targetCategories) {
                foreach ($targetCategories as $cat) {
                    $q->orWhereRaw('LOWER(label) = ?', [$cat]);
                }
            })
            ->latest();

        $diagnoses = $query->get();

        $items = [];
        $seenFilenames = [];
        $lastTrained = $this->getLastTrainedAt();

        foreach ($diagnoses as $diag) {
            $filename = basename($diag->image_path);
            $catSlug = strtolower(trim($diag->label));
            $catTitle = ucfirst($catSlug);

            $seenFilenames[$filename] = true;

            // Check if present in Laravel local dataset storage
            $isInLocalStorage = (bool) $diag->contributed_to_dataset
                || Storage::disk($this->disk)->exists($this->datasetDir.'/'.$catSlug.'/'.$filename);

            // Check if present in Algorithms raw storage
            $isInRaw = false;
            if ($hasAlgorithmsRaw) {
                $rawCatPath = $algorithmsRawPath.DIRECTORY_SEPARATOR.$catTitle;
                if (is_dir($rawCatPath)) {
                    $isInRaw = file_exists($rawCatPath.DIRECTORY_SEPARATOR.'webapp_'.$filename)
                        || file_exists($rawCatPath.DIRECTORY_SEPARATOR.$filename);
                }
            }

            // Storage Tangibility Rule: Intangible if already in raw OR local storage
            $isTangible = ! $isInLocalStorage && ! $isInRaw;
            $isSynced = $isInLocalStorage || $isInRaw;
            $syncStatus = $isSynced ? 'synced' : 'needs_sync';

            // Trained Status: True only if synced AND contributed/created at or before the last training run
            $isTrained = false;
            if ($isSynced && $lastTrained) {
                $diagTime = $diag->contributed_at ?? $diag->created_at;
                $isTrained = $diagTime ? $diagTime->lte($lastTrained) : false;
            }
            $stage = $isTrained ? 'trained' : ($isSynced ? 'synced' : 'pending');

            $storageStatus = 'ready';
            if ($isInRaw && $isInLocalStorage) {
                $storageStatus = 'both';
            } elseif ($isInRaw) {
                $storageStatus = 'raw';
            } elseif ($isInLocalStorage) {
                $storageStatus = 'local_storage';
            }

            $items[] = [
                'uuid' => $diag->uuid,
                'filename' => $filename,
                'file_name' => $filename,
                'image_url' => Storage::disk($this->disk)->url($diag->image_path),
                'category' => $catSlug,
                'label' => ucwords($catSlug),
                'confidence' => (float) $diag->confidence,
                'patient_consented' => (bool) $diag->patient_consented_dataset,
                'contributed_to_dataset' => (bool) $diag->contributed_to_dataset,
                'created_at' => $diag->created_at?->toIso8601String(),
                'is_in_local_storage' => $isInLocalStorage,
                'is_in_raw' => $isInRaw,
                'is_tangible' => $isTangible,
                'is_synced' => $isSynced,
                'sync_status' => $syncStatus,
                'is_trained' => $isTrained,
                'stage' => $stage,
                'storage_status' => $storageStatus,
                'source' => 'diagnosis',
            ];
        }

        // 2. Also check if there are standalone images in dataset storage not linked to a diagnosis
        foreach ($targetCategories as $catSlug) {
            $catDir = $this->datasetDir.'/'.$catSlug;
            $catTitle = ucfirst($catSlug);
            if (! Storage::disk($this->disk)->exists($catDir)) {
                continue;
            }

            $files = Storage::disk($this->disk)->files($catDir);
            foreach ($files as $file) {
                $fname = basename($file);
                if (isset($seenFilenames[$fname])) {
                    continue;
                }

                $isInRaw = false;
                if ($hasAlgorithmsRaw) {
                    $rawCatPath = $algorithmsRawPath.DIRECTORY_SEPARATOR.$catTitle;
                    if (is_dir($rawCatPath)) {
                        $isInRaw = file_exists($rawCatPath.DIRECTORY_SEPARATOR.'webapp_'.$fname)
                            || file_exists($rawCatPath.DIRECTORY_SEPARATOR.$fname);
                    }
                }

                $items[] = [
                    'uuid' => md5($file),
                    'filename' => $fname,
                    'file_name' => $fname,
                    'image_url' => Storage::disk($this->disk)->url($file),
                    'category' => $catSlug,
                    'label' => ucwords($catSlug),
                    'confidence' => 1.0,
                    'patient_consented' => true,
                    'contributed_to_dataset' => true,
                    'created_at' => null,
                    'is_in_local_storage' => true,
                    'is_in_raw' => $isInRaw,
                    'is_tangible' => ! $isInRaw,
                    'is_synced' => true,
                    'sync_status' => 'synced',
                    'is_trained' => true,
                    'stage' => 'trained',
                    'storage_status' => $isInRaw ? 'both' : 'local_storage',
                    'source' => 'dataset_gallery',
                ];
            }
        }

        $tangibleCount = count(array_filter($items, fn ($i) => $i['is_tangible']));
        $intangibleCount = count($items) - $tangibleCount;
        $inLocalStorageCount = count(array_filter($items, fn ($i) => $i['is_in_local_storage']));
        $inRawCount = count(array_filter($items, fn ($i) => $i['is_in_raw']));
        $syncedCount = count(array_filter($items, fn ($i) => $i['is_synced']));
        $needsSyncCount = count($items) - $syncedCount;
        $trainedCount = count(array_filter($items, fn ($i) => $i['is_trained']));
        $syncedUntrainedCount = count(array_filter($items, fn ($i) => $i['is_synced'] && ! $i['is_trained']));

        $byCategory = [
            'acne' => count(array_filter($items, fn ($i) => $i['category'] === 'acne')),
            'eczema' => count(array_filter($items, fn ($i) => $i['category'] === 'eczema')),
            'herpes' => count(array_filter($items, fn ($i) => $i['category'] === 'herpes')),
        ];

        return response()->json([
            'data' => $items,
            'images' => $items,
            'total' => count($items),
            'tangible_count' => $tangibleCount,
            'intangible_count' => $intangibleCount,
            'synced_count' => $syncedCount,
            'needs_sync_count' => $needsSyncCount,
            'trained_count' => $trainedCount,
            'synced_untrained_count' => $syncedUntrainedCount,
            'by_category' => $byCategory,
            'summary' => [
                'total' => count($items),
                'tangible_count' => $tangibleCount,
                'intangible_count' => $intangibleCount,
                'in_local_storage_count' => $inLocalStorageCount,
                'in_raw_count' => $inRawCount,
                'synced_count' => $syncedCount,
                'needs_sync_count' => $needsSyncCount,
                'trained_count' => $trainedCount,
                'synced_untrained_count' => $syncedUntrainedCount,
            ],
        ]);
    }

    /**
     * Reassign/transfer candidate scans or dataset gallery images to another category,
     * updating consultation records, dataset storage, and algorithm raw directories.
     *
     * @param  array<string>  $uuids
     */
    public function recategorizeTrainingImages(array $uuids, string $targetCategory): JsonResponse
    {
        $targetSlug = strtolower(trim($targetCategory));
        $allowedCategories = ['acne', 'eczema', 'herpes'];

        if (! in_array($targetSlug, $allowedCategories)) {
            return response()->json(['message' => "Invalid target category '{$targetCategory}'."], 422);
        }

        $targetTitle = ucfirst($targetSlug);
        $algorithmsRawPath = base_path('../DermAssist-Algorithms/data/raw');
        $hasAlgorithmsRaw = file_exists($algorithmsRawPath) && is_dir($algorithmsRawPath);
        $transferredCount = 0;
        $movedFilenames = [];

        $basenames = array_unique(array_filter(array_map(function ($id) {
            $parsed = parse_url($id, PHP_URL_PATH) ?? $id;

            return basename($parsed);
        }, $uuids)));

        // 1. Check for Diagnoses matching UUIDs, exact image_path, or filename
        $diagnoses = Diagnosis::query()
            ->where(function ($q) use ($uuids, $basenames) {
                $q->whereIn('uuid', $uuids)
                    ->orWhereIn('image_path', $uuids);
                foreach ($basenames as $bn) {
                    $q->orWhere('image_path', 'like', "%{$bn}");
                }
            })
            ->get();

        foreach ($diagnoses as $diag) {
            $currentSlug = strtolower(trim($diag->label));
            if ($currentSlug === $targetSlug) {
                continue;
            }

            $currentTitle = ucfirst($currentSlug);
            $filename = basename($diag->image_path);
            $movedFilenames[$filename] = true;

            // Relocate file in Laravel dataset directory if copied/contributed there
            $oldDatasetPath = $this->datasetDir.'/'.$currentSlug.'/'.$filename;
            $newDatasetPath = $this->datasetDir.'/'.$targetSlug.'/'.$filename;

            if (Storage::disk($this->disk)->exists($oldDatasetPath)) {
                $newDir = $this->datasetDir.'/'.$targetSlug;
                if (! Storage::disk($this->disk)->exists($newDir)) {
                    Storage::disk($this->disk)->makeDirectory($newDir);
                }
                Storage::disk($this->disk)->move($oldDatasetPath, $newDatasetPath);
            }

            // Relocate file in PyTorch Algorithms data/raw directory if synced there
            if ($hasAlgorithmsRaw) {
                $oldRawCatPath = $algorithmsRawPath.DIRECTORY_SEPARATOR.$currentTitle;
                $newRawCatPath = $algorithmsRawPath.DIRECTORY_SEPARATOR.$targetTitle;
                if (! is_dir($newRawCatPath)) {
                    @mkdir($newRawCatPath, 0755, true);
                }

                $oldWebappFile = $oldRawCatPath.DIRECTORY_SEPARATOR.'webapp_'.$filename;
                $newWebappFile = $newRawCatPath.DIRECTORY_SEPARATOR.'webapp_'.$filename;
                if (file_exists($oldWebappFile)) {
                    @rename($oldWebappFile, $newWebappFile);
                }

                $oldDirectFile = $oldRawCatPath.DIRECTORY_SEPARATOR.$filename;
                $newDirectFile = $newRawCatPath.DIRECTORY_SEPARATOR.$filename;
                if (file_exists($oldDirectFile)) {
                    @rename($oldDirectFile, $newDirectFile);
                }
            }

            // Update diagnosis record label
            $diag->update([
                'label' => $targetTitle,
            ]);

            $transferredCount++;
        }

        // 2. Also check for dataset gallery files matching md5, filename, path, or URL
        $remainingUuids = array_diff($uuids, $diagnoses->pluck('uuid')->all());
        if (! empty($remainingUuids) || ! empty($basenames)) {
            foreach ($allowedCategories as $catSlug) {
                if ($catSlug === $targetSlug) {
                    continue;
                }

                $catDir = $this->datasetDir.'/'.$catSlug;
                $catTitle = ucfirst($catSlug);
                if (! Storage::disk($this->disk)->exists($catDir)) {
                    continue;
                }

                $files = Storage::disk($this->disk)->files($catDir);
                foreach ($files as $file) {
                    $fname = basename($file);
                    $fileMd5 = md5($file);

                    if (isset($movedFilenames[$fname])) {
                        continue;
                    }

                    if (
                        in_array($fileMd5, $remainingUuids) ||
                        in_array($fname, $remainingUuids) ||
                        in_array($fname, $basenames) ||
                        in_array($file, $remainingUuids)
                    ) {
                        $movedFilenames[$fname] = true;
                        $newDatasetPath = $this->datasetDir.'/'.$targetSlug.'/'.$fname;
                        $newDir = $this->datasetDir.'/'.$targetSlug;
                        if (! Storage::disk($this->disk)->exists($newDir)) {
                            Storage::disk($this->disk)->makeDirectory($newDir);
                        }
                        Storage::disk($this->disk)->move($file, $newDatasetPath);

                        if ($hasAlgorithmsRaw) {
                            $oldRawCatPath = $algorithmsRawPath.DIRECTORY_SEPARATOR.$catTitle;
                            $newRawCatPath = $algorithmsRawPath.DIRECTORY_SEPARATOR.$targetTitle;
                            if (! is_dir($newRawCatPath)) {
                                @mkdir($newRawCatPath, 0755, true);
                            }

                            $oldWebappFile = $oldRawCatPath.DIRECTORY_SEPARATOR.'webapp_'.$fname;
                            $newWebappFile = $newRawCatPath.DIRECTORY_SEPARATOR.'webapp_'.$fname;
                            if (file_exists($oldWebappFile)) {
                                @rename($oldWebappFile, $newWebappFile);
                            }

                            $oldDirectFile = $oldRawCatPath.DIRECTORY_SEPARATOR.$fname;
                            $newDirectFile = $newRawCatPath.DIRECTORY_SEPARATOR.$fname;
                            if (file_exists($oldDirectFile)) {
                                @rename($oldDirectFile, $newDirectFile);
                            }
                        }

                        $transferredCount++;
                    }
                }
            }
        }

        return response()->json([
            'message' => "Successfully transferred {$transferredCount} image(s) to {$targetTitle}.",
            'transferred_count' => $transferredCount,
            'target_category' => $targetSlug,
        ]);
    }

    /**
     * Relocate a single verified diagnosis image to its target training dataset category.
     */
    public function relocateSingleDiagnosisImage(Diagnosis $diagnosis, string $targetCategory): bool
    {
        $rawTarget = trim($targetCategory);
        $targetSlug = strtolower(str_replace([' ', '-'], '_', $rawTarget));
        $targetTitle = ucwords(str_replace('_', ' ', $targetSlug));
        $standardCategories = ['acne', 'eczema', 'herpes'];
        $isStandard = in_array($targetSlug, $standardCategories);

        $currentRaw = trim($diagnosis->label ?? '');
        $currentSlug = strtolower(str_replace([' ', '-'], '_', $currentRaw));
        $currentTitle = ucwords(str_replace('_', ' ', $currentSlug));
        $currentIsStandard = in_array($currentSlug, $standardCategories);

        $filename = basename($diagnosis->image_path);
        if (! $filename) {
            return false;
        }

        // 1. Move file in dataset directories if present
        $oldDatasetDir = $currentIsStandard
            ? $this->datasetDir.'/'.$currentSlug
            : 'out_of_scope_dataset/'.$currentSlug;
        $oldDatasetPath = $oldDatasetDir.'/'.$filename;

        $newDatasetDir = $isStandard
            ? $this->datasetDir.'/'.$targetSlug
            : 'out_of_scope_dataset/'.$targetSlug;
        $newDatasetPath = $newDatasetDir.'/'.$filename;

        if (Storage::disk($this->disk)->exists($oldDatasetPath)) {
            if (! Storage::disk($this->disk)->exists($newDatasetDir)) {
                Storage::disk($this->disk)->makeDirectory($newDatasetDir);
            }
            Storage::disk($this->disk)->move($oldDatasetPath, $newDatasetPath);
        } elseif (Storage::disk($this->disk)->exists($diagnosis->image_path)) {
            // If file was not yet in dataset directory, copy it to the new training category
            if (! Storage::disk($this->disk)->exists($newDatasetDir)) {
                Storage::disk($this->disk)->makeDirectory($newDatasetDir);
            }
            Storage::disk($this->disk)->copy($diagnosis->image_path, $newDatasetPath);
        }

        // 2. Move file in PyTorch Algorithms data/raw directory if synced
        $algorithmsRawPath = base_path('../DermAssist-Algorithms/data/raw');
        if (file_exists($algorithmsRawPath) && is_dir($algorithmsRawPath)) {
            $oldRawCatPath = $algorithmsRawPath.DIRECTORY_SEPARATOR.$currentTitle;
            $newRawCatPath = $algorithmsRawPath.DIRECTORY_SEPARATOR.$targetTitle;
            if (! is_dir($newRawCatPath)) {
                @mkdir($newRawCatPath, 0755, true);
            }

            $oldWebappFile = $oldRawCatPath.DIRECTORY_SEPARATOR.'webapp_'.$filename;
            $newWebappFile = $newRawCatPath.DIRECTORY_SEPARATOR.'webapp_'.$filename;
            if (file_exists($oldWebappFile)) {
                @rename($oldWebappFile, $newWebappFile);
            }

            $oldDirectFile = $oldRawCatPath.DIRECTORY_SEPARATOR.$filename;
            $newDirectFile = $newRawCatPath.DIRECTORY_SEPARATOR.$filename;
            if (file_exists($oldDirectFile)) {
                @rename($oldDirectFile, $newDirectFile);
            }
        }

        // 3. Update diagnosis record
        $diagnosis->update([
            'label' => $targetTitle,
            'is_out_of_scope' => ! $isStandard,
            'out_of_scope_category' => ! $isStandard ? $targetSlug : null,
            'contributed_to_dataset' => true,
            'contributed_at' => now(),
        ]);

        return true;
    }

    /**
     * Completely delete candidate scans or dataset gallery images from disk,
     * consultation records, and the algorithm raw directory.
     *
     * @param  array<string>  $uuids
     */
    public function deleteTrainingImages(array $uuids): JsonResponse
    {
        $algorithmsRawPath = base_path('../DermAssist-Algorithms/data/raw');
        $hasAlgorithmsRaw = file_exists($algorithmsRawPath) && is_dir($algorithmsRawPath);
        $deletedCount = 0;

        // 1. Check for Diagnoses matching the UUIDs
        $diagnoses = Diagnosis::whereIn('uuid', $uuids)->get();
        foreach ($diagnoses as $diag) {
            $catSlug = strtolower(trim($diag->label));
            $catTitle = ucfirst($catSlug);
            $filename = basename($diag->image_path);

            // Delete original diagnosis scan photo if present
            if (! empty($diag->image_path) && Storage::disk($this->disk)->exists($diag->image_path)) {
                Storage::disk($this->disk)->delete($diag->image_path);
            }

            // Delete from webapp dataset if copied
            $datasetPath = $this->datasetDir.'/'.$catSlug.'/'.$filename;
            if (Storage::disk($this->disk)->exists($datasetPath)) {
                Storage::disk($this->disk)->delete($datasetPath);
            }

            // Delete from Algorithms data/raw if synced
            if ($hasAlgorithmsRaw) {
                $rawCatPath = $algorithmsRawPath.DIRECTORY_SEPARATOR.$catTitle;
                if (is_dir($rawCatPath)) {
                    $webappFile = $rawCatPath.DIRECTORY_SEPARATOR.'webapp_'.$filename;
                    $directFile = $rawCatPath.DIRECTORY_SEPARATOR.$filename;
                    if (file_exists($webappFile)) {
                        @unlink($webappFile);
                    }
                    if (file_exists($directFile)) {
                        @unlink($directFile);
                    }
                }
            }

            // Clear image path on the diagnosis so it can never be used or trained on
            $diag->update([
                'image_path' => null,
                'contributed_to_dataset' => false,
            ]);

            $deletedCount++;
        }

        // 2. Also check for dataset gallery files matching md5 or filename
        $remainingUuids = array_diff($uuids, $diagnoses->pluck('uuid')->all());
        if (! empty($remainingUuids)) {
            $categories = ['acne', 'eczema', 'herpes'];
            foreach ($categories as $catSlug) {
                $catDir = $this->datasetDir.'/'.$catSlug;
                $catTitle = ucfirst($catSlug);
                if (! Storage::disk($this->disk)->exists($catDir)) {
                    continue;
                }
                $files = Storage::disk($this->disk)->files($catDir);
                foreach ($files as $file) {
                    $fname = basename($file);
                    $fileMd5 = md5($file);
                    if (in_array($fileMd5, $remainingUuids) || in_array($fname, $remainingUuids)) {
                        Storage::disk($this->disk)->delete($file);

                        if ($hasAlgorithmsRaw) {
                            $rawCatPath = $algorithmsRawPath.DIRECTORY_SEPARATOR.$catTitle;
                            if (is_dir($rawCatPath)) {
                                $webappFile = $rawCatPath.DIRECTORY_SEPARATOR.'webapp_'.$fname;
                                $directFile = $rawCatPath.DIRECTORY_SEPARATOR.$fname;
                                if (file_exists($webappFile)) {
                                    @unlink($webappFile);
                                }
                                if (file_exists($directFile)) {
                                    @unlink($directFile);
                                }
                            }
                        }

                        $deletedCount++;
                    }
                }
            }
        }

        return response()->json([
            'message' => "Successfully deleted {$deletedCount} image(s).",
            'deleted_count' => $deletedCount,
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
                $seen = [];
                if (Storage::disk($this->disk)->exists($dir)) {
                    foreach (Storage::disk($this->disk)->files($dir) as $f) {
                        $seen[basename($f)] = true;
                    }
                }
                $diagScans = Diagnosis::query()
                    ->whereNotNull('image_path')
                    ->whereRaw('LOWER(label) = ?', [$cat])
                    ->pluck('image_path');
                foreach ($diagScans as $ip) {
                    if (! empty($ip)) {
                        $seen[basename($ip)] = true;
                    }
                }
                $count += count($seen);
            }

            return $count;
        }

        $lastTrainedTimestamp = $lastTrained->timestamp;
        $count = 0;
        $basePath = Storage::disk($this->disk)->path('');

        foreach (['acne', 'eczema', 'herpes'] as $cat) {
            $dir = $this->datasetDir.'/'.$cat;
            $seen = [];
            if (Storage::disk($this->disk)->exists($dir)) {
                $files = Storage::disk($this->disk)->files($dir);
                foreach ($files as $file) {
                    $fullPath = $basePath.DIRECTORY_SEPARATOR.$file;
                    if (file_exists($fullPath) && filemtime($fullPath) > $lastTrainedTimestamp) {
                        $seen[basename($file)] = true;
                    }
                }
            }
            $recentDiags = Diagnosis::query()
                ->whereNotNull('image_path')
                ->whereRaw('LOWER(label) = ?', [$cat])
                ->where('created_at', '>', $lastTrained)
                ->pluck('image_path');
            foreach ($recentDiags as $ip) {
                if (! empty($ip)) {
                    $seen[basename($ip)] = true;
                }
            }
            $count += count($seen);
        }

        return $count;
    }

    /**
     * Trigger background retraining of the AI model.
     *
     * @param  array{architecture?: string, epochs?: int, sync_dataset?: bool, learning_rate?: float, expansion_disease?: string, selected_image_uuids?: array<string>, training_mode?: string, fresh_start?: bool}  $options
     */
    public function startTraining(array $options): JsonResponse
    {
        $activeImageFiles = null;

        // If specific candidate diagnoses were selected for retraining, promote them to local dataset storage first
        if (! empty($options['selected_image_uuids']) && is_array($options['selected_image_uuids'])) {
            $selectedUuids = $options['selected_image_uuids'];
            $diagnoses = Diagnosis::whereIn('uuid', $selectedUuids)->get();
            $filenames = [];

            foreach ($diagnoses as $diag) {
                if (empty($diag->image_path) || ! Storage::disk($this->disk)->exists($diag->image_path)) {
                    continue;
                }
                $catSlug = strtolower(trim($diag->label));
                if (! in_array($catSlug, ['acne', 'eczema', 'herpes'])) {
                    continue;
                }
                $basename = basename($diag->image_path);
                $targetPath = $this->datasetDir.'/'.$catSlug.'/'.$basename;
                if (! Storage::disk($this->disk)->exists($targetPath)) {
                    Storage::disk($this->disk)->copy($diag->image_path, $targetPath);
                }
                $diag->update([
                    'contributed_to_dataset' => true,
                    'contributed_at' => now(),
                ]);
                $filenames[] = 'webapp_'.$basename;
            }

            // Also map any dataset gallery files whose md5(file) or filename matches selected UUIDs
            $remainingUuids = array_diff($selectedUuids, $diagnoses->pluck('uuid')->all());
            if (! empty($remainingUuids)) {
                $categories = ['acne', 'eczema', 'herpes'];
                foreach ($categories as $catSlug) {
                    $catDir = $this->datasetDir.'/'.$catSlug;
                    if (! Storage::disk($this->disk)->exists($catDir)) {
                        continue;
                    }
                    $files = Storage::disk($this->disk)->files($catDir);
                    foreach ($files as $file) {
                        $fname = basename($file);
                        $fileMd5 = md5($file);
                        if (in_array($fileMd5, $remainingUuids) || in_array($fname, $remainingUuids)) {
                            $filenames[] = 'webapp_'.$fname;
                        }
                    }
                }
            }

            $activeImageFiles = array_values(array_unique($filenames));
        }

        if (! empty($options['expansion_disease'])) {
            $diseaseSlug = strtolower(trim($options['expansion_disease']));
            $diseaseDir = 'out_of_scope_dataset/'.$diseaseSlug;
            $count = 0;
            if (Storage::disk($this->disk)->exists($diseaseDir)) {
                $count = count(Storage::disk($this->disk)->files($diseaseDir));
            }

            if ($count < 10) {
                $diseaseTitle = ucwords(str_replace('_', ' ', $diseaseSlug));

                return response()->json([
                    'message' => "Insufficient dataset for model expansion: At least 10 verified research images are required for {$diseaseTitle}. Currently {$count} available.",
                    'required' => 10,
                    'current' => $count,
                    'disease' => $diseaseTitle,
                ], 422);
            }
        }

        $shouldSync = (bool) ($options['sync_dataset'] ?? false);

        $payload = [
            'architecture' => $options['architecture'] ?? 'ensemble',
            'epochs' => (int) ($options['epochs'] ?? 5),
            'sync_dataset' => $shouldSync,
            'learning_rate' => $options['learning_rate'] ?? null,
            'expansion_disease' => $options['expansion_disease'] ?? null,
            'training_mode' => $options['training_mode'] ?? 'quick',
            'fresh_start' => (bool) ($options['fresh_start'] ?? false),
        ];

        if ($activeImageFiles !== null) {
            $payload['active_image_files'] = $activeImageFiles;
        }

        try {
            $response = Http::timeout(10)->post($this->aiUrl.'/train/start', $payload);

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
            $data = $response->json();
            if ($response->successful() && is_array($data)) {
                broadcast(new ModelTrainingProgressUpdated($data));
            }

            return response()->json($data, $response->status());
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
            $response = Http::timeout(10)->post($this->aiUrl.'/train/cancel');
            $data = $response->json();
            if ($response->successful() && is_array($data)) {
                broadcast(new ModelTrainingProgressUpdated($data));
            }

            return response()->json($data, $response->status());
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to cancel training: '.$e->getMessage(),
            ], 503);
        }
    }

    /**
     * Manually trigger sync of newly gathered scans to local dataset storage
     * and the AI algorithm training raw directory.
     *
     * @param  array<string>|null  $selectedUuids
     */
    public function syncDataset(?array $selectedUuids = null): JsonResponse
    {
        $allowedCategories = ['acne', 'eczema', 'herpes'];
        $promotedCount = 0;

        $query = Diagnosis::query()
            ->whereNotNull('image_path')
            ->where(function ($q) use ($allowedCategories) {
                foreach ($allowedCategories as $cat) {
                    $q->orWhereRaw('LOWER(label) = ?', [$cat]);
                }
            });

        if (! empty($selectedUuids)) {
            $query->whereIn('uuid', $selectedUuids);
        }

        $diagnoses = $query->get();

        foreach ($diagnoses as $diag) {
            $catSlug = strtolower(trim($diag->label));
            if (! in_array($catSlug, $allowedCategories)) {
                continue;
            }

            if (empty($diag->image_path) || ! Storage::disk($this->disk)->exists($diag->image_path)) {
                continue;
            }

            $filename = basename($diag->image_path);
            $targetPath = $this->datasetDir.'/'.$catSlug.'/'.$filename;

            if (! Storage::disk($this->disk)->exists($targetPath)) {
                Storage::disk($this->disk)->copy($diag->image_path, $targetPath);
                $promotedCount++;
            }

            if (! $diag->contributed_to_dataset) {
                $diag->update([
                    'contributed_to_dataset' => true,
                    'contributed_at' => now(),
                ]);
            }
        }

        $aiResult = null;
        try {
            $response = Http::timeout(15)->post($this->aiUrl.'/dataset/sync');
            if ($response->successful()) {
                $aiResult = $response->json();
            }
        } catch (\Exception $e) {
            $aiResult = ['warning' => 'AI Service unreachable for raw folder sync: '.$e->getMessage()];
        }

        $rawCopiedCount = $aiResult['copied_count'] ?? 0;

        return response()->json([
            'message' => "Successfully synced {$promotedCount} webapp scan(s) to local dataset storage and {$rawCopiedCount} to training raw directory.",
            'promoted_count' => $promotedCount,
            'raw_copied_count' => $rawCopiedCount,
            'ai_result' => $aiResult,
        ]);
    }
}
