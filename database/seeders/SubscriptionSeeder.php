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
        $individualPlan = Plan::where('slug', 'individual-doctor-plan')->first();
        $multiClinicPlan = Plan::where('slug', 'multi-clinic-doctor-plan')->first();

        // 1. Default Doctor (doctor@dermassist.com) -> ACTIVE SUBSCRIBED
        $defaultDoctor = User::where('email', 'doctor@dermassist.com')->first();
        if ($defaultDoctor && $individualPlan) {
            $sub = Subscription::firstOrCreate(
                [
                    'user_id' => $defaultDoctor->id,
                ],
                [
                    'plan_id' => $individualPlan->id,
                    'status' => 'active',
                    'billing_cycle' => 'monthly',
                    'starts_at' => Carbon::now()->subDays(15),
                    'ends_at' => Carbon::now()->addDays(15),
                    'trial_ends_at' => null,
                    'cancelled_at' => null,
                ]
            );

            // Create Paid Invoice for Default Doctor
            PaymentInvoice::firstOrCreate(
                [
                    'subscription_id' => $sub->id,
                    'user_id' => $defaultDoctor->id,
                ],
                [
                    'amount' => 999.00,
                    'discount_amount' => 0.00,
                    'final_amount' => 999.00,
                    'payment_method' => 'gcash',
                    'payment_status' => 'paid',
                    'proof_of_payment_path' => 'payments/sample_gcash_receipt.png',
                    'transaction_reference' => 'GCASH-REF-889911',
                    'paid_at' => Carbon::now()->subDays(15),
                ]
            );
        }

        // 2. Doctor Beatriz Cruz -> PENDING APPROVAL
        $beatrizDoctor = User::where('email', 'dr.beatriz.cruz@dermassist.com')->first();
        if ($beatrizDoctor && $multiClinicPlan) {
            $subBeatriz = Subscription::firstOrCreate(
                [
                    'user_id' => $beatrizDoctor->id,
                ],
                [
                    'plan_id' => $multiClinicPlan->id,
                    'status' => 'pending',
                    'billing_cycle' => 'annual',
                    'starts_at' => Carbon::now(),
                    'ends_at' => Carbon::now()->addYear(),
                    'trial_ends_at' => null,
                    'cancelled_at' => null,
                ]
            );

            PaymentInvoice::firstOrCreate(
                [
                    'subscription_id' => $subBeatriz->id,
                    'user_id' => $beatrizDoctor->id,
                ],
                [
                    'amount' => 19990.00,
                    'discount_amount' => 0.00,
                    'final_amount' => 19990.00,
                    'payment_method' => 'bank_transfer',
                    'payment_status' => 'pending',
                    'proof_of_payment_path' => 'payments/sample_bank_deposit.png',
                    'transaction_reference' => 'BANK-REF-771122',
                    'paid_at' => null,
                ]
            );
        }

        // 3. Doctor Ricardo Dizon -> EXPIRED / PAST DUE
        $ricardoDoctor = User::where('email', 'dr.ricardo.dizon@dermassist.com')->first();
        if ($ricardoDoctor && $individualPlan) {
            Subscription::firstOrCreate(
                [
                    'user_id' => $ricardoDoctor->id,
                ],
                [
                    'plan_id' => $individualPlan->id,
                    'status' => 'past_due',
                    'billing_cycle' => 'monthly',
                    'starts_at' => Carbon::now()->subDays(45),
                    'ends_at' => Carbon::now()->subDays(15),
                    'trial_ends_at' => null,
                    'cancelled_at' => null,
                ]
            );
        }

        // 4. Doctor Clara Mendoza -> UNSUBSCRIBED (no subscription record)
        // Remains without subscription record to represent unsubscribed doctor.
    }
}
