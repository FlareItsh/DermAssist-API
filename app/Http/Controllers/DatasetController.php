<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class DatasetController extends Controller
{
    private $disk = 'public';
    private $datasetDir = 'dataset';

    public function index()
    {
        if (!Storage::disk($this->disk)->exists($this->datasetDir)) {
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
                    'images' => $imageUrls
                ];
            }
        }

        return response()->json($result);
    }

    public function store(Request $request)
    {
        $request->validate([
            'image' => 'required|image',
            'category' => 'required|string',
        ]);

        $category = Str::slug($request->input('category'));
        $path = $request->file('image')->store($this->datasetDir . '/' . $category, $this->disk);

        return response()->json([
            'message' => 'Image added to dataset',
            'url' => url(Storage::disk($this->disk)->url($path))
        ], 201);
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'url' => 'required|string'
        ]);

        $url = $request->input('url');
        // Convert the full URL back to a relative storage path
        $baseUrl = url(Storage::disk($this->disk)->url(''));
        
        if (str_starts_with($url, $baseUrl)) {
            $relativePath = substr($url, strlen($baseUrl));
            $relativePath = ltrim($relativePath, '/'); // ensure no leading slash

            if (Storage::disk($this->disk)->exists($relativePath)) {
                Storage::disk($this->disk)->delete($relativePath);
                return response()->json(['message' => 'Image deleted successfully']);
            }
        }

        return response()->json(['error' => 'File not found or invalid URL'], 404);
    }

    public function saveFromDiagnosis(Request $request)
    {
        $request->validate([
            'diagnosis_uuid' => 'required|uuid'
        ]);

        $diagnosis = \App\Models\Diagnosis::where('uuid', $request->diagnosis_uuid)->firstOrFail();
        
        $category = Str::slug($diagnosis->label);
        $path = $diagnosis->image_path;
        
        if (!Storage::disk($this->disk)->exists($path)) {
            return response()->json(['error' => 'Image not found'], 404);
        }

        $datasetPath = $this->datasetDir . '/' . $category . '/' . basename($path);
        
        if (!Storage::disk($this->disk)->exists($datasetPath)) {
            Storage::disk($this->disk)->copy($path, $datasetPath);
        }

        return response()->json(['message' => 'Saved to dataset']);
    }

    public function download(Request $request)
    {
        $category = $request->input('category');
        $zipFileName = $category ? 'dataset_' . Str::slug($category) . '.zip' : 'dataset_all.zip';
        $zipPath = storage_path('app/public/' . $zipFileName);

        $directories = $category 
            ? [$this->datasetDir . '/' . Str::slug($category)]
            : Storage::disk($this->disk)->directories($this->datasetDir);

        $hasFiles = false;
        foreach ($directories as $dir) {
            if (Storage::disk($this->disk)->exists($dir) && count(Storage::disk($this->disk)->files($dir)) > 0) {
                $hasFiles = true;
                break;
            }
        }

        if (!$hasFiles) {
            return response()->json(['error' => 'No images found to download'], 404);
        }

        // Use shell zip command
        $baseDir = Storage::disk($this->disk)->path($this->datasetDir);
        
        if ($category) {
            $catSlug = Str::slug($category);
            $cmd = sprintf("cd %s && zip -r %s %s", escapeshellarg($baseDir), escapeshellarg($zipPath), escapeshellarg($catSlug));
        } else {
            $cmd = sprintf("cd %s && zip -r %s .", escapeshellarg($baseDir), escapeshellarg($zipPath));
        }
        
        exec($cmd, $output, $returnVar);

        if ($returnVar !== 0) {
            return response()->json(['error' => 'Could not create zip file'], 500);
        }

        return response()->download($zipPath)->deleteFileAfterSend(true);
    }
}
