<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Individual Doctor Plan',
                'slug' => 'individual-doctor-plan',
                'tier_type' => 'individual',
                'price_monthly' => 999.00,
                'price_annual' => 9990.00,
                'max_doctors' => 1,
                'max_clinics' => 1,
                'trial_period_days' => 14,
                'grace_period_days' => 3,
                'is_active' => true,
                'features' => [
                    'show_in_recommendation' => true,
                    'can_execute_scan' => true,
                    'export_pdf_reports' => true,
                    'unlimited_appointments' => true,
                ],
            ],
            [
                'name' => 'Multi-Clinic Doctor Plan',
                'slug' => 'multi-clinic-doctor-plan',
                'tier_type' => 'doctor_multi_clinic',
                'price_monthly' => 1999.00,
                'price_annual' => 19990.00,
                'max_doctors' => 1,
                'max_clinics' => 5,
                'trial_period_days' => 14,
                'grace_period_days' => 3,
                'is_active' => true,
                'features' => [
                    'show_in_recommendation' => true,
                    'can_execute_scan' => true,
                    'export_pdf_reports' => true,
                    'unlimited_appointments' => true,
                ],
            ],
            [
                'name' => 'Clinic Group Plan',
                'slug' => 'clinic-group-plan',
                'tier_type' => 'clinic_multi_doctor',
                'price_monthly' => 4999.00,
                'price_annual' => 49990.00,
                'max_doctors' => 10,
                'max_clinics' => 3,
                'trial_period_days' => 14,
                'grace_period_days' => 3,
                'is_active' => true,
                'features' => [
                    'show_in_recommendation' => true,
                    'can_execute_scan' => true,
                    'export_pdf_reports' => true,
                    'unlimited_appointments' => true,
                ],
            ],
        ];

        foreach ($plans as $planData) {
            Plan::firstOrCreate(
                ['slug' => $planData['slug']],
                $planData
            );
        }
    }
}
