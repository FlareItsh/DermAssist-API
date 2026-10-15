<?php

namespace App\Service;

use App\Models\Diagnosis;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DatasetService
{
    private string $disk = 'public';

    private string $datasetDir = 'dataset';

    public function listDatasets(): JsonResponse
    {
        $categoryImages = [];
        $categoryItems = [];
        $seenFilenames = [];

        // 1. Read files from storage/app/public/dataset/{category}
        if (Storage::disk($this->disk)->exists($this->datasetDir)) {
            $categories = Storage::disk($this->disk)->directories($this->datasetDir);
            foreach ($categories as $categoryPath) {
                $categoryName = strtolower(basename($categoryPath));
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

        // 2. Seamlessly aggregate webapp consultation scans from Diagnosis records
        $allowedPriorityCategories = ['acne', 'eczema', 'herpes'];
        $diagnoses = Diagnosis::query()
            ->whereNotNull('image_path')
            ->where(function ($q) use ($allowedPriorityCategories) {
                foreach ($allowedPriorityCategories as $cat) {
                    $q->orWhereRaw('LOWER(label) = ?', [$cat]);
                }
            })
            ->latest()
            ->get();

        foreach ($diagnoses as $diag) {
            $catSlug = strtolower(trim($diag->label));
            if (empty($catSlug) || ! in_array($catSlug, $allowedPriorityCategories)) {
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
                    || Storage::disk($this->disk)->exists($this->datasetDir.'/'.$catSlug.'/'.$filename);

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

        // Format into standard API response: [ { 'category' => 'acne', 'images' => [...], 'items' => [...] }, ... ]
        $result = [];
        foreach ($categoryImages as $cat => $urls) {
            if (count($urls) > 0) {
                $result[] = [
                    'category' => $cat,
                    'images' => array_values(array_unique($urls)),
                    'items' => $categoryItems[$cat] ?? [],
                ];
            }
        }

        return response()->json($result);
    }

    public function addImage(UploadedFile $file, string $category): JsonResponse
    {
        $categorySlug = Str::slug($category);
        $path = $file->store($this->datasetDir.'/'.$categorySlug, $this->disk);

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
    public function addImages(array $files, string $category): JsonResponse
    {
        $categorySlug = Str::slug($category);
        $urls = [];

        foreach ($files as $file) {
            $path = $file->store($this->datasetDir.'/'.$categorySlug, $this->disk);
            $urls[] = Storage::disk($this->disk)->url($path);
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

    public function removeImage(string $url): JsonResponse
    {
        $relativePath = $this->resolveRelativeDatasetPath($url);

        if (! $relativePath) {
            return response()->json(['error' => 'Invalid dataset image path'], 403);
        }

        $filename = basename($relativePath);
        $algorithmsRawPath = base_path('../DermAssist-Algorithms/data/raw');
        $hasAlgorithmsRaw = file_exists($algorithmsRawPath) && is_dir($algorithmsRawPath);

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
    public function removeImages(array $urls): JsonResponse
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

    public function saveFromDiagnosis(string $diagnosisUuid): JsonResponse
    {
        $diagnosis = Diagnosis::where('uuid', $diagnosisUuid)->firstOrFail();

        // Verify Patient Consent
        $isPatientConsented = false;
        if ($diagnosis->patient_consented_dataset) {
            $isPatientConsented = true;
        } elseif (! empty($diagnosis->patient_uuid)) {
            $patient = User::where('uuid', $diagnosis->patient_uuid)->first();
            if ($patient && $patient->consent_dataset) {
                $isPatientConsented = true;
            }
        }

        if (! $isPatientConsented) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot save to dataset: Patient has not consented to AI retraining dataset contribution.',
            ], 403);
        }

        $category = Str::slug($diagnosis->label);
        $path = $diagnosis->image_path;

        if (! Storage::disk($this->disk)->exists($path)) {
            return response()->json(['error' => 'Image not found'], 404);
        }

        $datasetPath = $this->datasetDir.'/'.$category.'/'.basename($path);

        if (! Storage::disk($this->disk)->exists($datasetPath)) {
            Storage::disk($this->disk)->copy($path, $datasetPath);
        }

        $diagnosis->update([
            'contributed_to_dataset' => true,
            'contributed_at' => now(),
        ]);

        return response()->json(['message' => 'Saved to dataset']);
    }

    public function downloadZip(?string $category = null): JsonResponse|BinaryFileResponse
    {
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

        if ($category) {
            $catSlug = Str::slug($category);
            $cmd = sprintf('cd %s && zip -r %s %s', escapeshellarg($baseDir), escapeshellarg($zipPath), escapeshellarg($catSlug));
        } else {
            $cmd = sprintf('cd %s && zip -r %s .', escapeshellarg($baseDir), escapeshellarg($zipPath));
        }

        exec($cmd, $output, $returnVar);

        if ($returnVar !== 0) {
            return response()->json(['error' => 'Could not create zip file'], 500);
        }

        return response()->download($zipPath)->deleteFileAfterSend(true);
    }
}
