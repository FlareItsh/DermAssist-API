<?php

use App\Models\Feature;
use App\Models\PaymentInvoice;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Repository\PlanRepository;
use App\Service\PaymentInvoiceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->roleDoctor = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);

    $this->pdfFeature = Feature::firstOrCreate(
        ['code' => 'export_pdf_reports'],
        [
            'uuid' => (string) Str::uuid(),
            'name' => 'Allow PDF Clinical Report Exports',
            'description' => 'Export PDF reports',
            'is_active' => true,
            'sort_order' => 3,
        ]
    );

    $this->scanFeature = Feature::firstOrCreate(
        ['code' => 'can_execute_scan'],
        [
            'uuid' => (string) Str::uuid(),
            'name' => 'Allow Doctor AI Scan Execution',
            'description' => 'Run scans',
            'is_active' => true,
            'sort_order' => 2,
        ]
    );
});

test('existing subscriber retains frozen snapshot and does not gain mid-cycle plan updates', function () {
    $planRepo = app(PlanRepository::class);

    $slug = 'practitioner-plan-'.Str::random(8);
    $plan = $planRepo->create([
        'name' => 'Practitioner Plan',
        'slug' => $slug,
        'tier_type' => 'individual',
        'price_monthly' => 999.00,
        'price_annual' => 9990.00,
        'max_doctors' => 1,
        'max_clinics' => 1,
        'max_secretaries' => 0,
        'features' => [
            'can_execute_scan' => true,
            'export_pdf_reports' => false,
        ],
        'is_active' => true,
    ]);

    expect($plan->version)->toBe(1);

    // 2. Doctor subscribes to Plan v1
    $doctor = User::factory()->create([
        'role_id' => $this->roleDoctor->id,
        'account_status' => 'active',
    ]);

    $subscription = Subscription::create([
        'user_id' => $doctor->id,
        'plan_id' => $plan->id,
        'plan_version' => $plan->version,
        'plan_snapshot' => $plan->createSnapshot(),
        'billing_cycle' => 'monthly',
        'status' => 'active',
        'starts_at' => now(),
        'ends_at' => now()->addMonth(),
    ]);

    // Verify initial entitlements
    expect($doctor->canAccessFeature('can_execute_scan'))->toBeTrue();
    expect($doctor->canAccessFeature('export_pdf_reports'))->toBeFalse();
    expect($doctor->getMaxClinics())->toBe(1);
    expect($subscription->hasPlanUpdate())->toBeFalse();

    // 3. Middle of the month: Admin updates the plan to include PDF reports and 3 clinics
    $planRepo->update($plan, [
        'name' => 'Practitioner Plan (Enhanced)',
        'max_clinics' => 3,
        'features' => [
            'can_execute_scan' => true,
            'export_pdf_reports' => true,
        ],
    ]);

    $freshPlan = $plan->fresh();
    expect($freshPlan->version)->toBe(2);
    expect($freshPlan->hasFeature('export_pdf_reports'))->toBeTrue();

    // 4. CRITICAL: Existing subscriber must NOT gain access mid-month
    $doctorFresh = $doctor->fresh();
    expect($doctorFresh->canAccessFeature('export_pdf_reports'))->toBeFalse();
    expect($doctorFresh->getMaxClinics())->toBe(1);
    expect($subscription->fresh()->hasPlanUpdate())->toBeTrue();
});

test('new subscribers immediately gain updated features while old subscribers remain frozen', function () {
    $planRepo = app(PlanRepository::class);

    $plan = $planRepo->create([
        'name' => 'Solo Tier',
        'slug' => 'solo-tier-'.Str::random(8),
        'tier_type' => 'individual',
        'price_monthly' => 800.00,
        'price_annual' => 8000.00,
        'max_clinics' => 1,
        'features' => [
            'export_pdf_reports' => false,
        ],
        'is_active' => true,
    ]);

    // Old subscriber on v1
    $oldDoctor = User::factory()->create(['role_id' => $this->roleDoctor->id]);
    Subscription::create([
        'user_id' => $oldDoctor->id,
        'plan_id' => $plan->id,
        'plan_version' => 1,
        'plan_snapshot' => $plan->createSnapshot(),
        'billing_cycle' => 'monthly',
        'status' => 'active',
        'starts_at' => now(),
        'ends_at' => now()->addMonth(),
    ]);

    // Admin updates plan to v2
    $planRepo->update($plan, [
        'features' => [
            'export_pdf_reports' => true,
        ],
    ]);

    // New subscriber on v2
    $newDoctor = User::factory()->create(['role_id' => $this->roleDoctor->id]);
    Subscription::create([
        'user_id' => $newDoctor->id,
        'plan_id' => $plan->id,
        'plan_version' => $plan->fresh()->version,
        'plan_snapshot' => $plan->fresh()->createSnapshot(),
        'billing_cycle' => 'monthly',
        'status' => 'active',
        'starts_at' => now(),
        'ends_at' => now()->addMonth(),
    ]);

    expect($oldDoctor->canAccessFeature('export_pdf_reports'))->toBeFalse();
    expect($newDoctor->canAccessFeature('export_pdf_reports'))->toBeTrue();
});

test('renewing or upgrading updates the subscription snapshot and unlocks the new features', function () {
    $planRepo = app(PlanRepository::class);

    $plan = $planRepo->create([
        'name' => 'Clinic Tier',
        'slug' => 'clinic-tier-'.Str::random(8),
        'tier_type' => 'individual',
        'price_monthly' => 1000.00,
        'price_annual' => 10000.00,
        'max_clinics' => 1,
        'features' => [
            'export_pdf_reports' => false,
        ],
        'is_active' => true,
    ]);

    $doctor = User::factory()->create(['role_id' => $this->roleDoctor->id]);
    $oldSub = Subscription::create([
        'user_id' => $doctor->id,
        'plan_id' => $plan->id,
        'plan_version' => 1,
        'plan_snapshot' => $plan->createSnapshot(),
        'billing_cycle' => 'monthly',
        'status' => 'active',
        'starts_at' => now()->subDays(25),
        'ends_at' => now()->addDays(5),
    ]);

    // Admin updates plan to v2
    $planRepo->update($plan, [
        'max_clinics' => 5,
        'features' => [
            'export_pdf_reports' => true,
        ],
    ]);

    expect($doctor->canAccessFeature('export_pdf_reports'))->toBeFalse();
    expect($doctor->getMaxClinics())->toBe(1);

    // Doctor renews / upgrades: new subscription & invoice created
    $newSub = Subscription::create([
        'user_id' => $doctor->id,
        'plan_id' => $plan->id,
        'billing_cycle' => 'monthly',
        'status' => 'pending',
        'starts_at' => now(),
        'ends_at' => now()->addMonth(),
    ]);

    $invoice = PaymentInvoice::create([
        'subscription_id' => $newSub->id,
        'user_id' => $doctor->id,
        'amount' => 1000.00,
        'final_amount' => 1000.00,
        'payment_method' => 'paymongo',
        'payment_status' => 'pending',
    ]);

    // Payment is approved
    $invoiceService = app(PaymentInvoiceService::class);
    $invoiceService->approvePayment($invoice, null, 'REF-12345');

    // Doctor now has latest plan snapshot and unlocked features!
    $doctorFresh = $doctor->fresh();
    expect($doctorFresh->canAccessFeature('export_pdf_reports'))->toBeTrue();
    expect($doctorFresh->getMaxClinics())->toBe(5);

    // Old subscription is superseded/cancelled
    expect($oldSub->fresh()->status)->toBe('cancelled');
    expect($newSub->fresh()->status)->toBe('active');
    expect($newSub->fresh()->hasPlanUpdate())->toBeFalse();
});

test('my-subscription API returns plan_snapshot and has_plan_update', function () {
    $planRepo = app(PlanRepository::class);

    $plan = $planRepo->create([
        'name' => 'API Plan',
        'slug' => 'api-plan-'.Str::random(8),
        'tier_type' => 'individual',
        'price_monthly' => 500.00,
        'price_annual' => 5000.00,
        'features' => ['can_execute_scan' => true],
        'is_active' => true,
    ]);

    $doctor = User::factory()->create(['role_id' => $this->roleDoctor->id]);
    Subscription::create([
        'user_id' => $doctor->id,
        'plan_id' => $plan->id,
        'plan_version' => 1,
        'plan_snapshot' => $plan->createSnapshot(),
        'billing_cycle' => 'monthly',
        'status' => 'active',
        'starts_at' => now(),
        'ends_at' => now()->addMonth(),
    ]);

    // Admin increments version
    $planRepo->update($plan, ['name' => 'API Plan Updated']);

    $response = $this->actingAs($doctor)->getJson('/api/subscription/my-subscription');

    $response->assertStatus(200)
        ->assertJsonPath('data.subscription.has_plan_update', true)
        ->assertJsonPath('data.subscription.plan_version', 1)
        ->assertJsonPath('data.subscription.latest_plan_version', 2);
});
