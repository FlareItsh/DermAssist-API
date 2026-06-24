<?php

namespace App\Repository;

use App\Models\ClinicalNote;

class ClinicalNoteRepository
{
    public function paginate(int $perPage = 15)
    {
        return ClinicalNote::latest()->paginate($perPage);
    }

    public function create(array $payload)
    {
        return ClinicalNote::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return ClinicalNote::where('uuid', $uuid)->firstOrFail();
    }

    public function findByField(string $field, $value)
    {
        return ClinicalNote::where($field, $value)->firstOrFail();
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
        $model = ClinicalNote::withTrashed()->where('uuid', $uuid)->firstOrFail();
        $model->restore();

        return $model;
    }

    public function updateOrCreate(array $attributes, array $values)
    {
        return ClinicalNote::updateOrCreate($attributes, $values);
    }

    public function findByAppointmentId($appointmentId)
    {
        return ClinicalNote::where('appointment_id', $appointmentId)->first();
    }
}
