<?php

namespace App\Http\Controllers;

use App\Http\Requests\DiagnosisRequest;
use App\Models\User;
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
        try {
            $data = $request->validated();
            $data['image'] = $request->file('image');
            $userUuid = $request->header('X-User-Uuid') ?? $request->input('user_uuid');
            $data['user_uuid'] = $userUuid;

            $user = $request->user();
            if (! $user && $userUuid) {
                $user = User::where('uuid', $userUuid)->first();
            }
            $data['user'] = $user;

            if ($user && $user->role && $user->role->slug === 'doctor') {
                if (! $user->canExecuteScan()) {
                    return response()->json([
                        'status' => 'error',
                        'code' => 'PLAN_FEATURE_RESTRICTED',
                        'message' => 'Your current subscription plan does not include Doctor AI Scan Execution.',
                    ], 403);
                }
                $data['doctor_uuid'] = $userUuid;
                $data['patient_uuid'] = $request->input('patient_uuid');
            } else {
                $data['patient_uuid'] = $request->input('patient_uuid') ?? $userUuid;
                $data['doctor_uuid'] = null;
            }

            return $this->diagnosisService->diagnose($data);
        } catch (\Exception $e) {
            Log::error('Diagnosis Error: '.$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function recordTechnicalFailure(Request $request)
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:1000',
            'patient_uuid' => 'nullable|string|exists:users,uuid',
            'image' => 'nullable|image|max:10240',
        ]);

        $user = $request->user();
        $userUuid = $user ? $user->uuid : ($request->header('X-User-Uuid') ?? $request->input('user_uuid'));

        $data = [
            'reason' => $validated['reason'],
            'image' => $request->file('image'),
            'user_uuid' => $userUuid,
            'doctor_uuid' => ($user && $user->role && $user->role->slug === 'doctor') ? $userUuid : null,
            'patient_uuid' => $validated['patient_uuid'] ?? null,
        ];

        return $this->diagnosisService->recordTechnicalFailure($data);
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
