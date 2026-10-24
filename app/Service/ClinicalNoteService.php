<?php

namespace App\Service;

use App\Events\AppointmentUpdated;
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

        $targetStatus = $validated['status'] ?? null;
        unset($validated['status']);

        if (isset($validated['diagnosis_uuid'])) {
            $diagnosis = Diagnosis::where('uuid', $validated['diagnosis_uuid'])->first();
            if ($diagnosis) {
                $validated['diagnosis_id'] = $diagnosis->id;
                $appointmentUpdate = [];
                if (! $appointment->diagnosis_id) {
                    $appointmentUpdate['diagnosis_id'] = $diagnosis->id;
                }
                if ($diagnosis->scan_type !== 'home' || ! $appointment->in_clinic_diagnosis_id) {
                    $appointmentUpdate['in_clinic_diagnosis_id'] = $diagnosis->id;
                }
                if (! empty($appointmentUpdate)) {
                    $appointment->update($appointmentUpdate);
                }
                if ($targetStatus === 'completed' || $diagnosis->status === 'draft') {
                    $diagnosis->update(['status' => 'completed']);
                }
            }
            unset($validated['diagnosis_uuid']);
        }

        if (isset($validated['in_clinic_diagnosis_uuid'])) {
            $inClinicDiagnosis = Diagnosis::where('uuid', $validated['in_clinic_diagnosis_uuid'])->first();
            if ($inClinicDiagnosis) {
                $appointmentUpdate = ['in_clinic_diagnosis_id' => $inClinicDiagnosis->id];
                if (! $appointment->diagnosis_id) {
                    $appointmentUpdate['diagnosis_id'] = $inClinicDiagnosis->id;
                }
                $appointment->update($appointmentUpdate);
                if ($targetStatus === 'completed' || $inClinicDiagnosis->status === 'draft') {
                    $inClinicDiagnosis->update(['status' => 'completed']);
                }
            }
            unset($validated['in_clinic_diagnosis_uuid']);
        }

        if (! empty($validated['scan_exception_reason'])) {
            $appointment->update([
                'scan_exception_reason' => $validated['scan_exception_reason'],
                'scan_exception_category' => $validated['scan_exception_category'] ?? 'other',
                'scan_exception_granted_at' => now(),
                'scan_exception_doctor_id' => $appointment->doctor_id,
            ]);
            unset($validated['scan_exception_category']);
        }

        if (! isset($validated['diagnosis_id'])) {
            $validated['diagnosis_id'] = $appointment->diagnosis_id ?? $appointment->in_clinic_diagnosis_id;
        }

        $clinicalNote = $this->clinicalNoteRepository->updateOrCreate(
            ['appointment_id' => $appointment->id],
            $validated
        );

        if ($targetStatus === 'completed') {
            $appointment->refresh();
            if ($appointment->hasSatisfiedConsultationScan()) {
                $appointment->update(['status' => 'completed']);
                $appointment->loadMissing(['doctor', 'patient']);
                broadcast(new AppointmentUpdated(
                    $appointment->patient?->uuid,
                    $appointment->doctor?->uuid,
                    'completed',
                    $appointment->uuid
                ));
            }
        }

        return $clinicalNote;
    }

    public function storeNoteForDiagnosis(string $diagnosisUuid, array $validated, User $user)
    {
        $diagnosis = Diagnosis::where('uuid', $diagnosisUuid)->firstOrFail();
        $diagnosis->update(['status' => 'completed']);

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

        // Auto-complete existing active appointment if present, or create a completed record
        $activeAppointment = Appointment::where('doctor_id', $user->id)
            ->where('patient_id', $patientId)
            ->whereIn('status', ['pending', 'confirmed', 'scheduled', 'checked_in', 'in_consultation', 'reschedule_proposed', 'reschedule_requested'])
            ->orderByDesc('created_at')
            ->first();

        if ($activeAppointment) {
            $apptUpdate = ['status' => 'completed'];
            if ($diagnosis->scan_type === 'home') {
                $apptUpdate['diagnosis_id'] = $diagnosis->id;
            } else {
                $apptUpdate['in_clinic_diagnosis_id'] = $diagnosis->id;
                if (! $activeAppointment->diagnosis_id) {
                    $apptUpdate['diagnosis_id'] = $diagnosis->id;
                }
            }
            $activeAppointment->update($apptUpdate);
            $appointment = $activeAppointment;
            $appointment->loadMissing(['doctor', 'patient']);
            broadcast(new AppointmentUpdated(
                $appointment->patient?->uuid,
                $appointment->doctor?->uuid,
                'completed',
                $appointment->uuid
            ));
        } else {
            $appointment = Appointment::firstOrCreate(
                [
                    'doctor_id' => $user->id,
                    'patient_id' => $patientId,
                    'status' => 'completed',
                    'diagnosis_id' => $diagnosis->id,
                    'in_clinic_diagnosis_id' => $diagnosis->scan_type !== 'home' ? $diagnosis->id : null,
                ],
                [
                    'uuid' => (string) Str::uuid(),
                    'scheduled_at' => now(),
                ]
            );
        }

        $validated['appointment_id'] = $appointment->id;
        $validated['doctor_id'] = $appointment->doctor_id;
        $validated['patient_id'] = $appointment->patient_id;
        $validated['diagnosis_id'] = $diagnosis->id;

        $clinicalNote = $this->clinicalNoteRepository->updateOrCreate(
            ['appointment_id' => $appointment->id],
            $validated
        );

        return $clinicalNote;
    }
}
