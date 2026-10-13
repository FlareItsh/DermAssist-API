<?php

use App\Models\Feature;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $this->doctorRole = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);

    $this->admin = User::factory()->create([
        'role_id' => $this->adminRole->id,
    ]);

    // Ensure standard features exist
    $this->recFeature = Feature::firstOrCreate(
        ['code' => 'show_in_recommendation'],
        [
            'uuid' => (string) Str::uuid(),
            'name' => 'Show in Patient Scan Recommendations',
            'description' => 'Recommendations feature',
            'is_active' => true,
            'sort_order' => 1,
        ]
    );

    $this->scanFeature = Feature::firstOrCreate(
        ['code' => 'can_execute_scan'],
        [
            'uuid' => (string) Str::uuid(),
            'name' => 'Allow Doctor AI Scan Execution',
            'description' => 'Scan execution feature',
            'is_active' => true,
            'sort_order' => 2,
        ]
    );

    $this->pdfFeature = Feature::firstOrCreate(
        ['code' => 'export_pdf_reports'],
        [
            'uuid' => (string) Str::uuid(),
            'name' => 'Allow PDF Clinical Report Exports',
            'description' => 'PDF export feature',
            'is_active' => true,
            'sort_order' => 3,
        ]
    );

    $this->teleFeature = Feature::firstOrCreate(
        ['code' => 'unlimited_appointments'],
        [
            'uuid' => (string) Str::uuid(),
            'name' => 'Enable Teleconsultation Appointments',
            'description' => 'Appointments feature',
            'is_active' => true,
            'sort_order' => 4,
        ]
    );

    $this->secFeature = Feature::firstOrCreate(
        ['code' => 'can_have_secretary'],
        [
            'uuid' => (string) Str::uuid(),
            'name' => 'Dedicated Secretary Account Access',
            'description' => 'Secretary feature',
            'is_active' => true,
            'sort_order' => 5,
        ]
    );
});

test('admin can list, create, view, update, toggle, and delete subscription plans', function () {
    // 1. List plans
    $listResponse = $this->actingAs($this->admin)->getJson('/api/admin/plans');
    $listResponse->assertStatus(200)
        ->assertJsonPath('status', 'success');

    // 2. Create a new plan with custom highlights and feature flags
    $createPayload = [
        'name' => 'Clinic Growth Tier '.Str::random(5),
        'tier_type' => 'clinic_multi_doctor',
        'price_monthly' => 2499.00,
        'price_annual' => 24990.00,
        'max_doctors' => 5,
        'max_clinics' => 3,
        'max_secretaries' => 2,
        'trial_period_days' => 14,
        'grace_period_days' => 5,
        'features' => [
            'show_in_recommendation' => true,
            'can_execute_scan' => true,
            'export_pdf_reports' => true,
            'unlimited_appointments' => false,
            'can_have_secretary' => true,
            'custom_list' => [
                '24/7 Priority Support',
                'Custom Clinic Branding',
            ],
        ],
        'is_active' => true,
    ];

    $createResponse = $this->actingAs($this->admin)->postJson('/api/admin/plans', $createPayload);
    $createResponse->assertStatus(201)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.tier_type', 'clinic_multi_doctor')
        ->assertJsonPath('data.max_doctors', 5)
        ->assertJsonPath('data.max_clinics', 3)
        ->assertJsonPath('data.max_secretaries', 2)
        ->assertJsonPath('data.trial_period_days', 14)
        ->assertJsonPath('data.grace_period_days', 5)
        ->assertJsonPath('data.version', 1);

    $planId = $createResponse->json('data.id');
    $planUuid = $createResponse->json('data.uuid');

    // Verify DB model and feature flags
    $freshPlan = Plan::with('planFeatures')->find($planId);
    expect($freshPlan)->not->toBeNull();
    expect($freshPlan->hasFeature('show_in_recommendation'))->toBeTrue();
    expect($freshPlan->hasFeature('can_execute_scan'))->toBeTrue();
    expect($freshPlan->hasFeature('export_pdf_reports'))->toBeTrue();
    expect($freshPlan->hasFeature('unlimited_appointments'))->toBeFalse();
    expect($freshPlan->hasFeature('can_have_secretary'))->toBeTrue();
    expect($freshPlan->features['custom_list'])->toBe([
        '24/7 Priority Support',
        'Custom Clinic Branding',
    ]);

    // 3. Show plan
    $showResponse = $this->actingAs($this->admin)->getJson("/api/admin/plans/{$planId}");
    $showResponse->assertStatus(200)
        ->assertJsonPath('data.id', $planId)
        ->assertJsonPath('data.uuid', $planUuid);

    // 4. Update plan (change limits and toggled features)
    $updatePayload = [
        'name' => $createPayload['name'],
        'slug' => $freshPlan->slug,
        'tier_type' => 'clinic_multi_doctor',
        'price_monthly' => 2999.00,
        'price_annual' => 29990.00,
        'max_doctors' => 10,
        'max_clinics' => 5,
        'max_secretaries' => 4,
        'trial_period_days' => 30,
        'grace_period_days' => 7,
        'features' => [
            'show_in_recommendation' => true,
            'can_execute_scan' => true,
            'export_pdf_reports' => false, // toggled off
            'unlimited_appointments' => true, // toggled on
            'can_have_secretary' => true,
            'custom_list' => [
                'Dedicated Account Manager',
            ],
        ],
    ];

    $updateResponse = $this->actingAs($this->admin)->putJson("/api/admin/plans/{$planId}", $updatePayload);
    $updateResponse->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.max_doctors', 10)
        ->assertJsonPath('data.price_monthly', 2999)
        ->assertJsonPath('data.version', 2);

    $updatedPlan = Plan::with('planFeatures')->find($planId);
    expect($updatedPlan->hasFeature('export_pdf_reports'))->toBeFalse();
    expect($updatedPlan->hasFeature('unlimited_appointments'))->toBeTrue();
    expect($updatedPlan->version)->toBe(2);

    // 5. Toggle active status
    $toggleResponse = $this->actingAs($this->admin)->patchJson("/api/admin/plans/{$planId}/toggle-active");
    $toggleResponse->assertStatus(200)
        ->assertJsonPath('data.is_active', false);
    expect($updatedPlan->fresh()->is_active)->toBeFalse();

    // Re-toggle back to active
    $this->actingAs($this->admin)->patchJson("/api/admin/plans/{$planId}/toggle-active");
    expect($updatedPlan->fresh()->is_active)->toBeTrue();

    // 6. Delete plan without subscriptions
    $deleteResponse = $this->actingAs($this->admin)->deleteJson("/api/admin/plans/{$planId}");
    $deleteResponse->assertStatus(200)
        ->assertJsonPath('status', 'success');
    expect(Plan::find($planId))->toBeNull();
});

test('admin cannot delete plan with existing active subscriptions', function () {
    $plan = Plan::create([
        'name' => 'Subscribed Plan '.Str::random(5),
        'slug' => 'subscribed-plan-'.Str::random(5),
        'tier_type' => 'individual',
        'price_monthly' => 999.00,
        'price_annual' => 9990.00,
        'max_doctors' => 1,
        'max_clinics' => 1,
        'max_secretaries' => 0,
        'is_active' => true,
    ]);

    $doctor = User::factory()->create([
        'role_id' => $this->doctorRole->id,
    ]);

    Subscription::create([
        'user_id' => $doctor->id,
        'plan_id' => $plan->id,
        'billing_cycle' => 'monthly',
        'status' => 'active',
        'starts_at' => now(),
        'ends_at' => now()->addMonth(),
    ]);

    $deleteResponse = $this->actingAs($this->admin)->deleteJson("/api/admin/plans/{$plan->id}");
    $deleteResponse->assertStatus(422)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', 'Cannot delete plan with existing subscriptions. Deactivate it instead.');

    expect(Plan::find($plan->id))->not->toBeNull();
});

test('can_have_secretary feature flag is automatically synced from max_secretaries quota', function () {
    // 1. max_secretaries = 0 should result in can_have_secretary = false, even if sent true in payload
    $resZero = $this->actingAs($this->admin)->postJson('/api/admin/plans', [
        'name' => 'Solo Doctor '.Str::random(5),
        'tier_type' => 'individual',
        'price_monthly' => 500.00,
        'price_annual' => 5000.00,
        'max_secretaries' => 0,
        'features' => ['can_have_secretary' => true],
    ]);
    $resZero->assertStatus(201);
    $planZero = Plan::find($resZero->json('data.id'));
    expect($planZero->hasFeature('can_have_secretary'))->toBeFalse();

    // 2. max_secretaries = 3 should result in can_have_secretary = true, even if sent false in payload
    $resSeats = $this->actingAs($this->admin)->postJson('/api/admin/plans', [
        'name' => 'Clinic Tier '.Str::random(5),
        'tier_type' => 'clinic_multi_doctor',
        'price_monthly' => 2000.00,
        'price_annual' => 20000.00,
        'max_secretaries' => 3,
        'features' => ['can_have_secretary' => false],
    ]);
    $resSeats->assertStatus(201);
    $planSeats = Plan::find($resSeats->json('data.id'));
    expect($planSeats->hasFeature('can_have_secretary'))->toBeTrue();

    // 3. Update plan from 3 to 0 secretaries should auto-sync flag to false
    $resUpdate = $this->actingAs($this->admin)->putJson("/api/admin/plans/{$planSeats->id}", [
        'max_secretaries' => 0,
    ]);
    $resUpdate->assertStatus(200);
    $freshSeats = $planSeats->fresh();
    expect($freshSeats->hasFeature('can_have_secretary'))->toBeFalse();
});
