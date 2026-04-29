<?php

namespace App\Http\Controllers;

use App\Http\Requests\DiagnosisRequest;
use App\Service\DiagnosisService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DiagnosisController extends Controller
{
    private DiagnosisService $diagnosisService;

    public function __construct(DiagnosisService $diagnosisService)
    {
        $this->diagnosisService = $diagnosisService;
    }

    public function diagnose(DiagnosisRequest $request)
    {
        try {
            $data = $request->validated();
            $data['image'] = $request->file('image');
            $data['user_uuid'] = $request->header('X-User-Uuid') ?? $request->input('user_uuid');

            return $this->diagnosisService->diagnose($data);
        } catch (\Exception $e) {
            Log::error('Diagnosis Error: '.$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function index(Request $request)
    {
        return $this->diagnosisService->listDiagnosis($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        return $this->diagnosisService->createDiagnosis($request->all());
    }

    public function show(string $uuid)
    {
        return $this->diagnosisService->getDiagnosis($uuid);
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
