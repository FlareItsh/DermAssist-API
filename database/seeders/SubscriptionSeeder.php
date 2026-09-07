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
        $clinicGroupPlan = Plan::where('slug', 'clinic-group-plan')->first();

        // 1. Dr. Allan Smith -> ACTIVE Clinic Group Plan (Owner of 10-Doctor Seat Pool)
        $smithDoctor = User::where('email', 'doctor@dermassist.com')->first();
        if ($smithDoctor && $clinicGroupPlan) {
            $subSmith = Subscription::updateOrCreate(
                ['user_id' => $smithDoctor->id],
                [
                    'plan_id' => $clinicGroupPlan->id,
                    'status' => 'active',
                    'billing_cycle' => 'monthly',
                    'starts_at' => Carbon::now()->subDays(15),
                    'ends_at' => Carbon::now()->addDays(15),
                    'trial_ends_at' => null,
                    'cancelled_at' => null,
                ]
            );

            PaymentInvoice::updateOrCreate(
                [
                    'subscription_id' => $subSmith->id,
                    'user_id' => $smithDoctor->id,
                ],
                [
                    'amount' => 4999.00,
                    'discount_amount' => 0.00,
                    'final_amount' => 4999.00,
                    'payment_method' => 'gcash',
                    'payment_status' => 'paid',
                    'proof_of_payment_path' => 'payments/sample_gcash_receipt.png',
                    'transaction_reference' => 'GCASH-SMITH-4999',
                    'paid_at' => Carbon::now()->subDays(15),
                ]
            );
        }

        // 2. Dr. Beatriz Cruz -> ACTIVE Individual Doctor Plan (Also an associate in Dr. Smith's clinic)
        $beatrizDoctor = User::where('email', 'dr.beatriz.cruz@dermassist.com')->first();
        if ($beatrizDoctor && $individualPlan) {
            $subBeatriz = Subscription::updateOrCreate(
                ['user_id' => $beatrizDoctor->id],
                [
                    'plan_id' => $individualPlan->id,
                    'status' => 'active',
                    'billing_cycle' => 'monthly',
                    'starts_at' => Carbon::now()->subDays(10),
                    'ends_at' => Carbon::now()->addDays(20),
                    'trial_ends_at' => null,
                    'cancelled_at' => null,
                ]
            );

            PaymentInvoice::updateOrCreate(
                [
                    'subscription_id' => $subBeatriz->id,
                    'user_id' => $beatrizDoctor->id,
                ],
                [
                    'amount' => 999.00,
                    'discount_amount' => 0.00,
                    'final_amount' => 999.00,
                    'payment_method' => 'bank_transfer',
                    'payment_status' => 'paid',
                    'proof_of_payment_path' => 'payments/sample_bank_deposit.png',
                    'transaction_reference' => 'BANK-CRUZ-999',
                    'paid_at' => Carbon::now()->subDays(10),
                ]
            );
        }

        // 3. Dr. Ricardo Dizon -> PAST DUE / EXPIRED Individual Plan (Covered via associate seat in Dr. Smith's clinic)
        $ricardoDoctor = User::where('email', 'dr.ricardo.dizon@dermassist.com')->first();
        if ($ricardoDoctor && $individualPlan) {
            $subRicardo = Subscription::updateOrCreate(
                ['user_id' => $ricardoDoctor->id],
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

            PaymentInvoice::updateOrCreate(
                [
                    'subscription_id' => $subRicardo->id,
                    'user_id' => $ricardoDoctor->id,
                ],
                [
                    'amount' => 999.00,
                    'discount_amount' => 0.00,
                    'final_amount' => 999.00,
                    'payment_method' => 'gcash',
                    'payment_status' => 'unpaid',
                    'transaction_reference' => 'INV-EXPIRED-DIZON',
                    'paid_at' => null,
                ]
            );
        }

        // 4. Dr. Clara Mendoza -> Pure Associate (No personal subscription record)
        // She relies 100% on Dr. Allan Smith's clinic associate delegation.

        // 5. Dr. Miguel Tan -> ACTIVE Solo Doctor (1 Doctor, 1 Clinic, Not an associate anywhere)
        $miguelDoctor = User::where('email', 'dr.miguel.tan@dermassist.com')->first();
        if ($miguelDoctor && $individualPlan) {
            $subMiguel = Subscription::updateOrCreate(
                ['user_id' => $miguelDoctor->id],
                [
                    'plan_id' => $individualPlan->id,
                    'status' => 'active',
                    'billing_cycle' => 'monthly',
                    'starts_at' => Carbon::now()->subDays(5),
                    'ends_at' => Carbon::now()->addDays(25),
                    'trial_ends_at' => null,
                    'cancelled_at' => null,
                ]
            );

            PaymentInvoice::updateOrCreate(
                [
                    'subscription_id' => $subMiguel->id,
                    'user_id' => $miguelDoctor->id,
                ],
                [
                    'amount' => 999.00,
                    'discount_amount' => 0.00,
                    'final_amount' => 999.00,
                    'payment_method' => 'card',
                    'payment_status' => 'paid',
                    'transaction_reference' => 'CARD-TAN-999',
                    'paid_at' => Carbon::now()->subDays(5),
                ]
            );
        }
    }
}
