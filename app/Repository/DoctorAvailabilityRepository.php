<?php

namespace App\Repository;

use App\Models\DoctorAvailability;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class DoctorAvailabilityRepository
{
    public function getAvailabilitiesForDoctor(User $doctor): Collection
    {
        return DoctorAvailability::with('clinic')
            ->where('doctor_id', $doctor->id)
            ->orderBy('available_date', 'asc')
            ->orderBy('start_time', 'asc')
            ->get();
    }

    public function createAvailability(array $data): DoctorAvailability
    {
        return DoctorAvailability::create($data);
    }

    public function updateAvailability(DoctorAvailability $availability, array $data): DoctorAvailability
    {
        $availability->update($data);

        return $availability;
    }

    public function deleteAvailability(DoctorAvailability $availability): bool
    {
        return $availability->delete();
    }

    public function isDoctorAvailableOn(int $doctorId, Carbon $date): bool
    {
        $dateStr = $date->toDateString();
        $timeStr = $date->toTimeString();

        // Must have an active available slot (is_available = true)
        $hasAvailableSlot = DoctorAvailability::where('doctor_id', $doctorId)
            ->whereDate('available_date', $dateStr)
            ->where('is_available', true)
            ->where('start_time', '<=', $timeStr)
            ->where('end_time', '>=', $timeStr)
            ->exists();

        if (! $hasAvailableSlot) {
            return false;
        }

        // Must NOT have a blocked/unavailable slot (is_available = false)
        $isBlocked = DoctorAvailability::where('doctor_id', $doctorId)
            ->whereDate('available_date', $dateStr)
            ->where('is_available', false)
            ->where('start_time', '<=', $timeStr)
            ->where('end_time', '>=', $timeStr)
            ->exists();

        return ! $isBlocked;
    }

    public function getDutySlotsForDate(int $doctorId, Carbon $date): Collection
    {
        return DoctorAvailability::with('clinic')
            ->where('doctor_id', $doctorId)
            ->whereDate('available_date', $date->toDateString())
            ->where('is_available', true)
            ->orderBy('start_time', 'asc')
            ->get();
    }

    public function isDoctorOnDuty(int $doctorId, Carbon $date, string $startTime, string $endTime): bool
    {
        return DoctorAvailability::where('doctor_id', $doctorId)
            ->whereDate('available_date', $date->toDateString())
            ->where('is_available', true)
            ->where('start_time', '<=', $startTime)
            ->where('end_time', '>=', $endTime)
            ->exists();
    }

    public function hasBlockedOverlap(int $doctorId, Carbon $date, string $startTime, string $endTime): ?DoctorAvailability
    {
        return DoctorAvailability::where('doctor_id', $doctorId)
            ->whereDate('available_date', $date->toDateString())
            ->where('is_available', false)
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->first();
    }

    public function getNextAvailableDate(int $doctorId, Carbon $fromDate): ?DoctorAvailability
    {
        $dateStr = $fromDate->toDateString();
        $timeStr = $fromDate->toTimeString();

        return DoctorAvailability::where('doctor_id', $doctorId)
            ->where('is_available', true)
            ->where(function ($query) use ($dateStr, $timeStr) {
                $query->whereDate('available_date', '>', $dateStr)
                    ->orWhere(function ($q) use ($dateStr, $timeStr) {
                        $q->whereDate('available_date', $dateStr)
                            ->where('start_time', '>', $timeStr);
                    });
            })
            ->orderBy('available_date', 'asc')
            ->orderBy('start_time', 'asc')
            ->first();
    }

    public function getAvailableDoctorsOn(Carbon $date, ?string $city = null, ?string $province = null): Collection
    {
        $dateStr = $date->toDateString();
        $timeStr = $date->toTimeString();

        return User::whereHas('role', function ($query) {
            $query->where('slug', 'doctor');
        })
            ->whereHas('doctorVerifications', function ($query) {
                $query->where('status', 'verified');
            })
            ->whereHas('availabilities', function ($query) use ($dateStr, $timeStr) {
                $query->whereDate('available_date', $dateStr)
                    ->where('is_available', true)
                    ->where('start_time', '<=', $timeStr)
                    ->where('end_time', '>=', $timeStr);
            })
            ->whereDoesntHave('availabilities', function ($query) use ($dateStr, $timeStr) {
                $query->whereDate('available_date', $dateStr)
                    ->where('is_available', false)
                    ->where('start_time', '<=', $timeStr)
                    ->where('end_time', '>=', $timeStr);
            })
            ->when($city, function ($query) use ($city) {
                $query->where('city', $city);
            })
            ->when(! $city && $province, function ($query) use ($province) {
                $query->where('province', $province);
            })
            ->get();
    }
}
