<?php

namespace App\Service;

use App\Models\Clinic;
use App\Models\User;
use App\Repository\ClinicRepository;
use Illuminate\Database\Eloquent\Collection;

class DoctorClinicService
{
    public function __construct(
        protected ClinicRepository $clinicRepository
    ) {}

    /**
     * Get all clinics for the authenticated doctor or secretary's doctor.
     */
    public function listClinics(User $user): Collection
    {
        $doctor = $this->resolveDoctor($user);

        return $this->clinicRepository->getClinicsForDoctor($doctor);
    }

    /**
     * Create a new clinic branch for the doctor.
     */
    public function createClinic(User $user, array $data): Clinic
    {
        $doctor = $this->resolveDoctor($user);

        $maxClinics = $doctor->getMaxClinics();
        if ($maxClinics !== null) {
            $currentCount = $this->clinicRepository->countOwnedByDoctor($doctor->id);
            if ($currentCount >= $maxClinics) {
                abort(403, "You have reached your plan limit of {$maxClinics} clinic branch(es). Please upgrade your subscription to add more clinics.");
            }
        }

        $data['owner_doctor_id'] = $doctor->id;
        if (! isset($data['is_active'])) {
            $data['is_active'] = true;
        }

        return $this->clinicRepository->create($data);
    }

    /**
     * Update an existing clinic.
     */
    public function updateClinic(User $user, string $uuid, array $data): Clinic
    {
        $doctor = $this->resolveDoctor($user);
        $clinic = $this->clinicRepository->findByUuid($uuid);

        if (! $clinic) {
            abort(404, 'Clinic not found.');
        }

        if ($clinic->owner_doctor_id !== $doctor->id) {
            abort(403, 'Unauthorized to modify this clinic.');
        }

        return $this->clinicRepository->update($clinic, $data);
    }

    /**
     * Delete a clinic.
     */
    public function deleteClinic(User $user, string $uuid): bool
    {
        $doctor = $this->resolveDoctor($user);
        $clinic = $this->clinicRepository->findByUuid($uuid);

        if (! $clinic) {
            abort(404, 'Clinic not found.');
        }

        if ($clinic->owner_doctor_id !== $doctor->id) {
            abort(403, 'Unauthorized to delete this clinic.');
        }

        return $this->clinicRepository->delete($clinic);
    }

    private function resolveDoctor(User $user): User
    {
        if ($user->role?->slug === 'doctor') {
            return $user;
        }

        if ($user->role?->slug === 'secretary' && $user->doctor_id) {
            $doctor = User::find($user->doctor_id);
            if ($doctor) {
                return $doctor;
            }
        }

        abort(403, 'Only doctors and clinic staff can manage clinics.');
    }
}
