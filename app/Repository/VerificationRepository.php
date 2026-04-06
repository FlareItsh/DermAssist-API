<?php

namespace App\Repository;

use App\Models\DoctorVerification as Verification;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class VerificationRepository
{
    public function paginate(int $perPage = 15)
    {
        return Verification::latest()->paginate($perPage);
    }

    public function create(array $payload)
    {
        return Verification::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return Verification::where('uuid', $uuid)->firstOrFail();
    }

    public function findByField(string $field, $value)
    {
        return Verification::where($field, $value)->firstOrFail();
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
        $model = Verification::withTrashed()->where('uuid', $uuid)->firstOrFail();
        $model->restore();
        return $model;
    }
}
