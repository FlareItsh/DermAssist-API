<?php

namespace App\Service;

use App\Models\DoctorAvailability;
use App\Models\User;
use App\Repository\DoctorAvailabilityRepository;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class DoctorAvailabilityService
{
    private DoctorAvailabilityRepository $repository;

    public function __construct(DoctorAvailabilityRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getAvailabilities(User $user): Collection
    {
        if ($user->role->slug === 'doctor') {
            return $this->repository->getAvailabilitiesForDoctor($user);
        } elseif ($user->role->slug === 'secretary' && $user->doctor_id) {
            $doctor = User::findOrFail($user->doctor_id);

            return $this->repository->getAvailabilitiesForDoctor($doctor);
        }

        abort(403, 'Only doctors and secretaries can access availability records.');
    }

    public function createAvailability(User $actor, array $data): DoctorAvailability
    {
        $doctorId = null;
        if ($actor->role->slug === 'doctor') {
            $doctorId = $actor->id;
        } elseif ($actor->role->slug === 'secretary' && $actor->doctor_id) {
            $doctorId = $actor->doctor_id;
        } else {
            abort(403, 'Only doctors or secretaries can set availability.');
        }

        $data['doctor_id'] = $doctorId;

        return $this->repository->createAvailability($data);
    }

    public function updateAvailability(DoctorAvailability $availability, array $data, User $user): DoctorAvailability
    {
        $isDoctorOwner = $user->role->slug === 'doctor' && $availability->doctor_id === $user->id;
        $isSecretaryOwner = $user->role->slug === 'secretary' && $availability->doctor_id === $user->doctor_id;

        if (! $isDoctorOwner && ! $isSecretaryOwner) {
            abort(403, 'Unauthorized action.');
        }

        return $this->repository->updateAvailability($availability, $data);
    }

    public function deleteAvailability(DoctorAvailability $availability, User $user): bool
    {
        $isDoctorOwner = $user->role->slug === 'doctor' && $availability->doctor_id === $user->id;
        $isSecretaryOwner = $user->role->slug === 'secretary' && $availability->doctor_id === $user->doctor_id;

        if (! $isDoctorOwner && ! $isSecretaryOwner) {
            abort(403, 'Unauthorized action.');
        }

        return $this->repository->deleteAvailability($availability);
    }

    public function checkDoctorAvailability(int $doctorId, Carbon $date, ?User $patient = null): array
    {
        $isAvailable = $this->repository->isDoctorAvailableOn($doctorId, $date);

        $nextAvailable = null;
        $alternatives = new Collection;

        if (! $isAvailable) {
            // Find when the doctor will be available next (after the blocked slot ends)
            $nextWindow = $this->repository->getNextAvailableDate($doctorId, $date);
            if ($nextWindow) {
                $formattedTime = Carbon::parse($nextWindow->end_time)->format('g:i A');
                $nextAvailable = [
                    'date' => $nextWindow->available_date->toDateString(),
                    'start_time' => $nextWindow->end_time,
                    'end_time' => '23:59:59',
                    'formatted' => $nextWindow->available_date->format('M d, Y').' after '.$formattedTime,
                ];
            }

            // Find alternative doctors available at the specified date/time
            $city = $patient ? $patient->city : null;
            $province = $patient ? $patient->province : null;

            $alternatives = $this->repository->getAvailableDoctorsOn($date, $city, $province);

            // If no alternatives in same city/province, search nationwide (without location filters)
            if ($alternatives->isEmpty()) {
                $alternatives = $this->repository->getAvailableDoctorsOn($date);
            }
        }

        return [
            'is_available' => $isAvailable,
            'next_available' => $nextAvailable,
            'alternatives' => $alternatives,
        ];
    }
}
