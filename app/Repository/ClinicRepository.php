<?php

namespace App\Repository;

use App\Models\Clinic;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ClinicRepository
{
    /**
     * Get all clinics accessible to a doctor (both owned and associate memberships).
     */
    public function getClinicsForDoctor(User $doctor): Collection
    {
        return Clinic::with('owner')
            ->where('owner_doctor_id', $doctor->id)
            ->orWhereHas('doctors', function ($query) use ($doctor) {
                $query->where('doctor_user_id', $doctor->id)
                    ->where('status', 'active');
            })
            ->orderBy('is_active', 'desc')
            ->orderBy('name', 'asc')
            ->get();
    }

    public function findByUuid(string $uuid): ?Clinic
    {
        return Clinic::where('uuid', $uuid)->first();
    }

    public function countOwnedByDoctor(int $doctorId): int
    {
        return Clinic::where('owner_doctor_id', $doctorId)->count();
    }

    public function create(array $data): Clinic
    {
        return Clinic::create($data);
    }

    public function update(Clinic $clinic, array $data): Clinic
    {
        $clinic->update($data);

        return $clinic;
    }

    public function delete(Clinic $clinic): bool
    {
        return $clinic->delete();
    }
}
