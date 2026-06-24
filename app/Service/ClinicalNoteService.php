<?php

namespace App\Service;

use App\Models\Appointment;
use App\Models\Diagnosis;
use App\Models\User;
use App\Repository\ClinicalNoteRepository;
use Illuminate\Support\Str;

class ClinicalNoteService
{
    private ClinicalNoteRepository $clinicalNoteRepository;

    public function __construct(ClinicalNoteRepository $clinicalNoteRepository)
    {
        $this->clinicalNoteRepository = $clinicalNoteRepository;
    }

    public function getNoteByAppointmentUuid(string $appointmentUuid)
    {
        $appointment = Appointment::where('uuid', $appointmentUuid)->firstOrFail();
        $clinicalNote = $this->clinicalNoteRepository->findByAppointmentId($appointment->id);

        return $clinicalNote;
    }

    public function storeNote(string $appointmentUuid, array $validated)
    {
        $appointment = Appointment::where('uuid', $appointmentUuid)->firstOrFail();

        $validated['appointment_id'] = $appointment->id;
        $validated['doctor_id'] = $appointment->doctor_id;
        $validated['patient_id'] = $appointment->patient_id;

        if (isset($validated['diagnosis_uuid'])) {
            $diagnosis = Diagnosis::where('uuid', $validated['diagnosis_uuid'])->first();
            if ($diagnosis) {
                $validated['diagnosis_id'] = $diagnosis->id;
            }
            unset($validated['diagnosis_uuid']);
        }

        $clinicalNote = $this->clinicalNoteRepository->updateOrCreate(
            ['appointment_id' => $appointment->id],
            $validated
        );

        $this->handleFollowUpAppointment($appointment, $validated);

        return $clinicalNote;
    }

    public function storeNoteForDiagnosis(string $diagnosisUuid, array $validated, User $user)
    {
        $diagnosis = Diagnosis::where('uuid', $diagnosisUuid)->firstOrFail();

        if (! $diagnosis->patient_id && ! $diagnosis->patient_uuid) {
            throw new \Exception('Diagnosis must have an assigned patient before saving a clinical note.');
        }

        $patientId = $diagnosis->patient_id;
        if (! $patientId && $diagnosis->patient_uuid) {
            $patient = User::where('uuid', $diagnosis->patient_uuid)->first();
            if ($patient) {
                $patientId = $patient->id;
            }
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
                'uuid' => (string) Str::uuid(),
                'scheduled_at' => now(),
            ]
        );

        $validated['appointment_id'] = $appointment->id;
        $validated['doctor_id'] = $appointment->doctor_id;
        $validated['patient_id'] = $appointment->patient_id;
        $validated['diagnosis_id'] = $diagnosis->id;

        $clinicalNote = $this->clinicalNoteRepository->updateOrCreate(
            ['appointment_id' => $appointment->id],
            $validated
        );

        $this->handleFollowUpAppointment($appointment, $validated);

        return $clinicalNote;
    }

    protected function handleFollowUpAppointment(Appointment $appointment, array $validated)
    {
        if (! empty($validated['follow_up_date'])) {
            $exists = Appointment::where('doctor_id', $appointment->doctor_id)
                ->where('patient_id', $appointment->patient_id)
                ->where('scheduled_at', $validated['follow_up_date'])
                ->exists();

            if (! $exists) {
                Appointment::create([
                    'doctor_id' => $appointment->doctor_id,
                    'patient_id' => $appointment->patient_id,
                    'scheduled_at' => $validated['follow_up_date'],
                    'status' => 'scheduled',
                ]);
            }
        }
    }
}
