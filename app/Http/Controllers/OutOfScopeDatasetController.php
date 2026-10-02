<?php

namespace App\Http\Controllers;

use App\Service\OutOfScopeDatasetService;
use Illuminate\Http\Request;

class OutOfScopeDatasetController extends Controller
{
    public function __construct(private OutOfScopeDatasetService $service) {}

    public function index()
    {
        return $this->service->listDatasets();
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
}
