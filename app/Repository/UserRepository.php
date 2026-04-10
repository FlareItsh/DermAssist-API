<?php

namespace App\Repository;

use App\Models\User;

class UserRepository
{
    public function paginate(int $perPage = 15, ?string $role = null, ?string $status = null)
    {
        return User::latest()
            ->when($role, function ($query) use ($role) {
                $query->whereHas('role', function ($q) use ($role) {
                    $q->where('slug', $role);
                });
            })
            ->when($status, function ($query) use ($status) {
                $query->whereHas('doctorVerifications', function ($q) use ($status) {
                    $q->where('status', $status);
                });
            })
            ->paginate($perPage);
    }

    public function create(array $payload)
    {
        return User::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return User::where('uuid', $uuid)->firstOrFail();
    }

    public function findByField(string $field, $value)
    {
        return User::where($field, $value)->firstOrFail();
    }

    public function findFirstByField(string $field, $value)
    {
        return User::where($field, $value)->first();
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
        $model = User::withTrashed()->where('uuid', $uuid)->firstOrFail();
        $model->restore();

        return $model;
    }
}
