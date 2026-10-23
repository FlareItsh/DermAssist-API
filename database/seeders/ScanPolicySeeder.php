<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class ScanPolicySeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Safe to run in production / Laravel Cloud without data loss (no --fresh needed).
     */
    public function run(): void
    {
        // 1. Seed dynamic doctor scan settings
        SystemSetting::set('doctor_trial_days', '14');
        SystemSetting::set('doctor_trial_daily_scans', '10');
        SystemSetting::set('doctor_unsubscribed_daily_scans', '3');

        // 2. Seed dynamic patient scan settings
        SystemSetting::set('patient_default_daily_scans', '5');
        SystemSetting::set('patient_care_daily_scans', '15');
        SystemSetting::set('patient_care_duration_days', '30');

        // 3. Ensure standard doctor plans have unlimited scans by default (null = unlimited)
        Plan::query()->update(['daily_scan_limit' => null]);
    }
}
