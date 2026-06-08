<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\ClinicalNote;
use Illuminate\Http\Request;

class ClinicalNoteController extends Controller
{
    public function show(string $appointmentUuid)
    {
        $appointment = Appointment::where('uuid', $appointmentUuid)->firstOrFail();
        $clinicalNote = $appointment->clinicalNote;
        
        if (!$clinicalNote) {
            return response()->json(null, 200);
        }
        
        return response()->json($clinicalNote);
    }

    public function store(Request $request, string $appointmentUuid)
    {
        $appointment = Appointment::where('uuid', $appointmentUuid)->firstOrFail();
        
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
        
        $validated['appointment_id'] = $appointment->id;
        $validated['doctor_id'] = $appointment->doctor_id;
        $validated['patient_id'] = $appointment->patient_id;
        if (isset($validated['diagnosis_uuid'])) {
            $diagnosis = \App\Models\Diagnosis::where('uuid', $validated['diagnosis_uuid'])->first();
            if ($diagnosis) {
                $validated['diagnosis_id'] = $diagnosis->id;
            }
            unset($validated['diagnosis_uuid']);
        }
        
        $clinicalNote = ClinicalNote::updateOrCreate(
            ['appointment_id' => $appointment->id],
            $validated
        );
        
        if (!empty($validated['follow_up_date'])) {
            $exists = Appointment::where('doctor_id', $appointment->doctor_id)
                ->where('patient_id', $appointment->patient_id)
                ->where('scheduled_at', $validated['follow_up_date'])
                ->exists();
                
            if (!$exists) {
                Appointment::create([
                    'doctor_id' => $appointment->doctor_id,
                    'patient_id' => $appointment->patient_id,
                    'scheduled_at' => $validated['follow_up_date'],
                    'status' => 'scheduled',
                ]);
            }
        }
        
        return response()->json($clinicalNote);
    }

    public function storeForDiagnosis(Request $request, string $diagnosisUuid)
    {
        $diagnosis = \App\Models\Diagnosis::where('uuid', $diagnosisUuid)->firstOrFail();
        
        $user = $request->user();
        if ($user->role->slug !== 'doctor') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (!$diagnosis->patient_id && !$diagnosis->patient_uuid) {
            return response()->json(['error' => 'Diagnosis must have an assigned patient before saving a clinical note.'], 400);
        }

        $patientId = $diagnosis->patient_id;
        if (!$patientId && $diagnosis->patient_uuid) {
            $patient = \App\Models\User::where('uuid', $diagnosis->patient_uuid)->first();
            if ($patient) $patientId = $patient->id;
        }

        // Auto-create or find a completed appointment for this interaction
        $appointment = Appointment::firstOrCreate(
            [
                'doctor_id' => $user->id,
                'patient_id' => $patientId,
                'status' => 'completed',
                'diagnosis_id' => $diagnosis->id,
            ],
            [
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'scheduled_at' => now(),
            ]
        );

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
        
        $validated['appointment_id'] = $appointment->id;
        $validated['doctor_id'] = $appointment->doctor_id;
        $validated['patient_id'] = $appointment->patient_id;
        $validated['diagnosis_id'] = $diagnosis->id;
        
        $clinicalNote = ClinicalNote::updateOrCreate(
            ['appointment_id' => $appointment->id],
            $validated
        );
        
        if (!empty($validated['follow_up_date'])) {
            $exists = Appointment::where('doctor_id', $appointment->doctor_id)
                ->where('patient_id', $appointment->patient_id)
                ->where('scheduled_at', $validated['follow_up_date'])
                ->exists();
                
            if (!$exists) {
                Appointment::create([
                    'doctor_id' => $appointment->doctor_id,
                    'patient_id' => $appointment->patient_id,
                    'scheduled_at' => $validated['follow_up_date'],
                    'status' => 'scheduled',
                ]);
            }
        }
        
        return response()->json($clinicalNote);
    }
}
