<?php

namespace App\Http\Controllers;

use App\Service\DatasetService;
use Illuminate\Http\Request;

class DatasetController extends Controller
{
    public function __construct(private DatasetService $datasetService) {}

    public function index()
    {
        return $this->datasetService->listDatasets();
    }

    public function store(Request $request)
    {
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
