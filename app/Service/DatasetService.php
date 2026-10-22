<?php

namespace App\Service;

use App\Http\Resources\DiagnosisResource;
use App\Models\Diagnosis;
use App\Models\User;
use App\Support\RepositoryPathResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DatasetService
{
    private string $disk = 'public';

    private string $datasetDir = 'dataset';

    public function listDatasets(
        ?string $category = null,
        ?int $page = null,
        ?int $perPage = 12,
        bool $all = false,
        ?string $doctorUuid = null,
        ?int $doctorId = null
    ): JsonResponse {
        $categoryImages = [];
        $categoryItems = [];
        $seenFilenames = [];

        $allowedPriorityCategories = ['acne', 'eczema', 'herpes'];
        $allowedDoctorCategories = ['acne', 'eczema', 'herpes', 'out_of_scope'];
        $allowedCategories = $doctorUuid ? $allowedDoctorCategories : $allowedPriorityCategories;

        $rawFilter = $category ? strtolower(trim(str_replace([' ', '-'], '_', $category))) : null;
        if ($rawFilter === 'outofscope') {
            $rawFilter = 'out_of_scope';
        }
        $filterCategory = $rawFilter;

        if ($filterCategory && ! in_array($filterCategory, $allowedCategories)) {
            return response()->json([]);
        }

        $targetCategories = $filterCategory ? [$filterCategory] : $allowedCategories;

        // 1. Read files from storage/app/public/dataset/{category} (Only for global/admin dataset, NOT doctor-scoped)
        if (! $doctorUuid && Storage::disk($this->disk)->exists($this->datasetDir)) {
            $categories = Storage::disk($this->disk)->directories($this->datasetDir);
            foreach ($categories as $categoryPath) {
                $categoryName = strtolower(basename($categoryPath));
                if (! in_array($categoryName, $targetCategories)) {
                    continue;
                }
                $files = Storage::disk($this->disk)->files($categoryPath);
                foreach ($files as $file) {
                    $fname = basename($file);
                    $seenFilenames[$fname] = true;
                    $url = Storage::disk($this->disk)->url($file);
                    $categoryImages[$categoryName][] = $url;
                    $categoryItems[$categoryName][] = [
                        'url' => $url,
                        'filename' => $fname,
                        'is_synced' => true,
                        'sync_status' => 'synced',
                        'source' => 'dataset_gallery',
                        'storage_location' => 'dataset_storage',
                        'created_at' => null,
                    ];
                }
            }
        }

        // 2. Seamlessly aggregate webapp consultation scans from Diagnosis records (Only finalized/contributed scans)
        $diagnosisQuery = Diagnosis::query()
            ->whereNotNull('image_path')
            ->where(function ($q) {
                $q->where('contributed_to_dataset', true)
                    ->orWhere('out_of_scope_contributed', true)
                    ->orWhere('is_out_of_scope', true);
            });

        if ($doctorUuid) {
            $diagnosisQuery->where(function ($q) use ($doctorUuid, $doctorId) {
                $q->where('doctor_uuid', $doctorUuid)
                    ->orWhere('user_uuid', $doctorUuid);
                if ($doctorId) {
                    $q->orWhereHas('clinicalNote', fn ($sq) => $sq->where('doctor_id', $doctorId));
                }
            });
        }

        $diagnoses = $diagnosisQuery->latest()->get();

        foreach ($diagnoses as $diag) {
            $rawLabel = strtolower(trim($diag->label ?? ''));
            $isOos = (bool) $diag->is_out_of_scope
                || (bool) $diag->out_of_scope_contributed
                || ! empty($diag->out_of_scope_category)
                || in_array($rawLabel, ['outofscope', 'out_of_scope', 'inconclusive']);

            $catSlug = $isOos ? 'out_of_scope' : $rawLabel;

            if (empty($catSlug) || ! in_array($catSlug, $targetCategories)) {
                continue;
            }

            $filename = basename($diag->image_path);
            if (isset($seenFilenames[$filename])) {
                continue;
            }

            if (Storage::disk($this->disk)->exists($diag->image_path)) {
                $seenFilenames[$filename] = true;
                $url = Storage::disk($this->disk)->url($diag->image_path);
                $isSynced = (bool) $diag->contributed_to_dataset
                    || (bool) $diag->out_of_scope_contributed
                    || Storage::disk($this->disk)->exists($this->datasetDir.'/'.$catSlug.'/'.$filename)
                    || Storage::disk($this->disk)->exists('out_of_scope_dataset/'.$catSlug.'/'.$filename);

                $categoryImages[$catSlug][] = $url;
                $categoryItems[$catSlug][] = [
                    'url' => $url,
                    'filename' => $filename,
                    'is_synced' => $isSynced,
                    'sync_status' => $isSynced ? 'synced' : 'needs_sync',
                    'source' => 'diagnosis',
                    'storage_location' => $isSynced ? 'dataset_storage' : 'diagnosis_scan',
                    'diagnosis_uuid' => $diag->uuid,
                    'created_at' => $diag->created_at?->toIso8601String(),
                ];
            }
        }

        // 3. Format into standard paginated API response
        $result = [];
        $currentPage = max(1, $page ?? 1);
        $perPageCount = max(1, $perPage ?? 12);

        foreach ($targetCategories as $cat) {
            $rawUrls = $categoryImages[$cat] ?? [];
            $rawItems = $categoryItems[$cat] ?? [];
            $uniqueUrls = array_values(array_unique($rawUrls));
            $total = count($uniqueUrls);

            // Compute global sync metrics across ALL items in this category
            $syncedCount = 0;
            $needsSyncCount = 0;
            foreach ($rawItems as $item) {
                if (! empty($item['is_synced'])) {
                    $syncedCount++;
                } else {
                    $needsSyncCount++;
                }
            }

            if ($total === 0 && $filterCategory === null && ! $doctorUuid) {
                continue;
            }

            if ($all) {
                $pagedUrls = $uniqueUrls;
                $pagedItems = $rawItems;
                $lastPage = max(1, (int) ceil($total / $perPageCount));
            } else {
                $offset = ($currentPage - 1) * $perPageCount;
                $pagedUrls = array_values(array_slice($uniqueUrls, $offset, $perPageCount));
                $pagedItems = array_values(array_slice($rawItems, $offset, $perPageCount));
                $lastPage = max(1, (int) ceil($total / $perPageCount));
            }

            $result[] = [
                'category' => $cat,
                'total' => $total,
                'current_page' => $currentPage,
                'per_page' => $perPageCount,
                'last_page' => $lastPage,
                'synced_count' => $syncedCount,
                'needs_sync_count' => $needsSyncCount,
                'images' => $pagedUrls,
                'items' => $pagedItems,
            ];
        }

        return response()->json($result);
    }

    public function addImage(UploadedFile $file, string $category, ?User $user = null, ?string $doctorUuid = null): JsonResponse
    {
        $categorySlug = Str::slug($category);
        $path = $file->store($this->datasetDir.'/'.$categorySlug, $this->disk);

        $resolvedDocUuid = $doctorUuid ?? ($user && in_array($user->role?->slug, ['doctor', 'secretary']) ? $user->uuid : null);
        if ($resolvedDocUuid) {
            Diagnosis::create([
                'user_uuid' => $user?->uuid ?? $resolvedDocUuid,
                'doctor_uuid' => $resolvedDocUuid,
                'image_path' => $path,
                'label' => ucfirst(strtolower($category)),
                'confidence' => 1.0,
                'probabilities' => [ucfirst(strtolower($category)) => 1.0],
                'status' => 'completed',
                'patient_consented_dataset' => true,
                'contributed_to_dataset' => true,
                'contributed_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Image added to dataset',
            'url' => Storage::disk($this->disk)->url($path),
        ], 201);
    }

    /**
     * Store multiple uploaded images into a dataset category.
     *
     * @param  array<UploadedFile>  $files
     */
    public function addImages(array $files, string $category, ?User $user = null, ?string $doctorUuid = null): JsonResponse
    {
        $categorySlug = Str::slug($category);
        $urls = [];
        $resolvedDocUuid = $doctorUuid ?? ($user && in_array($user->role?->slug, ['doctor', 'secretary']) ? $user->uuid : null);

        foreach ($files as $file) {
            $path = $file->store($this->datasetDir.'/'.$categorySlug, $this->disk);
            $urls[] = Storage::disk($this->disk)->url($path);

            if ($resolvedDocUuid) {
                Diagnosis::create([
                    'user_uuid' => $user?->uuid ?? $resolvedDocUuid,
                    'doctor_uuid' => $resolvedDocUuid,
                    'image_path' => $path,
                    'label' => ucfirst(strtolower($category)),
                    'confidence' => 1.0,
                    'probabilities' => [ucfirst(strtolower($category)) => 1.0],
                    'status' => 'completed',
                    'patient_consented_dataset' => true,
                    'contributed_to_dataset' => true,
                    'contributed_at' => now(),
                ]);
            }
        }

        return response()->json([
            'message' => count($urls).' images added to dataset',
            'urls' => $urls,
        ], 201);
    }

    /**
     * Resolve a dataset or diagnosis image URL or path into a relative storage path.
     */
    private function resolveRelativeDatasetPath(string $url): ?string
    {
        $relativePath = $url;
        $storagePos = strpos($relativePath, '/storage/');
        if ($storagePos !== false) {
            $relativePath = substr($relativePath, $storagePos + 9);
        } elseif (str_starts_with($relativePath, 'storage/')) {
            $relativePath = substr($relativePath, 8);
        } else {
            $parsed = parse_url($relativePath, PHP_URL_PATH);
            if ($parsed) {
                $storagePos = strpos($parsed, '/storage/');
                $relativePath = $storagePos !== false ? substr($parsed, $storagePos + 9) : ltrim($parsed, '/');
            }
        }

        $relativePath = ltrim($relativePath, '/');

        $isDataset = str_starts_with($relativePath, $this->datasetDir.'/');
        $isDiagnoses = str_starts_with($relativePath, 'diagnoses/');

        if ((! $isDataset && ! $isDiagnoses) || str_contains($relativePath, '..')) {
            return null;
        }

        return $relativePath;
    }

    public function removeImage(string $url, ?User $user = null, ?string $doctorUuid = null): JsonResponse
    {
        $relativePath = $this->resolveRelativeDatasetPath($url);

        if (! $relativePath) {
            return response()->json(['error' => 'Invalid dataset image path'], 403);
        }

        $filename = basename($relativePath);
        $algorithmsRawPath = base_path('../DermAssist-Algorithms/data/raw');
        $hasAlgorithmsRaw = file_exists($algorithmsRawPath) && is_dir($algorithmsRawPath);

        if ($doctorUuid) {
            $diag = Diagnosis::where('image_path', $relativePath)
                ->orWhere('image_path', 'like', "%{$filename}")
                ->first();

            if (! $diag || ($diag->doctor_uuid !== $doctorUuid && $diag->user_uuid !== $doctorUuid)) {
                return response()->json(['error' => 'Unauthorized: You can only remove your own clinical scans.'], 403);
            }
        }

        if (Storage::disk($this->disk)->exists($relativePath)) {
            Storage::disk($this->disk)->delete($relativePath);

            // If it's a diagnosis scan or linked to diagnosis, clear image_path on Diagnosis record
            $diag = Diagnosis::where('image_path', $relativePath)
                ->orWhere('image_path', 'like', "%{$filename}")
                ->first();

            if ($diag) {
                $catSlug = strtolower(trim($diag->label));
                $catTitle = ucfirst($catSlug);

                // Also delete from dataset if a copy was there
                $copyInDataset = $this->datasetDir.'/'.$catSlug.'/'.$filename;
                if (Storage::disk($this->disk)->exists($copyInDataset)) {
                    Storage::disk($this->disk)->delete($copyInDataset);
                }

                // Delete from algorithm data/raw if synced
                if ($hasAlgorithmsRaw) {
                    $rawCatPath = $algorithmsRawPath.DIRECTORY_SEPARATOR.$catTitle;
                    if (is_dir($rawCatPath)) {
                        @unlink($rawCatPath.DIRECTORY_SEPARATOR.'webapp_'.$filename);
                        @unlink($rawCatPath.DIRECTORY_SEPARATOR.$filename);
                    }
                }

                $diag->update([
                    'image_path' => null,
                    'contributed_to_dataset' => false,
                ]);
            } else {
                // If it was in dataset/, also clean up from algorithm raw if present
                if ($hasAlgorithmsRaw) {
                    foreach (['Acne', 'Eczema', 'Herpes'] as $catTitle) {
                        $rawCatPath = $algorithmsRawPath.DIRECTORY_SEPARATOR.$catTitle;
                        if (is_dir($rawCatPath)) {
                            @unlink($rawCatPath.DIRECTORY_SEPARATOR.'webapp_'.$filename);
                            @unlink($rawCatPath.DIRECTORY_SEPARATOR.$filename);
                        }
                    }
                }
            }

            return response()->json(['message' => 'Image deleted successfully']);
        }

        return response()->json(['error' => 'File not found or invalid URL'], 404);
    }

    /**
     * Remove multiple images from the dataset in a single operation.
     *
     * @param  array<string>  $urls
     */
    public function removeImages(array $urls, ?User $user = null, ?string $doctorUuid = null): JsonResponse
    {
        $deletedCount = 0;
        $algorithmsRawPath = base_path('../DermAssist-Algorithms/data/raw');
        $hasAlgorithmsRaw = file_exists($algorithmsRawPath) && is_dir($algorithmsRawPath);

        foreach ($urls as $url) {
            $relativePath = $this->resolveRelativeDatasetPath($url);
            if (! $relativePath || ! Storage::disk($this->disk)->exists($relativePath)) {
                continue;
            }

            $filename = basename($relativePath);

            if ($doctorUuid) {
                $diag = Diagnosis::where('image_path', $relativePath)
                    ->orWhere('image_path', 'like', "%{$filename}")
                    ->first();

                if (! $diag || ($diag->doctor_uuid !== $doctorUuid && $diag->user_uuid !== $doctorUuid)) {
                    continue;
                }
            }

            Storage::disk($this->disk)->delete($relativePath);

            $diag = Diagnosis::where('image_path', $relativePath)
                ->orWhere('image_path', 'like', "%{$filename}")
                ->first();

            if ($diag) {
                $catSlug = strtolower(trim($diag->label));
                $catTitle = ucfirst($catSlug);

                $copyInDataset = $this->datasetDir.'/'.$catSlug.'/'.$filename;
                if (Storage::disk($this->disk)->exists($copyInDataset)) {
                    Storage::disk($this->disk)->delete($copyInDataset);
                }

                if ($hasAlgorithmsRaw) {
                    $rawCatPath = $algorithmsRawPath.DIRECTORY_SEPARATOR.$catTitle;
                    if (is_dir($rawCatPath)) {
                        @unlink($rawCatPath.DIRECTORY_SEPARATOR.'webapp_'.$filename);
                        @unlink($rawCatPath.DIRECTORY_SEPARATOR.$filename);
                    }
                }

                $diag->update([
                    'image_path' => null,
                    'contributed_to_dataset' => false,
                ]);
            } else {
                if ($hasAlgorithmsRaw) {
                    foreach (['Acne', 'Eczema', 'Herpes'] as $catTitle) {
                        $rawCatPath = $algorithmsRawPath.DIRECTORY_SEPARATOR.$catTitle;
                        if (is_dir($rawCatPath)) {
                            @unlink($rawCatPath.DIRECTORY_SEPARATOR.'webapp_'.$filename);
                            @unlink($rawCatPath.DIRECTORY_SEPARATOR.$filename);
                        }
                    }
                }
            }

            $deletedCount++;
        }

        return response()->json([
            'message' => "{$deletedCount} images deleted successfully",
            'deleted_count' => $deletedCount,
        ]);
    }

    public function saveFromDiagnosis(string $diagnosisUuid, ?User $actor = null): JsonResponse
    {
        $diagnosis = Diagnosis::where('uuid', $diagnosisUuid)->firstOrFail();

        if ($diagnosis->is_technical_failure) {
            return response()->json([
                'status' => 'error',
                'message' => 'Technical failure scans cannot be added to the AI retraining dataset.',
            ], 422);
        }

        // Gate 1: Strict Patient Consent verification (zero bypasses for doctor registration or doctor uuid)
        $isPatientConsented = false;
        if (! empty($diagnosis->patient_uuid)) {
            $patient = User::where('uuid', $diagnosis->patient_uuid)->first();
            if ($patient && $patient->consent_dataset && $diagnosis->patient_consented_dataset) {
                $isPatientConsented = true;
            }
        } elseif ($diagnosis->patient_consented_dataset) {
            $isPatientConsented = true;
        }

        if (! $isPatientConsented) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot save to dataset: Patient has not consented to AI retraining dataset contribution.',
            ], 403);
        }

        $category = Str::slug($diagnosis->label);
        $allowedPriorityCategories = ['acne', 'eczema', 'herpes'];

        if (! in_array($category, $allowedPriorityCategories)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot save to priority dataset: Only verified Acne, Eczema, or Herpes scans can be contributed.',
            ], 422);
        }

        // Gate 2: Doctor Authorization & Gate 3 Approval
        $diagnosis->update([
            'doctor_authorized_dataset' => true,
            'doctor_authorized_at' => $diagnosis->doctor_authorized_at ?? now(),
            'admin_approved_dataset' => true,
            'admin_approved_at' => now(),
            'admin_approved_by' => $actor?->id,
        ]);

        // All 3 Gates passed: Ingest physical files
        $path = $diagnosis->image_path;
        if (! Storage::disk($this->disk)->exists($path)) {
            return response()->json(['error' => 'Image not found'], 404);
        }

        $filename = basename($path);
        $datasetPath = $this->datasetDir.'/'.$category.'/'.$filename;

        if (! Storage::disk($this->disk)->exists($datasetPath)) {
            Storage::disk($this->disk)->copy($path, $datasetPath);
        }

        // Synchronize to algorithms/data/raw
        $algosRawDir = RepositoryPathResolver::getAlgorithmsDataRawPath();
        if ($algosRawDir && is_dir($algosRawDir)) {
            $catDir = $algosRawDir.'/'.ucfirst($category);
            if (! is_dir($catDir)) {
                @mkdir($catDir, 0755, true);
            }
            $targetAlgoFile = $catDir.'/webapp_'.$filename;
            if (! file_exists($targetAlgoFile)) {
                @copy(Storage::disk($this->disk)->path($path), $targetAlgoFile);
            }
        }

        $diagnosis->update([
            'contributed_to_dataset' => true,
            'contributed_at' => now(),
        ]);

        return response()->json([
            'message' => 'Saved to dataset',
            'status' => 'ingested',
            'contributed' => true,
        ]);
    }

    public function adminApproveDiagnosis(string $diagnosisUuid, User $admin): JsonResponse
    {
        $diagnosis = Diagnosis::where('uuid', $diagnosisUuid)->firstOrFail();

        if ($diagnosis->is_technical_failure) {
            return response()->json(['error' => 'Cannot approve a technical failure scan.'], 422);
        }

        // Check Gate 1: Patient Consent
        $isPatientConsented = false;
        if (! empty($diagnosis->patient_uuid)) {
            $patient = User::where('uuid', $diagnosis->patient_uuid)->first();
            if ($patient && $patient->consent_dataset && $diagnosis->patient_consented_dataset) {
                $isPatientConsented = true;
            }
        } elseif ($diagnosis->patient_consented_dataset) {
            $isPatientConsented = true;
        }

        if (! $isPatientConsented) {
            return response()->json(['error' => 'Gate 1 Failed: Patient has not consented to dataset contribution.'], 403);
        }

        // Check Gate 2: Doctor Authorization
        if (! $diagnosis->doctor_authorized_dataset) {
            return response()->json(['error' => 'Gate 2 Failed: Doctor has not authorized this scan for dataset contribution.'], 422);
        }

        $category = Str::slug($diagnosis->label);
        $allowedPriorityCategories = ['acne', 'eczema', 'herpes'];
        if (! in_array($category, $allowedPriorityCategories)) {
            return response()->json(['error' => 'Only priority categories (acne, eczema, herpes) can be ingested.'], 422);
        }

        $path = $diagnosis->image_path;
        if (! Storage::disk($this->disk)->exists($path)) {
            return response()->json(['error' => 'Diagnosis image file not found on disk.'], 404);
        }

        // Gate 3: Admin Approval stamp
        $diagnosis->update([
            'admin_approved_dataset' => true,
            'admin_approved_at' => now(),
            'admin_approved_by' => $admin->id,
        ]);

        $filename = basename($path);
        $datasetPath = $this->datasetDir.'/'.$category.'/'.$filename;
        if (! Storage::disk($this->disk)->exists($datasetPath)) {
            Storage::disk($this->disk)->copy($path, $datasetPath);
        }

        // Synchronize to algorithms/data/raw
        $algosRawDir = RepositoryPathResolver::getAlgorithmsDataRawPath();
        if ($algosRawDir && is_dir($algosRawDir)) {
            $catDir = $algosRawDir.'/'.ucfirst($category);
            if (! is_dir($catDir)) {
                @mkdir($catDir, 0755, true);
            }
            $targetAlgoFile = $catDir.'/webapp_'.$filename;
            if (! file_exists($targetAlgoFile)) {
                @copy(Storage::disk($this->disk)->path($path), $targetAlgoFile);
            }
        }

        $diagnosis->update([
            'contributed_to_dataset' => true,
            'contributed_at' => now(),
        ]);

        return response()->json([
            'message' => 'Diagnosis scan approved by admin and ingested into dataset.',
            'diagnosis' => new DiagnosisResource($diagnosis),
        ]);
    }

    public function revokePatientDatasetContributions(User $patient): int
    {
        $diagnoses = Diagnosis::where(function ($q) use ($patient) {
            $q->where('patient_uuid', $patient->uuid)
                ->orWhere('user_uuid', $patient->uuid);
        })
            ->where('contributed_to_dataset', true)
            ->get();

        $prunedCount = 0;
        $algosRawDir = RepositoryPathResolver::getAlgorithmsDataRawPath();

        foreach ($diagnoses as $diagnosis) {
            if (! empty($diagnosis->image_path)) {
                $filename = basename($diagnosis->image_path);
                $category = Str::slug($diagnosis->label);

                // Check standard priority dataset directories
                $possiblePaths = [
                    $this->datasetDir.'/'.$category.'/'.$filename,
                    $this->datasetDir.'/acne/'.$filename,
                    $this->datasetDir.'/eczema/'.$filename,
                    $this->datasetDir.'/herpes/'.$filename,
                ];

                foreach ($possiblePaths as $datasetFilePath) {
                    if (Storage::disk($this->disk)->exists($datasetFilePath)) {
                        Storage::disk($this->disk)->delete($datasetFilePath);
                        $prunedCount++;
                    }
                }

                // Check out-of-scope dataset directory
                $outOfScopePath = 'out_of_scope_dataset/'.$category.'/'.$filename;
                if (Storage::disk($this->disk)->exists($outOfScopePath)) {
                    Storage::disk($this->disk)->delete($outOfScopePath);
                    $prunedCount++;
                }

                // Prune from algorithms/data/raw
                if ($algosRawDir && is_dir($algosRawDir)) {
                    foreach (['acne', 'eczema', 'herpes', $category] as $cat) {
                        $variants = [ucfirst($cat), strtolower($cat), $cat];
                        foreach (array_unique($variants) as $c) {
                            $algoFile = $algosRawDir.'/'.$c.'/webapp_'.$filename;
                            if (file_exists($algoFile)) {
                                @unlink($algoFile);
                            }
                        }
                    }
                }
            }

            $diagnosis->update([
                'contributed_to_dataset' => false,
                'patient_consented_dataset' => false,
                'doctor_authorized_dataset' => false,
                'admin_approved_dataset' => false,
                'contributed_at' => null,
            ]);
        }

        return $prunedCount;
    }

    public function downloadZip(?string $category = null, ?string $doctorUuid = null, ?int $doctorId = null): JsonResponse|BinaryFileResponse
    {
        if ($doctorUuid) {
            return $this->downloadDoctorScansZip($category, $doctorUuid, $doctorId);
        }

        $zipFileName = $category ? 'dataset_'.Str::slug($category).'.zip' : 'dataset_all.zip';
        $zipPath = storage_path('app/public/'.$zipFileName);

        $directories = $category
            ? [$this->datasetDir.'/'.Str::slug($category)]
            : Storage::disk($this->disk)->directories($this->datasetDir);

        $hasFiles = false;
        foreach ($directories as $dir) {
            if (Storage::disk($this->disk)->exists($dir) && count(Storage::disk($this->disk)->files($dir)) > 0) {
                $hasFiles = true;
                break;
            }
        }

        if (! $hasFiles) {
            return response()->json(['error' => 'No images found to download'], 404);
        }

        $baseDir = Storage::disk($this->disk)->path($this->datasetDir);

        if (PHP_OS_FAMILY === 'Windows') {
            $targetPath = $category ? $baseDir.DIRECTORY_SEPARATOR.Str::slug($category) : $baseDir;
            $cmd = sprintf(
                'powershell -NoProfile -NonInteractive -Command "Compress-Archive -Path %s\* -DestinationPath %s -Force"',
                escapeshellarg($targetPath),
                escapeshellarg($zipPath)
            );
        } else {
            if ($category) {
                $catSlug = Str::slug($category);
                $cmd = sprintf('cd %s && zip -r %s %s', escapeshellarg($baseDir), escapeshellarg($zipPath), escapeshellarg($catSlug));
            } else {
                $cmd = sprintf('cd %s && zip -r %s .', escapeshellarg($baseDir), escapeshellarg($zipPath));
            }
        }

        exec($cmd, $output, $returnVar);

        if ($returnVar !== 0 || ! file_exists($zipPath)) {
            return response()->json(['error' => 'Could not create zip file'], 500);
        }

        return response()->download($zipPath)->deleteFileAfterSend(true);
    }

    private function downloadDoctorScansZip(?string $category, string $doctorUuid, ?int $doctorId = null): JsonResponse|BinaryFileResponse
    {
        $catFilter = $category ? strtolower(trim($category)) : null;
        $query = Diagnosis::query()
            ->whereNotNull('image_path')
            ->where('contributed_to_dataset', true)
            ->where(function ($q) use ($doctorUuid, $doctorId) {
                $q->where('doctor_uuid', $doctorUuid)
                    ->orWhere('user_uuid', $doctorUuid);
                if ($doctorId) {
                    $q->orWhereHas('clinicalNote', fn ($sq) => $sq->where('doctor_id', $doctorId));
                }
            });

        if ($catFilter) {
            $query->whereRaw('LOWER(label) = ?', [$catFilter]);
        }

        $diagnoses = $query->get();

        $existingFiles = [];
        foreach ($diagnoses as $diag) {
            if ($diag->image_path && Storage::disk($this->disk)->exists($diag->image_path)) {
                $cat = strtolower(trim($diag->label ?: 'uncategorized'));
                $existingFiles[] = [
                    'source_path' => Storage::disk($this->disk)->path($diag->image_path),
                    'zip_path' => $catFilter ? basename($diag->image_path) : $cat.'/'.basename($diag->image_path),
                ];
            }
        }

        if (empty($existingFiles)) {
            return response()->json(['error' => 'No images found to download'], 404);
        }

        $zipFileName = 'doctor_scans_'.($catFilter ? Str::slug($catFilter).'_' : '').Str::random(8).'.zip';
        $zipFullPath = storage_path('app/public/'.$zipFileName);

        $tempDir = storage_path('app/temp_zip_'.Str::random(12));
        mkdir($tempDir, 0755, true);

        try {
            foreach ($existingFiles as $item) {
                $dest = $tempDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $item['zip_path']);
                $destDir = dirname($dest);
                if (! is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }
                copy($item['source_path'], $dest);
            }

            if (PHP_OS_FAMILY === 'Windows') {
                $cmd = sprintf(
                    'powershell -NoProfile -NonInteractive -Command "Compress-Archive -Path %s\* -DestinationPath %s -Force"',
                    escapeshellarg($tempDir),
                    escapeshellarg($zipFullPath)
                );
            } else {
                $cmd = sprintf(
                    'cd %s && zip -r %s .',
                    escapeshellarg($tempDir),
                    escapeshellarg($zipFullPath)
                );
            }

            exec($cmd, $output, $returnVar);

            if ($returnVar !== 0 || ! file_exists($zipFullPath)) {
                return response()->json(['error' => 'Could not create zip file'], 500);
            }

            return response()->download($zipFullPath)->deleteFileAfterSend(true);
        } finally {
            $this->deleteDirectoryRecursive($tempDir);
        }
    }

    private function deleteDirectoryRecursive(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? $this->deleteDirectoryRecursive($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
