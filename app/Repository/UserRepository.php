<?php

namespace App\Repository;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserRepository
{
    public function paginate(int $perPage = 15, ?string $role = null, ?string $status = null, bool $recommendedOnly = false)
    {
        return User::latest()
            ->withCount('diagnoses')
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
            ->when($recommendedOnly, function ($query) {
                $query->whereHas('subscription', function ($subQuery) {
                    $subQuery->whereIn('status', ['active', 'trialing'])
                        ->where(function ($q) {
                            $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
                        })
                        ->whereHas('plan.planFeatures', function ($pfQuery) {
                            $pfQuery->where('code', 'show_in_recommendation')
                                ->where('is_active', true)
                                ->where('plan_has_features.is_included', true);
                        });
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
        return User::where('uuid', $uuid)->withCount('diagnoses')->firstOrFail();
    }

    public function findByField(string $field, $value)
    {
        return User::where($field, $value)->withCount('diagnoses')->firstOrFail();
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

    public function findDoctorRegisteredPatients(int $doctorId)
    {
        return User::where('registered_by_doctor_id', $doctorId)
            ->where(function ($query) {
                $query->whereNull('account_action')
                    ->orWhere('account_action', '!=', 'delete')
                    ->orWhere('account_action_scheduled_at', '>', now());
            })
            ->withCount('diagnoses')
            ->latest()
            ->get();
    }

    public function paginateDoctorPatients(int $doctorId, int $perPage = 15)
    {
        return User::where('registered_by_doctor_id', $doctorId)
            ->where(function ($query) {
                $query->whereNull('account_action')
                    ->orWhere('account_action', '!=', 'delete')
                    ->orWhere('account_action_scheduled_at', '>', now());
            })
            ->withCount('diagnoses')
            ->latest()
            ->paginate($perPage);
    }

    public function getDoctorSecretaries(int $doctorId)
    {
        return User::where('doctor_id', $doctorId)
            ->whereHas('role', function ($q) {
                $q->where('slug', 'secretary');
            })
            ->latest()
            ->get();
    }

    public function createDoctorSecretary(array $payload, int $doctorId): User
    {
        $secretaryRole = Role::where('slug', 'secretary')->firstOrFail();

        $secretary = User::create([
            'first_name' => $payload['firstName'],
            'middle_name' => $payload['middleName'] ?? null,
            'last_name' => $payload['lastName'],
            'email' => $payload['email'],
            'password' => Hash::make($payload['password']),
            'role_id' => $secretaryRole->id,
            'doctor_id' => $doctorId,
            'uuid' => (string) Str::uuid(),
        ]);

        return $secretary->load('role', 'doctor');
    }

    public function deleteDoctorSecretary(string $uuid, int $doctorId): bool
    {
        $secretary = User::where('uuid', $uuid)
            ->where('doctor_id', $doctorId)
            ->firstOrFail();

        return (bool) $secretary->delete();
    }
}
