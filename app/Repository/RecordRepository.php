<?php

namespace App\Repository;

use App\Models\Diagnosis;

class RecordRepository
{
    public function getRecordsForPatient(string $userUuid)
    {
        return Diagnosis::with(['clinicalNote.doctor', 'doctor', 'appeal'])
            ->where(function ($q) use ($userUuid) {
                $q->where('patient_uuid', $userUuid)
                    ->orWhere('user_uuid', $userUuid);
            })
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getRecordsForDoctor(int $doctorId, string $doctorUuid, ?string $patientUuid = null)
    {
        $query = Diagnosis::with(['clinicalNote.patient', 'patient', 'user', 'appeal'])
            ->where(function ($q) use ($doctorId, $doctorUuid) {
                $q->whereHas('clinicalNote', function ($query) use ($doctorId) {
                    $query->where('doctor_id', $doctorId);
                })
                    ->orWhere('doctor_uuid', $doctorUuid)
                    ->orWhere('user_uuid', $doctorUuid);
            });

        if ($patientUuid) {
            $query->where(function ($q) use ($patientUuid) {
                $q->where('patient_uuid', $patientUuid)
                    ->orWhere('user_uuid', $patientUuid);
            });
        }

        return $query->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getAllRecords()
    {
        return Diagnosis::with(['clinicalNote.doctor', 'clinicalNote.patient', 'doctor', 'patient', 'appeal'])
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
