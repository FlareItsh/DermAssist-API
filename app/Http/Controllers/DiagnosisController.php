<?php

namespace App\Http\Controllers;

use App\Http\Requests\DiagnosisRequest;
use App\Service\DiagnosisService;
use Illuminate\Http\Request;

class DiagnosisController extends Controller
{
    private DiagnosisService $diagnosisService;

    public function __construct(DiagnosisService $diagnosisService)
    {
        $this->diagnosisService = $diagnosisService;
    }

    public function index(Request $request)
    {
        return $this->diagnosisService->listDiagnosis($request->input('per_page', 15));
    }

    public function show(string $uuid)
    {
        return $this->diagnosisService->getDiagnosis($uuid);
    }

    public function store(DiagnosisRequest $request)
    {
        $data = $request->validated();
        $data['image'] = $request->file('image');
        $data['user_uuid'] = $request->header('X-User-Uuid') ?? $request->input('user_uuid');
        $data['user'] = $request->user();

        return $this->diagnosisService->diagnose($data);
    }

    public function update(Request $request, string $uuid)
    {
        return $this->diagnosisService->updateDiagnosis($uuid, $request->all());
    }

    public function destroy(string $uuid)
    {
        $this->diagnosisService->deleteDiagnosis($uuid);

        return response()->json(['message' => 'Deleted successfully'], 200);
    }

    public function restore(string $uuid)
    {
        return $this->diagnosisService->restoreDiagnosis($uuid);
    }
}
