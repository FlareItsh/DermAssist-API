<?php

use App\Models\Diagnosis;
use App\Models\PaymentInvoice;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin can retrieve comprehensive dashboard statistics', function () {
    $adminRole = Role::create(['name' => 'admin', 'slug' => 'admin']);
    $doctorRole = Role::create(['name' => 'doctor', 'slug' => 'doctor']);
    $patientRole = Role::create(['name' => 'patient', 'slug' => 'patient']);

    $admin = User::factory()->create([
        'role_id' => $adminRole->id,
    ]);

    $doctor = User::factory()->create([
        'role_id' => $doctorRole->id,
    ]);

    $patient = User::factory()->create([
        'role_id' => $patientRole->id,
    ]);

    // Create a plan and subscription
    $plan = Plan::create([
        'name' => 'Professional Plan',
        'slug' => 'pro-plan',
        'tier_type' => 'pro',
        'price_monthly' => 1500.00,
        'price_annual' => 15000.00,
        'grace_period_days' => 3,
        'is_active' => true,
    ]);

    $subscription = Subscription::create([
        'user_id' => $doctor->id,
        'plan_id' => $plan->id,
        'billing_cycle' => 'monthly',
        'status' => 'active',
        'auto_renew' => true,
        'starts_at' => now(),
        'ends_at' => now()->addMonth(),
    ]);

    PaymentInvoice::create([
        'subscription_id' => $subscription->id,
        'user_id' => $doctor->id,
        'amount' => 1500.00,
        'discount_amount' => 0.00,
        'final_amount' => 1500.00,
        'payment_method' => 'GCash',
        'payment_status' => 'approved',
        'transaction_reference' => 'PAY-TEST-001',
    ]);

    // Create diagnosis scans
    Diagnosis::create([
        'user_uuid' => $patient->uuid,
        'image_path' => 'scans/test.jpg',
        'label' => 'Melanoma',
        'confidence' => 0.95,
        'probabilities' => ['Melanoma' => 0.95],
        'status' => 'completed',
    ]);

    $response = $this->actingAs($admin)
        ->getJson('/api/admin/dashboard-stats');

    $response->assertOk()
        ->assertJsonStructure([
            'status',
            'data' => [
                'subscription_profits' => [
                    'total_revenue',
                    'growth_rate',
                    'revenue_trend',
                    'active_subscribers_count',
                    'total_subscribed_users',
                    'renewed_users_count',
                    'renewal_rate_percentage',
                    'recent_invoices',
                ],
                'scan_usage' => [
                    'total_scans',
                    'doctor_scans_count',
                    'patient_scans_count',
                    'scans_30d',
                ],
                'user_counts' => [
                    'total_doctors',
                    'total_patients',
                    'new_doctors_30d',
                    'new_patients_30d',
                ],
            ],
        ]);

    $data = $response->json('data');
    expect($data['scan_usage']['total_scans'])->toBe(1);
    expect($data['subscription_profits']['total_revenue'])->toEqual(1500.0);
    expect($data['user_counts']['total_doctors'])->toBe(1);
    expect($data['user_counts']['total_patients'])->toBe(1);
});
