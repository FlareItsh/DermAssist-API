<?php

namespace App\Http\Controllers;

use App\Service\ClinicalNoteService;
use Illuminate\Http\Request;

class ClinicalNoteController extends Controller
{
    private ClinicalNoteService $clinicalNoteService;

    public function __construct(ClinicalNoteService $clinicalNoteService)
    {
        $this->clinicalNoteService = $clinicalNoteService;
    }

    public function show(string $appointmentUuid)
    {
        $clinicalNote = $this->clinicalNoteService->getNoteByAppointmentUuid($appointmentUuid);

        if (! $clinicalNote) {
            return response()->json(null, 200);
        }

        return response()->json($clinicalNote);
    }

    public function store(Request $request, string $appointmentUuid)
    {
        $validated = $request->validate([
            'diagnosis_uuid' => 'nullable|exists:diagnoses,uuid',
            'history_of_present_illness' => 'nullable|string',
            'systemic_symptoms' => 'nullable|string',
            'physical_exam' => 'nullable|string',
            'differential_diagnosis' => 'nullable|string',
            'final_diagnosis' => 'nullable|string|max:255',
            'prescription' => 'nullable|string',
            'patient_education' => 'nullable|string',
            'follow_up_date' => 'nullable|date',
            'follow_up_instructions' => 'nullable|string',
        ]);

        $clinicalNote = $this->clinicalNoteService->storeNote($appointmentUuid, $validated);

        return response()->json($clinicalNote);
    }

    public function storeForDiagnosis(Request $request, string $diagnosisUuid)
    {
        $user = $request->user();
        if ($user->role->slug !== 'doctor') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'history_of_present_illness' => 'nullable|string',
            'systemic_symptoms' => 'nullable|string',
            'physical_exam' => 'nullable|string',
            'differential_diagnosis' => 'nullable|string',
            'final_diagnosis' => 'nullable|string|max:255',
            'prescription' => 'nullable|string',
            'patient_education' => 'nullable|string',
            'follow_up_date' => 'nullable|date',
            'follow_up_instructions' => 'nullable|string',
        ]);

        try {
            $clinicalNote = $this->clinicalNoteService->storeNoteForDiagnosis($diagnosisUuid, $validated, $user);

            return response()->json($clinicalNote);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}
