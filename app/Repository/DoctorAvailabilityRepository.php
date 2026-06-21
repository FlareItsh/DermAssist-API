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
        return DoctorAvailability::where('doctor_id', $doctor->id)
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

        // Doctors are available by default unless they have an active blocked/unavailable slot (is_available = false)
        $isBlocked = DoctorAvailability::where('doctor_id', $doctorId)
            ->whereDate('available_date', $dateStr)
            ->where('is_available', false)
            ->where('start_time', '<=', $timeStr)
            ->where('end_time', '>=', $timeStr)
            ->exists();

        return !$isBlocked;
    }

    public function getNextAvailableDate(int $doctorId, Carbon $fromDate): ?DoctorAvailability
    {
        // Find the active blocked/away slot causing the unavailability
        return DoctorAvailability::where('doctor_id', $doctorId)
            ->where('is_available', false)
            ->whereDate('available_date', $fromDate->toDateString())
            ->where('start_time', '<=', $fromDate->toTimeString())
            ->where('end_time', '>=', $fromDate->toTimeString())
            ->first();
    }

    public function getAvailableDoctorsOn(Carbon $date, ?string $city = null, ?string $province = null): Collection
    {
        $dateStr = $date->toDateString();
        $timeStr = $date->toTimeString();

        // A doctor is available if they do NOT have a blocked record at that date/time
        return User::whereHas('role', function ($query) {
            $query->where('slug', 'doctor');
        })
            ->whereHas('doctorVerifications', function ($query) {
                $query->where('status', 'verified');
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
