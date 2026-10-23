<?php

namespace App\Service;

use App\Models\Appointment;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ScanQuotaService
{
    /**
     * Get system-wide dynamic default scan quota (defaults to 5 if not configured).
     */
    public function getDefaultDailyQuota(): int
    {
        $val = SystemSetting::get('default_scan_quota', config('scans.free_daily_limit', 5));

        return max(1, (int) $val);
    }

    /**
     * Update system-wide dynamic default scan quota.
     */
    public function setDefaultDailyQuota(int $limit): int
    {
        $limit = max(1, $limit);
        SystemSetting::set('default_scan_quota', (string) $limit);

        return $limit;
    }

    /**
     * Get dynamic unlimited scan days granted after a doctor completes an appointment.
     */
    public function getAppointmentUnlimitedDays(): int
    {
        $val = SystemSetting::get('appointment_unlimited_days', config('scans.care_unlimited_days', 7));

        return max(0, (int) $val);
    }

    /**
     * Set dynamic unlimited scan days granted after a doctor completes an appointment.
     */
    public function setAppointmentUnlimitedDays(int $days): int
    {
        $days = max(0, min(365, $days));
        SystemSetting::set('appointment_unlimited_days', (string) $days);

        return $days;
    }

    /**
     * Get dynamic unlimited scan days granted when a doctor creates/registers a patient account.
     */
    public function getDoctorCreatedUnlimitedDays(): int
    {
        $val = SystemSetting::get('doctor_created_unlimited_days', config('scans.care_unlimited_days', 7));

        return max(0, (int) $val);
    }

    /**
     * Set dynamic unlimited scan days granted when a doctor creates/registers a patient account.
     */
    public function setDoctorCreatedUnlimitedDays(int $days): int
    {
        $days = max(0, min(365, $days));
        SystemSetting::set('doctor_created_unlimited_days', (string) $days);

        return $days;
    }

    /**
     * Resolve the scan entitlement and quota status for a user.
     *
     * @return array{
     *     is_unlimited: bool,
     *     limit: ?int,
     *     used: int,
     *     remaining: ?int,
     *     resets_at: ?string,
     *     countdown_seconds: ?int,
     *     unlimited_until: ?string,
     *     source: string
     * }
     */
    public function getQuota(User $user): array
    {
        $resolution = $this->resolveEntitlement($user);
        $windowHours = (int) config('scans.window_hours', 24);

        if ($resolution['is_unlimited']) {
            return [
                'is_unlimited' => true,
                'limit' => null,
                'used' => 0,
                'remaining' => null,
                'resets_at' => null,
                'countdown_seconds' => null,
                'unlimited_until' => $resolution['unlimited_until']?->toIso8601String(),
                'source' => $resolution['source'],
            ];
        }

        $limit = $resolution['limit'] ?? $this->getDefaultDailyQuota();
        $windowStart = $user->scan_window_started_at;
        $windowCount = (int) $user->scan_window_count;

        // Check if window has expired
        $isWindowExpired = false;
        $resetsAt = null;
        $countdownSeconds = null;

        if ($windowStart) {
            $windowEnd = Carbon::parse($windowStart)->addHours($windowHours);
            if (now()->gte($windowEnd)) {
                $isWindowExpired = true;
            } else {
                $resetsAt = $windowEnd->toIso8601String();
                $countdownSeconds = max(0, (int) now()->diffInSeconds($windowEnd, false));
            }
        }

        $effectiveUsed = ($isWindowExpired || ! $windowStart) ? 0 : $windowCount;
        $effectiveRemaining = max(0, $limit - $effectiveUsed);

        return [
            'is_unlimited' => false,
            'limit' => $limit,
            'used' => $effectiveUsed,
            'remaining' => $effectiveRemaining,
            'resets_at' => $resetsAt,
            'countdown_seconds' => $countdownSeconds,
            'unlimited_until' => null,
            'source' => $resolution['source'],
        ];
    }

    /**
     * Check if user is eligible to initiate an AI scan.
     */
    public function canScan(User $user): bool
    {
        $quota = $this->getQuota($user);

        return $quota['is_unlimited'] || ($quota['remaining'] !== null && $quota['remaining'] > 0);
    }

    /**
     * Record a completed scan usage AFTER AI inference has successfully produced a result.
     * Starts the 24h window on the first scan, or resets the count if 24h has elapsed.
     */
    public function recordScan(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();
            if (! $lockedUser) {
                return $this->getQuota($user);
            }

            $resolution = $this->resolveEntitlement($lockedUser);
            if ($resolution['is_unlimited']) {
                return $this->getQuota($lockedUser);
            }

            $windowHours = (int) config('scans.window_hours', 24);
            $windowStart = $lockedUser->scan_window_started_at;

            $isExpired = false;
            if ($windowStart) {
                $windowEnd = Carbon::parse($windowStart)->addHours($windowHours);
                $isExpired = now()->gte($windowEnd);
            }

            if (! $windowStart || $isExpired) {
                // First scan in a new 24h window
                $lockedUser->scan_window_started_at = now();
                $lockedUser->scan_window_count = 1;
            } else {
                // Increment within existing window
                $lockedUser->scan_window_count = ((int) $lockedUser->scan_window_count) + 1;
            }

            $lockedUser->save();

            // Refresh original user instance state
            $user->scan_window_started_at = $lockedUser->scan_window_started_at;
            $user->scan_window_count = $lockedUser->scan_window_count;

            return $this->getQuota($lockedUser);
        });
    }

    /**
     * Resolve user scan limit and source.
     *
     * @return array{is_unlimited: bool, limit: ?int, source: string, unlimited_until: ?Carbon}
     */
    protected function resolveEntitlement(User $user): array
    {
        // 1. Admin accounts have unlimited scans
        if ($user->role?->slug === 'admin') {
            return [
                'is_unlimited' => true,
                'limit' => null,
                'source' => 'admin',
                'unlimited_until' => null,
            ];
        }

        // 2. Doctor care grace period (completed appointment or doctor-registered account)
        $docWindow = null;
        if ($user->is_doctor_registered && $user->created_at) {
            $docDays = $this->getDoctorCreatedUnlimitedDays();
            if ($docDays > 0) {
                $docWindow = $user->created_at->copy()->addDays($docDays);
            }
        }

        $apptWindow = null;
        $apptDays = $this->getAppointmentUnlimitedDays();
        if ($apptDays > 0) {
            $latestCompleted = Appointment::where('patient_id', $user->id)
                ->where('status', 'completed')
                ->latest('updated_at')
                ->value('updated_at');

            if ($latestCompleted) {
                $apptWindow = Carbon::parse($latestCompleted)->addDays($apptDays);
            }
        }

        // Determine effective until
        $effectiveUntil = null;
        if ($docWindow && $apptWindow) {
            $effectiveUntil = $docWindow->gt($apptWindow) ? $docWindow : $apptWindow;
        } elseif ($docWindow) {
            $effectiveUntil = $docWindow;
        } elseif ($apptWindow) {
            $effectiveUntil = $apptWindow;
        } elseif ($user->unlimited_scans_until && $user->unlimited_scans_until->isFuture()) {
            $effectiveUntil = $user->unlimited_scans_until;
        }

        if ($effectiveUntil && $effectiveUntil->isFuture()) {
            if (! $user->unlimited_scans_until || $user->unlimited_scans_until->ne($effectiveUntil)) {
                $user->update(['unlimited_scans_until' => $effectiveUntil]);
                $user->unlimited_scans_until = $effectiveUntil;
            }

            return [
                'is_unlimited' => true,
                'limit' => null,
                'source' => 'doctor_care',
                'unlimited_until' => $effectiveUntil,
            ];
        }

        // 3. Subscribed Doctor with active plan having can_execute_scan
        if ($user->role?->slug === 'doctor') {
            $subscription = $user->getActiveSubscription();
            if ($subscription && $subscription->hasFeature('can_execute_scan')) {
                $planLimit = $subscription->getDailyScanLimit();

                // If plan daily_scan_limit is null, it means Unlimited
                if ($planLimit === null) {
                    return [
                        'is_unlimited' => true,
                        'limit' => null,
                        'source' => 'subscription',
                        'unlimited_until' => null,
                    ];
                }

                return [
                    'is_unlimited' => false,
                    'limit' => max(1, (int) $planLimit),
                    'source' => 'subscription',
                    'unlimited_until' => null,
                ];
            }
        }

        // 4. Default quota applies to regular patients and unsubscribed doctors
        return [
            'is_unlimited' => false,
            'limit' => $this->getDefaultDailyQuota(),
            'source' => 'free',
            'unlimited_until' => null,
        ];
    }
}
