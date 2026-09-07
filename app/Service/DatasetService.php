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
                return url(Storage::disk($this->disk)->url($file));
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
            'url' => url(Storage::disk($this->disk)->url($path)),
        ], 201);
    }

    public function removeImage(string $url): JsonResponse
    {
        $baseUrl = url(Storage::disk($this->disk)->url(''));

        if (str_starts_with($url, $baseUrl)) {
            $relativePath = substr($url, strlen($baseUrl));
            $relativePath = ltrim($relativePath, '/');

            if (Storage::disk($this->disk)->exists($relativePath)) {
                Storage::disk($this->disk)->delete($relativePath);

                return response()->json(['message' => 'Image deleted successfully']);
            }
        }

        return response()->json(['error' => 'File not found or invalid URL'], 404);
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
