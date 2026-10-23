<?php

namespace App\Service;

use App\Models\Appointment;
use App\Models\DoctorVerification;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ScanQuotaService
{
    /**
     * Get doctor 14-day trial duration in days.
     */
    public function getDoctorTrialDays(): int
    {
        $val = SystemSetting::get('doctor_trial_days', config('scans.doctor_trial_days', 14));

        return max(1, (int) $val);
    }

    public function setDoctorTrialDays(int $days): int
    {
        $days = max(1, min(365, $days));
        SystemSetting::set('doctor_trial_days', (string) $days);

        return $days;
    }

    /**
     * Get doctor trial daily AI scan quota.
     */
    public function getDoctorTrialDailyQuota(): int
    {
        $val = SystemSetting::get('doctor_trial_daily_scans', config('scans.doctor_trial_daily_scans', 10));

        return max(1, (int) $val);
    }

    public function setDoctorTrialDailyQuota(int $limit): int
    {
        $limit = max(1, min(1000, $limit));
        SystemSetting::set('doctor_trial_daily_scans', (string) $limit);

        return $limit;
    }

    /**
     * Get unsubscribed doctor daily AI scan quota post-trial.
     */
    public function getDoctorUnsubscribedDailyQuota(): int
    {
        $val = SystemSetting::get('doctor_unsubscribed_daily_scans', config('scans.doctor_unsubscribed_daily_scans', 3));

        return max(0, (int) $val);
    }

    public function setDoctorUnsubscribedDailyQuota(int $limit): int
    {
        $limit = max(0, min(1000, $limit));
        SystemSetting::set('doctor_unsubscribed_daily_scans', (string) $limit);

        return $limit;
    }

    /**
     * Get standard patient daily AI scan quota.
     */
    public function getPatientDefaultDailyQuota(): int
    {
        $val = SystemSetting::get('patient_default_daily_scans', config('scans.patient_default_daily_scans', 5));

        return max(1, (int) $val);
    }

    public function setPatientDefaultDailyQuota(int $limit): int
    {
        $limit = max(1, min(1000, $limit));
        SystemSetting::set('patient_default_daily_scans', (string) $limit);

        return $limit;
    }

    /**
     * Legacy alias for patient default daily quota.
     */
    public function getDefaultDailyQuota(): int
    {
        return $this->getPatientDefaultDailyQuota();
    }

    public function setDefaultDailyQuota(int $limit): int
    {
        return $this->setPatientDefaultDailyQuota($limit);
    }

    /**
     * Get doctor-care patient daily AI scan quota.
     */
    public function getPatientCareDailyQuota(): int
    {
        $val = SystemSetting::get('patient_care_daily_scans', config('scans.patient_care_daily_scans', 15));

        return max(1, (int) $val);
    }

    public function setPatientCareDailyQuota(int $limit): int
    {
        $limit = max(1, min(1000, $limit));
        SystemSetting::set('patient_care_daily_scans', (string) $limit);

        return $limit;
    }

    /**
     * Get doctor-care validity duration in days.
     */
    public function getPatientCareDurationDays(): int
    {
        $val = SystemSetting::get('patient_care_duration_days', config('scans.patient_care_duration_days', 30));

        return max(1, (int) $val);
    }

    public function setPatientCareDurationDays(int $days): int
    {
        $days = max(1, min(365, $days));
        SystemSetting::set('patient_care_duration_days', (string) $days);

        return $days;
    }

    /**
     * Legacy aliases for care grace periods compatibility.
     */
    public function getAppointmentUnlimitedDays(): int
    {
        return $this->getPatientCareDurationDays();
    }

    public function setAppointmentUnlimitedDays(int $days): int
    {
        return $this->setPatientCareDurationDays($days);
    }

    public function getDoctorCreatedUnlimitedDays(): int
    {
        return $this->getPatientCareDurationDays();
    }

    public function setDoctorCreatedUnlimitedDays(int $days): int
    {
        return $this->setPatientCareDurationDays($days);
    }

    /**
     * Get all dynamic scan quota policies for administration.
     */
    public function getAllPolicies(): array
    {
        return [
            'doctor_trial_days' => $this->getDoctorTrialDays(),
            'doctor_trial_daily_scans' => $this->getDoctorTrialDailyQuota(),
            'doctor_unsubscribed_daily_scans' => $this->getDoctorUnsubscribedDailyQuota(),
            'patient_default_daily_scans' => $this->getPatientDefaultDailyQuota(),
            'patient_care_daily_scans' => $this->getPatientCareDailyQuota(),
            'patient_care_duration_days' => $this->getPatientCareDurationDays(),
        ];
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
     *     source: string,
     *     can_scan: bool,
     *     message: ?string
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
                'can_scan' => $resolution['can_scan'] ?? true,
                'message' => $resolution['message'] ?? null,
            ];
        }

        $limit = $resolution['limit'] ?? $this->getPatientDefaultDailyQuota();

        // If user cannot scan due to verification requirement or 0 limit
        if (isset($resolution['can_scan']) && ! $resolution['can_scan']) {
            return [
                'is_unlimited' => false,
                'limit' => 0,
                'used' => 0,
                'remaining' => 0,
                'resets_at' => null,
                'countdown_seconds' => null,
                'unlimited_until' => null,
                'source' => $resolution['source'],
                'can_scan' => false,
                'message' => $resolution['message'] ?? 'AI scan access unavailable.',
            ];
        }

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
            'unlimited_until' => $resolution['unlimited_until']?->toIso8601String(),
            'source' => $resolution['source'],
            'can_scan' => $effectiveRemaining > 0,
            'message' => $resolution['message'] ?? null,
        ];
    }

    /**
     * Check if user is eligible to initiate an AI scan.
     */
    public function canScan(User $user): bool
    {
        $resolution = $this->resolveEntitlement($user);
        if (isset($resolution['can_scan']) && ! $resolution['can_scan']) {
            return false;
        }

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
     * @return array{is_unlimited: bool, limit: ?int, source: string, unlimited_until: ?Carbon, can_scan: bool, message?: string}
     */
    public function resolveEntitlement(User $user): array
    {
        // 1. Admin accounts have unlimited scans
        if ($user->role?->slug === 'admin') {
            return [
                'is_unlimited' => true,
                'limit' => null,
                'source' => 'admin',
                'unlimited_until' => null,
                'can_scan' => true,
            ];
        }

        // 2. Doctor accounts
        if ($user->role?->slug === 'doctor') {
            $isVerified = ($user->account_status === 'verified')
                || ($user->latestDoctorVerification?->status === DoctorVerification::STATUS_VERIFIED);

            if (! $isVerified) {
                return [
                    'is_unlimited' => false,
                    'limit' => 0,
                    'source' => 'doctor_unverified',
                    'unlimited_until' => null,
                    'can_scan' => false,
                    'message' => 'Doctor verification required to use AI scanning.',
                ];
            }

            // Subscribed doctor with active plan
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
                        'can_scan' => true,
                    ];
                }

                return [
                    'is_unlimited' => false,
                    'limit' => max(1, (int) $planLimit),
                    'source' => 'subscription',
                    'unlimited_until' => null,
                    'can_scan' => true,
                ];
            }

            // Verified doctor without subscription: 14-day free trial or unsubscribed allowance
            $verification = $user->latestDoctorVerification;
            $verifiedAt = $verification?->updated_at ?? $verification?->created_at ?? $user->created_at;
            $trialDays = $this->getDoctorTrialDays();
            $trialEndsAt = $verifiedAt ? Carbon::parse($verifiedAt)->addDays($trialDays) : null;

            if ($trialEndsAt && now()->lt($trialEndsAt)) {
                return [
                    'is_unlimited' => false,
                    'limit' => $this->getDoctorTrialDailyQuota(),
                    'source' => 'doctor_trial',
                    'unlimited_until' => $trialEndsAt,
                    'can_scan' => true,
                ];
            }

            // Post-trial unsubscribed doctor allowance
            return [
                'is_unlimited' => false,
                'limit' => $this->getDoctorUnsubscribedDailyQuota(),
                'source' => 'doctor_unsubscribed',
                'unlimited_until' => null,
                'can_scan' => true,
            ];
        }

        // 3. Patient accounts
        $careDurationDays = $this->getPatientCareDurationDays();

        // Source A: Doctor-registered patient account
        $docWindow = null;
        if ($user->is_doctor_registered && $user->created_at) {
            $docWindow = $user->created_at->copy()->addDays($careDurationDays);
        }

        // Source B: Completed doctor appointment
        $apptWindow = null;
        $latestCompleted = Appointment::where('patient_id', $user->id)
            ->where('status', 'completed')
            ->latest('updated_at')
            ->value('updated_at');

        if ($latestCompleted) {
            $apptWindow = Carbon::parse($latestCompleted)->addDays($careDurationDays);
        }

        // Overlapping eligibility sources: non-stacking quota, extends to latest date
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
            return [
                'is_unlimited' => false,
                'limit' => $this->getPatientCareDailyQuota(),
                'source' => 'doctor_care',
                'unlimited_until' => $effectiveUntil,
                'can_scan' => true,
            ];
        }

        // Standard Patient Tier
        return [
            'is_unlimited' => false,
            'limit' => $this->getPatientDefaultDailyQuota(),
            'source' => 'free',
            'unlimited_until' => null,
            'can_scan' => true,
        ];
    }
}
