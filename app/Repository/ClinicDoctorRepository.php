<?php

namespace App\Repository;

use App\Models\Clinic;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ClinicDoctorRepository
{
    /**
     * Get all associate doctors across all clinics owned by the given doctor.
     */
    public function getDoctorsForOwner(int $ownerDoctorId): array
    {
        $records = DB::table('clinic_doctors')
            ->join('clinics', 'clinic_doctors.clinic_id', '=', 'clinics.id')
            ->join('users', 'clinic_doctors.doctor_user_id', '=', 'users.id')
            ->where('clinics.owner_doctor_id', $ownerDoctorId)
            ->whereNull('users.deleted_at')
            ->select([
                'clinic_doctors.id as pivot_id',
                'clinic_doctors.role',
                'clinic_doctors.status',
                'clinic_doctors.created_at as joined_at',
                'clinics.id as clinic_id',
                'clinics.uuid as clinic_uuid',
                'clinics.name as clinic_name',
                'users.id as doctor_id',
                'users.uuid as doctor_uuid',
                'users.first_name',
                'users.middle_name',
                'users.last_name',
                'users.email',
                'users.prc_number',
                'users.affiliation',
                'users.avatar_path',
                'users.account_status',
            ])
            ->orderBy('clinic_doctors.created_at', 'desc')
            ->get();

        return $records->toArray();
    }

    /**
     * Search verified doctors who can be invited as associates.
     *
     * @return Collection<int, User>
     */
    public function searchEligibleDoctors(string $query, int $ownerDoctorId): Collection
    {
        $searchTerm = '%'.trim($query).'%';

        return User::whereHas('role', function ($q) {
            $q->where('slug', 'doctor');
        })
            ->where('id', '!=', $ownerDoctorId)
            ->where('account_status', 'active')
            ->where(function ($q) use ($searchTerm) {
                $q->where('email', 'like', $searchTerm)
                    ->orWhere('prc_number', 'like', $searchTerm)
                    ->orWhere('first_name', 'like', $searchTerm)
                    ->orWhere('last_name', 'like', $searchTerm)
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$searchTerm]);
            })
            ->limit(10)
            ->get();
    }

    /**
     * Find a clinic by ID or UUID owned by the given doctor.
     */
    public function findOwnedClinic(int $ownerDoctorId, string|int $clinicIdOrUuid): ?Clinic
    {
        return Clinic::where('owner_doctor_id', $ownerDoctorId)
            ->where(function ($query) use ($clinicIdOrUuid) {
                if (is_numeric($clinicIdOrUuid)) {
                    $query->where('id', $clinicIdOrUuid);
                } else {
                    $query->where('uuid', $clinicIdOrUuid);
                }
            })
            ->first();
    }

    /**
     * Find a doctor user by ID, UUID, or email.
     */
    public function findDoctorUser(string|int $identifier): ?User
    {
        return User::whereHas('role', function ($q) {
            $q->where('slug', 'doctor');
        })
            ->where(function ($query) use ($identifier) {
                if (is_numeric($identifier)) {
                    $query->where('id', $identifier);
                } else {
                    $query->where('uuid', $identifier)
                        ->orWhere('email', $identifier);
                }
            })
            ->first();
    }

    /**
     * Check if doctor is already assigned to this specific clinic.
     */
    public function isDoctorInClinic(int $clinicId, int $doctorId): bool
    {
        return DB::table('clinic_doctors')
            ->where('clinic_id', $clinicId)
            ->where('doctor_user_id', $doctorId)
            ->exists();
    }

    /**
     * Assign doctor to clinic.
     */
    public function assignDoctorToClinic(int $clinicId, int $doctorId, string $role = 'associate', string $status = 'active'): int
    {
        return DB::table('clinic_doctors')->insertGetId([
            'clinic_id' => $clinicId,
            'doctor_user_id' => $doctorId,
            'role' => $role,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Remove / revoke a doctor seat by pivot ID if owned by the owner doctor.
     */
    public function removeDoctorSeat(int $ownerDoctorId, int $pivotId): bool
    {
        $pivot = DB::table('clinic_doctors')
            ->join('clinics', 'clinic_doctors.clinic_id', '=', 'clinics.id')
            ->where('clinic_doctors.id', $pivotId)
            ->where('clinics.owner_doctor_id', $ownerDoctorId)
            ->select('clinic_doctors.id')
            ->first();

        if (! $pivot) {
            return false;
        }

        return (bool) DB::table('clinic_doctors')->where('id', $pivotId)->delete();
    }
}
