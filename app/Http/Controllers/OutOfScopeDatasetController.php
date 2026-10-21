<?php

namespace App\Http\Controllers;

use App\Service\OutOfScopeDatasetService;
use Illuminate\Http\Request;

class OutOfScopeDatasetController extends Controller
{
    public function __construct(private OutOfScopeDatasetService $service) {}

    public function index(Request $request)
    {
        $hasPagination = $request->has('page') || $request->has('per_page');
        $perPage = $request->query('per_page') ? (int) $request->query('per_page') : ($hasPagination ? 12 : null);

        return $this->service->listDatasets(
            category: $request->query('category'),
            page: $request->query('page') ? (int) $request->query('page') : ($hasPagination ? 1 : null),
            perPage: $perPage,
            all: $request->boolean('all', ! $hasPagination)
        );
    }

    public function stats()
    {
        return $this->service->getStats();
    }

    public function store(Request $request)
    {
        if ($request->hasFile('images')) {
            $request->validate([
                'images' => 'required|array',
                'images.*' => 'image',
                'category' => 'required|string',
            ]);

            return $this->service->addImages(
                $request->file('images'),
                $request->input('category')
            );
        }

        $request->validate([
            'image' => 'required|image',
            'category' => 'required|string',
        ]);

        return $this->service->addImage(
            $request->file('image'),
            $request->input('category')
        );
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'url' => 'required|string',
        ]);

        return $this->service->removeImage($request->input('url'));
    }

    public function destroyBulk(Request $request)
    {
        $request->validate([
            'urls' => 'required|array|min:1',
            'urls.*' => 'required|string',
        ]);

        return $this->service->removeImages($request->input('urls'));
    }

    public function saveFromDiagnosis(Request $request)
    {
        $request->validate([
            'diagnosis_uuid' => 'required|uuid',
        ]);

        return $this->service->saveFromDiagnosis($request->input('diagnosis_uuid'));
    }

    public function download(Request $request)
    {
        return $this->service->downloadZip($request->input('category'));
    }

    public function transfer(Request $request)
    {
        $validated = $request->validate([
            'url' => 'nullable|string',
            'urls' => 'nullable|array',
            'urls.*' => 'string',
            'target_category' => 'required|string|max:50',
            'is_standard' => 'nullable|boolean',
        ]);

        $urls = [];
        if (! empty($validated['url'])) {
            $urls[] = $validated['url'];
        }
        if (! empty($validated['urls'])) {
            $urls = array_merge($urls, $validated['urls']);
        }
        $urls = array_unique(array_filter($urls));

        if (empty($urls)) {
            return response()->json(['message' => 'No image URLs provided for transfer.'], 422);
        }

        return $this->service->transferImages($urls, $validated['target_category'], (bool) ($validated['is_standard'] ?? false));
    }
}
