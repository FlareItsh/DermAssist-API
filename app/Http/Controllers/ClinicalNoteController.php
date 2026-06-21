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
            'diagnosis_id' => 'nullable|exists:diagnoses,id',
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
