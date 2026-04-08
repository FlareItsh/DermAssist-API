<?php

namespace App\Repository;

use App\Models\DoctorVerification as Verification;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class VerificationRepository
{
    public function paginate(int $perPage = 15, ?string $status = null, ?string $search = null)
    {
        return Verification::with(['user', 'user.role'])
            ->when($status, fn($query) => $query->where('status', $status))
            ->when($search, function ($query) use ($search) {
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate($perPage);
    }

    public function create(array $payload)
    {
        return Verification::create($payload)->load(['user', 'user.role']);
    }

    public function findByUuid(string $uuid)
    {
        return Verification::with(['user', 'user.role'])->where('uuid', $uuid)->firstOrFail();
    }

    public function findByField(string $field, $value)
    {
        return Verification::with(['user', 'user.role'])->where($field, $value)->firstOrFail();
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
