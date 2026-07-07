<?php

namespace App\Repository;

use App\Models\Diagnosis;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class RecordRepository
{
    public function getRecordsForPatient(string $userUuid)
    {
        return Diagnosis::with(['clinicalNote.doctor', 'doctor'])
            ->where(function ($q) use ($userUuid) {
                $q->where('patient_uuid', $userUuid)
                  ->orWhere('user_uuid', $userUuid);
            })
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getRecordsForDoctor(int $doctorId, string $doctorUuid)
    {
        return Diagnosis::with(['clinicalNote.patient', 'patient'])
            ->where(function ($q) use ($doctorId, $doctorUuid) {
                $q->whereHas('clinicalNote', function ($query) use ($doctorId) {
                    $query->where('doctor_id', $doctorId);
                })
                ->orWhere('doctor_uuid', $doctorUuid);
            })
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getAllRecords()
    {
        return Diagnosis::with(['clinicalNote.doctor', 'clinicalNote.patient', 'doctor', 'patient'])
            ->orderBy('created_at', 'desc')
            ->get();
    }
}