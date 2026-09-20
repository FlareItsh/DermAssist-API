<?php

namespace App\Service;

use App\Models\Diagnosis;
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
        if (! Storage::disk($this->disk)->exists($this->datasetDir)) {
            return response()->json([]);
        }

        $categories = Storage::disk($this->disk)->directories($this->datasetDir);
        $result = [];

        foreach ($categories as $categoryPath) {
            $categoryName = basename($categoryPath);
            $files = Storage::disk($this->disk)->files($categoryPath);
            $imageUrls = array_map(function ($file) {
                return Storage::disk($this->disk)->url($file);
            }, $files);

            if (count($imageUrls) > 0) {
                $result[] = [
                    'category' => $categoryName,
                    'images' => $imageUrls,
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
     * Resolve a dataset image URL or path into a relative storage path.
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

        if (! str_starts_with($relativePath, $this->datasetDir.'/') || str_contains($relativePath, '..')) {
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

        if (Storage::disk($this->disk)->exists($relativePath)) {
            Storage::disk($this->disk)->delete($relativePath);

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
        foreach ($urls as $url) {
            $relativePath = $this->resolveRelativeDatasetPath($url);
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

    public function saveFromDiagnosis(string $diagnosisUuid): JsonResponse
    {
        $diagnosis = Diagnosis::where('uuid', $diagnosisUuid)->firstOrFail();

        $category = Str::slug($diagnosis->label);
        $path = $diagnosis->image_path;

        if (! Storage::disk($this->disk)->exists($path)) {
            return response()->json(['error' => 'Image not found'], 404);
        }

        $datasetPath = $this->datasetDir.'/'.$category.'/'.basename($path);

        if (! Storage::disk($this->disk)->exists($datasetPath)) {
            Storage::disk($this->disk)->copy($path, $datasetPath);
        }

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
