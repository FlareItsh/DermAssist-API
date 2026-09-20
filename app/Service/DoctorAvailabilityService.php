<?php

namespace App\Service;

use App\Models\DoctorAvailability;
use App\Models\User;
use App\Repository\DoctorAvailabilityRepository;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;

class DoctorAvailabilityService
{
    private DoctorAvailabilityRepository $repository;

    public function __construct(DoctorAvailabilityRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getAvailabilities(User $user, ?string $doctorUuid = null): Collection
    {
        if ($doctorUuid) {
            $doctor = User::where('uuid', $doctorUuid)->firstOrFail();

            return $this->repository->getAvailabilitiesForDoctor($doctor);
        }

        if ($user->role->slug === 'doctor') {
            return $this->repository->getAvailabilitiesForDoctor($user);
        } elseif ($user->role->slug === 'secretary' && $user->doctor_id) {
            $doctor = User::findOrFail($user->doctor_id);

            return $this->repository->getAvailabilitiesForDoctor($doctor);
        }

        abort(403, 'Only doctors and secretaries can access availability records.');
    }

    public function checkDoctorAvailabilityByUuid(string $doctorUuid, Carbon $date, ?User $patient = null): array
    {
        $doctor = User::where('uuid', $doctorUuid)->firstOrFail();

        return $this->checkDoctorAvailability($doctor->id, $date, $patient);
    }

    public function detectAvailabilityConflicts(int $doctorId, array $data, ?int $excludeId = null): ?array
    {
        $dateStr = Carbon::parse($data['available_date'])->toDateString();
        $startTime = strlen($data['start_time']) === 5 ? $data['start_time'].':00' : $data['start_time'];
        $endTime = strlen($data['end_time']) === 5 ? $data['end_time'].':00' : $data['end_time'];

        $overlapping = $this->repository->getOverlappingSlots($doctorId, $dateStr, $startTime, $endTime, $excludeId);

        if ($overlapping->isEmpty()) {
            return null;
        }

        $bookedAppts = $this->repository->getBookedAppointmentsInWindow($doctorId, $dateStr, $startTime, $endTime);

        return [
            'conflict' => true,
            'message' => 'Schedule conflict detected with existing duty hours or blocked slots on this date.',
            'overlapping_slots' => $overlapping->map(function ($slot) {
                return [
                    'id' => $slot->id,
                    'uuid' => $slot->uuid,
                    'is_available' => (bool) $slot->is_available,
                    'start_time' => $slot->start_time,
                    'end_time' => $slot->end_time,
                    'clinic_name' => $slot->clinic?->name ?? $slot->location_name ?? ($slot->is_available ? 'Clinic Duty' : 'Blocked / Away'),
                ];
            })->values()->toArray(),
            'affected_appointments' => $bookedAppts->map(function ($appt) {
                return [
                    'uuid' => $appt->uuid,
                    'patient_name' => $appt->patient ? $appt->patient->first_name.' '.$appt->patient->last_name : 'Booked Patient',
                    'scheduled_at' => $appt->scheduled_at?->format('g:i A'),
                ];
            })->values()->toArray(),
        ];
    }

    public function resolveScheduleOverwrites(int $doctorId, array $data, ?int $excludeId = null): DoctorAvailability
    {
        $dateStr = Carbon::parse($data['available_date'])->toDateString();
        $startTime = strlen($data['start_time']) === 5 ? $data['start_time'].':00' : $data['start_time'];
        $endTime = strlen($data['end_time']) === 5 ? $data['end_time'].':00' : $data['end_time'];
        $isAvailable = (bool) ($data['is_available'] ?? true);
        $clinicId = $data['clinic_id'] ?? null;
        $locationName = $data['location_name'] ?? null;

        // Special Case 1: Whole-day block (00:00 to 23:59 and !is_available)
        $isWholeDay = ($startTime <= '00:01:00' && $endTime >= '23:58:00');
        if ($isWholeDay && ! $isAvailable) {
            $this->repository->deleteSlotsForDoctorOnDate($doctorId, $dateStr);

            return $this->repository->createAvailability([
                'doctor_id' => $doctorId,
                'available_date' => $dateStr,
                'start_time' => '00:00:00',
                'end_time' => '23:59:00',
                'is_available' => false,
                'location_name' => 'Blocked / Away Period',
            ]);
        }

        // Special Case 2: Adding a duty schedule on a day that previously had a whole-day block
        $existingAll = $this->repository->getSlotsForDoctorOnDate($doctorId, $dateStr);
        $wholeDayBlock = $existingAll->first(function ($s) {
            return ! $s->is_available && $s->start_time <= '00:01:00' && $s->end_time >= '23:58:00';
        });
        if ($wholeDayBlock && $isAvailable) {
            $this->repository->deleteAvailability($wholeDayBlock);
        }

        // General Case: Resolve overlaps with existing slots
        $overlapping = $this->repository->getOverlappingSlots($doctorId, $dateStr, $startTime, $endTime, $excludeId);

        foreach ($overlapping as $exist) {
            $existStart = strlen($exist->start_time) === 5 ? $exist->start_time.':00' : $exist->start_time;
            $existEnd = strlen($exist->end_time) === 5 ? $exist->end_time.':00' : $exist->end_time;

            // 1. Eclipsed: new slot completely covers existing slot
            if ($startTime <= $existStart && $endTime >= $existEnd) {
                $this->repository->deleteAvailability($exist);

                continue;
            }

            // 2. Enclosed: new slot is strictly inside existing slot -> Split existing into Left and Right
            if ($existStart < $startTime && $existEnd > $endTime) {
                $existClinicId = $exist->clinic_id;
                $existLocName = $exist->location_name;
                $existIsAvail = $exist->is_available;

                $this->repository->updateAvailability($exist, ['end_time' => $startTime]);

                $this->repository->createAvailability([
                    'doctor_id' => $doctorId,
                    'clinic_id' => $existClinicId,
                    'location_name' => $existLocName,
                    'available_date' => $dateStr,
                    'start_time' => $endTime,
                    'end_time' => $existEnd,
                    'is_available' => $existIsAvail,
                ]);

                continue;
            }

            // 3. Left overlap: new slot starts before/at existStart, and ends inside existing slot -> Trim start of existing
            if ($startTime <= $existStart && $endTime < $existEnd) {
                $this->repository->updateAvailability($exist, ['start_time' => $endTime]);

                continue;
            }

            // 4. Right overlap: new slot starts inside existing slot, and ends after/at existEnd -> Trim end of existing
            if ($existStart < $startTime && $existEnd <= $endTime) {
                $this->repository->updateAvailability($exist, ['end_time' => $startTime]);

                continue;
            }
        }

        $payload = [
            'doctor_id' => $doctorId,
            'clinic_id' => $clinicId,
            'location_name' => $locationName,
            'available_date' => $dateStr,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'is_available' => $isAvailable,
        ];

        if ($excludeId) {
            $existingToUpdate = DoctorAvailability::find($excludeId);
            if ($existingToUpdate) {
                return $this->repository->updateAvailability($existingToUpdate, $payload);
            }
        }

        return $this->repository->createAvailability($payload);
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

        $overwrite = filter_var($data['overwrite'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! $overwrite) {
            $conflict = $this->detectAvailabilityConflicts($doctorId, $data);
            if ($conflict) {
                throw new HttpResponseException(response()->json($conflict, 409));
            }
        }

        return $this->resolveScheduleOverwrites($doctorId, $data);
    }

    public function updateAvailability(DoctorAvailability $availability, array $data, User $user): DoctorAvailability
    {
        $isDoctorOwner = $user->role->slug === 'doctor' && $availability->doctor_id === $user->id;
        $isSecretaryOwner = $user->role->slug === 'secretary' && $availability->doctor_id === $user->doctor_id;

        if (! $isDoctorOwner && ! $isSecretaryOwner) {
            abort(403, 'Unauthorized action.');
        }

        $overwrite = filter_var($data['overwrite'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $mergedData = array_merge([
            'available_date' => $availability->available_date->toDateString(),
            'start_time' => $availability->start_time,
            'end_time' => $availability->end_time,
            'is_available' => $availability->is_available,
            'clinic_id' => $availability->clinic_id,
            'location_name' => $availability->location_name,
        ], $data);

        if (! $overwrite) {
            $conflict = $this->detectAvailabilityConflicts($availability->doctor_id, $mergedData, $availability->id);
            if ($conflict) {
                throw new HttpResponseException(response()->json($conflict, 409));
            }
        }

        return $this->resolveScheduleOverwrites($availability->doctor_id, $mergedData, $availability->id);
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

            // Find alternative doctors available at the specified date/time (only for standard self-registered patients)
            if (! ($patient && $patient->is_doctor_registered)) {
                $city = $patient ? $patient->city : null;
                $province = $patient ? $patient->province : null;

                $alternatives = $this->repository->getAvailableDoctorsOn($date, $city, $province);

                // If no alternatives in same city/province, search nationwide (without location filters)
                if ($alternatives->isEmpty()) {
                    $alternatives = $this->repository->getAvailableDoctorsOn($date);
                }
            }
        }

        return [
            'is_available' => $isAvailable,
            'next_available' => $nextAvailable,
            'alternatives' => $alternatives,
        ];
    }

    public function isDoctorOnDuty(int $doctorId, Carbon $date, string $startTime, string $endTime): bool
    {
        return $this->repository->isDoctorOnDuty($doctorId, $date, $startTime, $endTime);
    }

    public function hasBlockedOverlap(int $doctorId, Carbon $date, string $startTime, string $endTime): ?DoctorAvailability
    {
        return $this->repository->hasBlockedOverlap($doctorId, $date, $startTime, $endTime);
    }

    public function getDutySlotsForDate(int $doctorId, Carbon $date): Collection
    {
        return $this->repository->getDutySlotsForDate($doctorId, $date);
    }
}
