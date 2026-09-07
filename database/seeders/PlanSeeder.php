<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Ensure all standard features exist
        $standardFeatures = [
            [
                'code' => 'show_in_recommendation',
                'name' => 'Show in Patient Scan Recommendations',
                'description' => 'Display doctor in patient scan results and nearby specialist discovery list.',
                'sort_order' => 1,
            ],
            [
                'code' => 'can_execute_scan',
                'name' => 'Allow Doctor AI Scan Execution',
                'description' => 'Enables running live clinical skin disease scans and inference.',
                'sort_order' => 2,
            ],
            [
                'code' => 'export_pdf_reports',
                'name' => 'Allow PDF Clinical Report Exports',
                'description' => 'Export full clinical assessment diagnosis reports as downloadable PDFs.',
                'sort_order' => 3,
            ],
            [
                'code' => 'unlimited_appointments',
                'name' => 'Enable Teleconsultation Appointments',
                'description' => 'Allows booking and conducting teleconsultation appointments.',
                'sort_order' => 4,
            ],
            [
                'code' => 'can_have_secretary',
                'name' => 'Dedicated Secretary Account Access',
                'description' => 'Enables registering and delegating work to dedicated clinic secretary accounts.',
                'sort_order' => 5,
            ],
        ];

        foreach ($standardFeatures as $feat) {
            Feature::firstOrCreate(
                ['code' => $feat['code']],
                array_merge($feat, [
                    'uuid' => (string) Str::uuid(),
                    'is_active' => true,
                ])
            );
        }

        // 2. Define Plans
        $plans = [
            [
                'name' => 'Individual Doctor Plan',
                'slug' => 'individual-doctor-plan',
                'tier_type' => 'individual',
                'price_monthly' => 999.00,
                'price_annual' => 9990.00,
                'max_doctors' => 1,
                'max_clinics' => 1,
                'max_secretaries' => 0,
                'trial_period_days' => 14,
                'grace_period_days' => 3,
                'sort_order' => 1,
                'is_active' => true,
                'features' => [
                    'show_in_recommendation' => true,
                    'can_execute_scan' => true,
                    'export_pdf_reports' => false,
                    'unlimited_appointments' => false,
                    'can_have_secretary' => false,
                ],
            ],
            [
                'name' => 'Individual Doctor Plan (with Secretary)',
                'slug' => 'individual-doctor-secretary-plan',
                'tier_type' => 'individual',
                'price_monthly' => 1499.00,
                'price_annual' => 14990.00,
                'max_doctors' => 1,
                'max_clinics' => 1,
                'max_secretaries' => 1,
                'trial_period_days' => 14,
                'grace_period_days' => 3,
                'sort_order' => 2,
                'is_active' => true,
                'features' => [
                    'show_in_recommendation' => true,
                    'can_execute_scan' => true,
                    'export_pdf_reports' => true,
                    'unlimited_appointments' => true,
                    'can_have_secretary' => true,
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
                'max_secretaries' => 3,
                'trial_period_days' => 14,
                'grace_period_days' => 3,
                'sort_order' => 3,
                'is_active' => true,
                'features' => [
                    'show_in_recommendation' => true,
                    'can_execute_scan' => true,
                    'export_pdf_reports' => true,
                    'unlimited_appointments' => true,
                    'can_have_secretary' => true,
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
                'max_secretaries' => 10,
                'trial_period_days' => 14,
                'grace_period_days' => 3,
                'sort_order' => 4,
                'is_active' => true,
                'features' => [
                    'show_in_recommendation' => true,
                    'can_execute_scan' => true,
                    'export_pdf_reports' => true,
                    'unlimited_appointments' => true,
                    'can_have_secretary' => true,
                ],
            ],
        ];

        $allFeatures = Feature::all()->keyBy('code');

        foreach ($plans as $planData) {
            $featuresMap = $planData['features'] ?? [];

            $plan = Plan::updateOrCreate(
                ['slug' => $planData['slug']],
                $planData
            );

            // Sync features in pivot
            foreach ($allFeatures as $code => $feature) {
                $isIncluded = ! empty($featuresMap[$code]);
                DB::table('plan_has_features')->updateOrInsert(
                    [
                        'plan_id' => $plan->id,
                        'feature_id' => $feature->id,
                    ],
                    [
                        'is_included' => $isIncluded,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
