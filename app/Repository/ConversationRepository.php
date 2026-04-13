<?php

namespace App\Repository;

use App\Models\Conversation;

class ConversationRepository
{
    public function paginateForUser(int $userId, int $perPage = 15)
    {
        return Conversation::with(['doctor', 'patient'])
            ->where('doctor_id', $userId)
            ->orWhere('patient_id', $userId)
            ->latest('updated_at')
            ->paginate($perPage);
    }

    public function findExisting(int $doctorId, int $patientId)
    {
        return Conversation::where('doctor_id', $doctorId)
            ->where('patient_id', $patientId)
            ->first();
    }

    public function create(array $payload)
    {
        return Conversation::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return Conversation::with(['doctor', 'patient'])->where('uuid', $uuid)->firstOrFail();
    }

    public function delete(string $uuid)
    {
        $model = $this->findByUuid($uuid);

        return $model->delete();
    }
}
