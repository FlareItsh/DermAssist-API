<?php

namespace App\Service;

use App\Repository\RecordRepository;
use App\Http\Resources\RecordResource;

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
        } else if ($user->role->slug === 'doctor') {
            $diagnoses = $this->recordRepository->getRecordsForDoctor($user->id, $user->uuid);
        } else {
            $diagnoses = $this->recordRepository->getAllRecords();
        }

        return RecordResource::collection($diagnoses);
    }
}