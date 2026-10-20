<?php

namespace App\Repository;

use App\Models\Appeal;

class AppealRepository
{
    public function paginate(int $perPage = 15)
    {
        return Appeal::latest()->paginate($perPage);
    }

    public function getPendingWithUser()
    {
        return Appeal::with(['user', 'diagnosis.patient'])->where('status', 'pending')->latest()->get();
    }

    public function getWithRelations(?string $status = null, ?string $diagnosisUuid = null, ?string $userUuid = null)
    {
        $query = Appeal::with(['user', 'diagnosis.patient']);

        if ($status && in_array(strtolower($status), ['pending', 'resolved', 'rejected'])) {
            $query->where('status', strtolower($status));
        }

        if ($diagnosisUuid) {
            $query->whereHas('diagnosis', function ($q) use ($diagnosisUuid) {
                $q->where('uuid', $diagnosisUuid);
            });
        }

        if ($userUuid) {
            $query->whereHas('user', function ($q) use ($userUuid) {
                $q->where('uuid', $userUuid);
            });
        }

        return $query->latest()->get();
    }

    public function create(array $payload)
    {
        return Appeal::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return Appeal::where('uuid', $uuid)->firstOrFail();
    }

    public function findByField(string $field, $value)
    {
        return Appeal::where($field, $value)->firstOrFail();
    }

    public function update(string $uuid, array $payload)
    {
        $model = $this->findByUuid($uuid);
        $model->update($payload);

        return $model;
    }

    public function delete(string $uuid)
    {
        $model = $this->findByUuid($uuid);

        return $model->delete();
    }

    public function restore(string $uuid)
    {
        $model = Appeal::withTrashed()->where('uuid', $uuid)->firstOrFail();
        $model->restore();

        return $model;
    }
}
