<?php

namespace Database\Seeders;

use App\Models\PaymentInvoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class SubscriptionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $doctor = User::where('email', 'ben@derm.com')->first();
        $admin = User::where('email', 'sam@derm.com')->first();
        $multiClinicPlan = Plan::where('slug', 'multi-clinic-doctor-plan')->first();

        if ($doctor && $multiClinicPlan) {
            // Assign Multi-Clinic Doctor Plan to Doctor
            $subscription = Subscription::updateOrCreate(
                ['user_id' => $doctor->id],
                [
                    'plan_id' => $multiClinicPlan->id,
                    'plan_version' => $multiClinicPlan->version ?? 1,
                    'plan_snapshot' => $multiClinicPlan->createSnapshot(),
                    'status' => 'active',
                    'billing_cycle' => 'monthly',
                    'starts_at' => Carbon::now()->subDays(15),
                    'ends_at' => Carbon::now()->addDays(15),
                    'auto_renew' => true,
                    'trial_ends_at' => null,
                    'cancelled_at' => null,
                ]
            );

            // Record plan in doctor's payment history
            PaymentInvoice::updateOrCreate(
                [
                    'subscription_id' => $subscription->id,
                    'user_id' => $doctor->id,
                ],
                [
                    'amount' => $multiClinicPlan->price_monthly,
                    'discount_amount' => 0.00,
                    'final_amount' => $multiClinicPlan->price_monthly,
                    'payment_method' => 'gcash',
                    'payment_status' => 'paid',
                    'proof_of_payment_path' => 'payments/sample_gcash_receipt.png',
                    'transaction_reference' => 'GCASH-BEN-1999',
                    'approved_by_user_id' => $admin?->id,
                    'paid_at' => Carbon::now()->subDays(15),
                ]
            );
        }
    }
}
