<?php

namespace App\Service;

use App\Models\DoctorAvailability;
use App\Models\User;
use App\Repository\DoctorAvailabilityRepository;
use App\Repository\UserRepository;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class DoctorAvailabilityService
{
    private DoctorAvailabilityRepository $repository;

    private UserRepository $userRepository;

    public function __construct(
        DoctorAvailabilityRepository $repository,
        UserRepository $userRepository
    ) {
        $this->repository = $repository;
        $this->userRepository = $userRepository;
    }

    public function getAvailabilitiesByDoctorUuid(string $doctorUuid): Collection
    {
        $doctor = $this->userRepository->findByUuid($doctorUuid);

        return $this->getAvailabilities($doctor);
    }

    public function getAvailabilities(User $doctor): Collection
    {
        if ($doctor->role->slug !== 'doctor') {
            abort(403, 'Only doctors have availability records.');
        }

        return $this->repository->getAvailabilitiesForDoctor($doctor);
    }

    public function createAvailability(User $doctor, array $data): DoctorAvailability
    {
        if ($doctor->role->slug !== 'doctor') {
            abort(403, 'Only doctors can set availability.');
        }

        $data['doctor_id'] = $doctor->id;

        return $this->repository->createAvailability($data);
    }

    public function updateAvailability(DoctorAvailability $availability, array $data, User $user): DoctorAvailability
    {
        if ($user->role->slug !== 'doctor' || $availability->doctor_id !== $user->id) {
            abort(403, 'Unauthorized action.');
        }

        return $this->repository->updateAvailability($availability, $data);
    }

    public function deleteAvailability(DoctorAvailability $availability, User $user): bool
    {
        if ($user->role->slug !== 'doctor' || $availability->doctor_id !== $user->id) {
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

    public function checkDoctorAvailabilityByUuid(string $doctorUuid, Carbon $date, ?User $patient = null): array
    {
        $doctor = $this->userRepository->findByUuid($doctorUuid);

        return $this->checkDoctorAvailability($doctor->id, $date, $patient);
    }
}
