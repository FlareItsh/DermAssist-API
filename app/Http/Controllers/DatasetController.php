<?php

namespace App\Http\Controllers;

use App\Service\DatasetService;
use Illuminate\Http\Request;

class DatasetController extends Controller
{
    public function __construct(private DatasetService $datasetService) {}

    public function index(Request $request)
    {
        $hasPagination = $request->has('page') || $request->has('per_page');
        $perPage = $request->query('per_page') ? (int) $request->query('per_page') : ($hasPagination ? 12 : null);

        return $this->datasetService->listDatasets(
            category: $request->query('category'),
            page: $request->query('page') ? (int) $request->query('page') : ($hasPagination ? 1 : null),
            perPage: $perPage,
            all: $request->boolean('all', ! $hasPagination)
        );
    }

    public function store(Request $request)
    {
        if ($request->hasFile('images')) {
            $request->validate([
                'images' => 'required|array',
                'images.*' => 'image',
                'category' => 'required|string',
            ]);

            return $this->datasetService->addImages(
                $request->file('images'),
                $request->input('category')
            );
        }

        $request->validate([
            'image' => 'required|image',
            'category' => 'required|string',
        ]);

        return $this->datasetService->addImage(
            $request->file('image'),
            $request->input('category')
        );
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'url' => 'required|string',
        ]);

        return $this->datasetService->removeImage($request->input('url'));
    }

    public function destroyBulk(Request $request)
    {
        $request->validate([
            'urls' => 'required|array|min:1',
            'urls.*' => 'required|string',
        ]);

        return $this->datasetService->removeImages($request->input('urls'));
    }

    public function saveFromDiagnosis(Request $request)
    {
        $request->validate([
            'diagnosis_uuid' => 'required|uuid',
        ]);

        return $this->datasetService->saveFromDiagnosis($request->input('diagnosis_uuid'));
    }

    public function download(Request $request)
    {
        return $this->datasetService->downloadZip($request->input('category'));
    }
}
