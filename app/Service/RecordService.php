<?php

namespace App\Service;

use App\Http\Resources\RecordResource;
use App\Repository\RecordRepository;

class RecordService
{
    private RecordRepository $recordRepository;

    public function __construct(RecordRepository $recordRepository)
    {
        $this->recordRepository = $recordRepository;
    }

    public function listRecords($user)
    {
        if ($user->role->slug === 'patient') {
            $diagnoses = $this->recordRepository->getRecordsForPatient($user->uuid);
        } elseif ($user->role->slug === 'doctor') {
            $diagnoses = $this->recordRepository->getRecordsForDoctor($user->id, $user->uuid);
        } elseif ($user->role->slug === 'secretary' && $user->doctor_id) {
            $doctor = $user->doctor;
            $diagnoses = $this->recordRepository->getRecordsForDoctor($user->doctor_id, $doctor ? $doctor->uuid : null);
        } else {
            $diagnoses = $this->recordRepository->getAllRecords();
        }

        return RecordResource::collection($diagnoses);
    }
}
