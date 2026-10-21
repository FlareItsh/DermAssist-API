<?php

namespace App\Service;

use App\Models\Diagnosis;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OutOfScopeDatasetService
{
    private string $disk = 'public';

    private string $datasetDir = 'out_of_scope_dataset';

    /**
     * The valid out-of-scope disease categories recognized by OpenCLIP.
     *
     * @var array<string>
     */
    public static array $validCategories = [
        'psoriasis',
        'ringworm',
        'vitiligo',
        'melanoma',
        'hives',
        'warts',
        'lupus',
        'rosacea',
    ];

    public function listDatasets(?string $category = null, ?int $page = null, ?int $perPage = 12, bool $all = false): JsonResponse
    {
        if (! Storage::disk($this->disk)->exists($this->datasetDir)) {
            return response()->json([]);
        }

        $categories = Storage::disk($this->disk)->directories($this->datasetDir);
        $result = [];
        $filterCategory = $category ? strtolower(trim($category)) : null;
        $currentPage = max(1, $page ?? 1);
        $perPageCount = max(1, $perPage ?? 12);

        foreach ($categories as $categoryPath) {
            $categoryName = basename($categoryPath);
            if ($filterCategory && strtolower($categoryName) !== $filterCategory) {
                continue;
            }

            $files = Storage::disk($this->disk)->files($categoryPath);
            $imageUrls = array_map(function ($file) {
                return Storage::disk($this->disk)->url($file);
            }, $files);

            $total = count($imageUrls);
            if ($total === 0 && $filterCategory === null) {
                continue;
            }

            if ($all) {
                $pagedUrls = $imageUrls;
                $lastPage = max(1, (int) ceil($total / $perPageCount));
            } else {
                $offset = ($currentPage - 1) * $perPageCount;
                $pagedUrls = array_values(array_slice($imageUrls, $offset, $perPageCount));
                $lastPage = max(1, (int) ceil($total / $perPageCount));
            }

            $result[] = [
                'category' => $categoryName,
                'total' => $total,
                'current_page' => $currentPage,
                'per_page' => $perPageCount,
                'last_page' => $lastPage,
                'images' => $pagedUrls,
            ];
        }

        return response()->json($result);
    }

    public function addImage(UploadedFile $file, string $category): JsonResponse
    {
        $categorySlug = Str::slug($category);
        $path = $file->store($this->datasetDir.'/'.$categorySlug, $this->disk);

        return response()->json([
            'message' => 'Image added to out-of-scope dataset',
            'url' => Storage::disk($this->disk)->url($path),
        ], 201);
    }

    /**
     * Store multiple uploaded images into an out-of-scope dataset category.
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
            'message' => count($urls).' images added to out-of-scope dataset',
            'urls' => $urls,
        ], 201);
    }

    /**
     * Resolve a dataset image URL or path into a relative storage path.
     */
    private function resolveRelativePath(string $url): ?string
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

        if (! str_starts_with($relativePath, $this->datasetDir.'/') || str_contains($relativePath, '..')) {
            return null;
        }

        return $relativePath;
    }

    public function removeImage(string $url): JsonResponse
    {
        $relativePath = $this->resolveRelativePath($url);

        if (! $relativePath) {
            return response()->json(['error' => 'Invalid out-of-scope dataset image path'], 403);
        }

        if (Storage::disk($this->disk)->exists($relativePath)) {
            Storage::disk($this->disk)->delete($relativePath);

            return response()->json(['message' => 'Image deleted successfully']);
        }

        return response()->json(['error' => 'File not found or invalid URL'], 404);
    }

    /**
     * Remove multiple images from the out-of-scope dataset in a single operation.
     *
     * @param  array<string>  $urls
     */
    public function removeImages(array $urls): JsonResponse
    {
        $deletedCount = 0;
        foreach ($urls as $url) {
            $relativePath = $this->resolveRelativePath($url);
            if ($relativePath && Storage::disk($this->disk)->exists($relativePath)) {
                Storage::disk($this->disk)->delete($relativePath);
                $deletedCount++;
            }
        }

        return response()->json([
            'message' => "{$deletedCount} images deleted successfully",
            'deleted_count' => $deletedCount,
        ]);
    }

    /**
     * Transfer/move multiple images from the out-of-scope dataset to another research disease category or into the standard dataset.
     *
     * @param  array<string>  $urls
     */
    public function transferImages(array $urls, string $targetCategory, bool $targetIsStandard = false): JsonResponse
    {
        $targetCategory = Str::slug(trim($targetCategory));
        $destDir = $targetIsStandard ? 'dataset/'.$targetCategory : $this->datasetDir.'/'.$targetCategory;
        Storage::disk($this->disk)->makeDirectory($destDir);

        $transferredCount = 0;
        foreach ($urls as $url) {
            $relativePath = $this->resolveRelativePath($url);
            if ($relativePath && Storage::disk($this->disk)->exists($relativePath)) {
                $filename = basename($relativePath);
                $destPath = $destDir.'/'.$filename;
                // Move file across directories
                Storage::disk($this->disk)->move($relativePath, $destPath);
                $transferredCount++;
            }
        }

        $destLabel = $targetIsStandard ? ucfirst($targetCategory).' (Standard Dataset)' : ucfirst($targetCategory).' (Research Dataset)';

        return response()->json([
            'message' => "{$transferredCount} image(s) transferred to {$destLabel} successfully.",
            'transferred_count' => $transferredCount,
            'target_category' => $targetCategory,
            'is_standard' => $targetIsStandard,
        ]);
    }

    /**
     * Save an out-of-scope diagnosis scan to the research dataset pool.
     * Requires Dual-Consent (Patient consented + Doctor approved) — identical to the standard dataset flow.
     */
    public function saveFromDiagnosis(string $diagnosisUuid): JsonResponse
    {
        $diagnosis = Diagnosis::where('uuid', $diagnosisUuid)->firstOrFail();

        // Must be an out-of-scope diagnosis
        if (! $diagnosis->is_out_of_scope || empty($diagnosis->out_of_scope_category)) {
            return response()->json([
                'status' => 'error',
                'message' => 'This diagnosis was not flagged as an out-of-scope condition.',
            ], 422);
        }

        // Verify Patient Consent (Dual-Consent Rule — same as standard dataset)
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
                'message' => 'Cannot save to out-of-scope dataset: Patient has not consented to AI research dataset contribution.',
            ], 403);
        }

        $category = Str::slug($diagnosis->out_of_scope_category);
        $path = $diagnosis->image_path;

        if (! Storage::disk($this->disk)->exists($path)) {
            return response()->json(['error' => 'Diagnosis image not found'], 404);
        }

        $datasetPath = $this->datasetDir.'/'.$category.'/'.basename($path);

        if (! Storage::disk($this->disk)->exists($datasetPath)) {
            Storage::disk($this->disk)->copy($path, $datasetPath);
        }

        $diagnosis->update([
            'out_of_scope_contributed' => true,
            'out_of_scope_contributed_at' => now(),
        ]);

        return response()->json(['message' => 'Saved to out-of-scope research dataset']);
    }

    public function downloadZip(?string $category = null): JsonResponse|BinaryFileResponse
    {
        $zipFileName = $category ? 'out_of_scope_'.Str::slug($category).'.zip' : 'out_of_scope_all.zip';
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

    /**
     * Get image counts per out-of-scope category (for training readiness).
     *
     * @return array<string, int>
     */
    public function getStats(): JsonResponse
    {
        $stats = [];
        $total = 0;

        foreach (self::$validCategories as $cat) {
            $dir = $this->datasetDir.'/'.$cat;
            $count = Storage::disk($this->disk)->exists($dir)
                ? count(Storage::disk($this->disk)->files($dir))
                : 0;
            $stats[$cat] = $count;
            $total += $count;
        }

        return response()->json([
            'total' => $total,
            'by_category' => $stats,
        ]);
    }
}
