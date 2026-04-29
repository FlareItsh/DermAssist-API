<?php

namespace App\Repository;

use App\Models\Diagnosis;

class DiagnosisRepository
{
    public function paginate(int $perPage = 15)
    {
        return Diagnosis::latest()->paginate($perPage);
    }

    public function create(array $payload)
    {
        return Diagnosis::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return Diagnosis::where('uuid', $uuid)->firstOrFail();
    }

    public function findByField(string $field, $value)
    {
        return Diagnosis::where($field, $value)->firstOrFail();
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
        $model = Diagnosis::withTrashed()->where('uuid', $uuid)->firstOrFail();
        $model->restore();

        return $model;
    }
}
