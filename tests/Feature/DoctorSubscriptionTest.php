<?php

use App\Models\PaymentInvoice;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

test('doctor can list active subscription plans', function () {
    $role = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    $doctor = User::factory()->create(['role_id' => $role->id]);
    Plan::factory()->create(['is_active' => true, 'name' => 'Pro Plan']);

    $response = $this->actingAs($doctor)
        ->getJson('/api/subscription/plans');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'data' => [
                '*' => ['uuid', 'name', 'tier_type', 'price_monthly', 'price_annual'],
            ],
        ]);
});

test('doctor can view current subscription status', function () {
    $role = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    $doctor = User::factory()->create(['role_id' => $role->id]);

    $response = $this->actingAs($doctor)
        ->getJson('/api/subscription/my-subscription');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'data' => ['subscription', 'invoices'],
        ]);
});

test('doctor can toggle auto-renew off and on', function () {
    $role = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    $doctor = User::factory()->create(['role_id' => $role->id]);
    $plan = Plan::factory()->create(['is_active' => true]);
    $subscription = Subscription::factory()->create([
        'user_id' => $doctor->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'auto_renew' => true,
        'ends_at' => now()->addMonth(),
    ]);

    // Turn off auto-renew
    $responseOff = $this->actingAs($doctor)
        ->postJson('/api/subscription/toggle-auto-renew', [
            'auto_renew' => false,
        ]);

    $responseOff->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.subscription.auto_renew', false);

    expect($subscription->fresh()->auto_renew)->toBeFalse();

    // Turn on auto-renew
    $responseOn = $this->actingAs($doctor)
        ->postJson('/api/subscription/toggle-auto-renew', [
            'auto_renew' => true,
        ]);

    $responseOn->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.subscription.auto_renew', true);

    expect($subscription->fresh()->auto_renew)->toBeTrue();
});

test('doctor can cancel subscription at end of billing cycle', function () {
    $role = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    $doctor = User::factory()->create(['role_id' => $role->id]);
    $plan = Plan::factory()->create(['is_active' => true]);
    $endsAt = now()->addDays(20);
    $subscription = Subscription::factory()->create([
        'user_id' => $doctor->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'auto_renew' => true,
        'ends_at' => $endsAt,
    ]);

    $response = $this->actingAs($doctor)
        ->postJson('/api/subscription/cancel', [
            'reason' => 'Cost is too high',
            'feedback' => 'Need budget tier',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.subscription.auto_renew', false)
        ->assertJsonPath('data.subscription.is_pending_cancellation', true)
        ->assertJsonPath('data.subscription.status', 'active');

    $freshSub = $subscription->fresh();
    expect($freshSub->auto_renew)->toBeFalse();
    expect($freshSub->status)->toBe('active'); // Still active until ends_at!
    expect($freshSub->cancelled_at)->not->toBeNull();
    expect($freshSub->cancellation_reason)->toContain('Cost is too high');
    expect($freshSub->isPendingCancellation())->toBeTrue();
    expect($freshSub->isActive())->toBeTrue();
});

test('doctor can resume a subscription scheduled for cancellation', function () {
    $role = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    $doctor = User::factory()->create(['role_id' => $role->id]);
    $plan = Plan::factory()->create(['is_active' => true]);
    $subscription = Subscription::factory()->create([
        'user_id' => $doctor->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'auto_renew' => false,
        'cancelled_at' => now(),
        'cancellation_reason' => 'Will switch',
        'ends_at' => now()->addDays(15),
    ]);

    $response = $this->actingAs($doctor)
        ->postJson('/api/subscription/resume');

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.subscription.auto_renew', true)
        ->assertJsonPath('data.subscription.is_pending_cancellation', false);

    $freshSub = $subscription->fresh();
    expect($freshSub->auto_renew)->toBeTrue();
    expect($freshSub->cancelled_at)->toBeNull();
    expect($freshSub->cancellation_reason)->toBeNull();
});

test('associate doctor without personal subscription cannot cancel clinic owner plan', function () {
    $role = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    $associate = User::factory()->create(['role_id' => $role->id]);

    $response = $this->actingAs($associate)
        ->postJson('/api/subscription/cancel', [
            'reason' => 'Should not work',
        ]);

    $response->assertStatus(404);
});

test('scheduled renewal command marks expired subscriptions when auto_renew is false', function () {
    $role = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    $doctor = User::factory()->create(['role_id' => $role->id]);
    $plan = Plan::factory()->create();

    $expiredSub = Subscription::factory()->create([
        'user_id' => $doctor->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'auto_renew' => false,
        'ends_at' => now()->subDay(),
    ]);

    Artisan::call('subscriptions:process-renewals');

    expect($expiredSub->fresh()->status)->toBe('expired');
});

test('scheduled renewal command rolls over subscriptions when auto_renew is true', function () {
    $role = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    $doctor = User::factory()->create(['role_id' => $role->id]);
    $plan = Plan::factory()->create(['price_monthly' => 1500]);

    $pastEndsAt = now()->subHour();
    $sub = Subscription::factory()->create([
        'user_id' => $doctor->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'auto_renew' => true,
        'billing_cycle' => 'monthly',
        'ends_at' => $pastEndsAt,
    ]);

    Artisan::call('subscriptions:process-renewals');

    $freshSub = $sub->fresh();
    expect($freshSub->status)->toBe('active');
    expect($freshSub->ends_at->isFuture())->toBeTrue();
    expect(PaymentInvoice::where('subscription_id', $sub->id)->where('payment_method', 'Auto-Renew')->exists())->toBeTrue();
});

test('doctor cannot renew or checkout the same plan while an active subscription exists', function () {
    $role = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    $doctor = User::factory()->create(['role_id' => $role->id]);
    $plan = Plan::factory()->create(['price_monthly' => 1500]);

    Subscription::factory()->create([
        'user_id' => $doctor->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'ends_at' => now()->addMonth(),
    ]);

    $response = $this->actingAs($doctor)
        ->postJson('/api/subscription/checkout', [
            'plan_uuid' => $plan->uuid,
            'billing_cycle' => 'monthly',
            'payment_method' => 'manual',
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('status', 'error');

    expect($response->json('message'))->toContain('Renewal of this plan is only available once your current subscription expires');
});

test('doctor can switch or upgrade to a different tier while holding an active subscription', function () {
    $role = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    $doctor = User::factory()->create(['role_id' => $role->id]);
    $currentPlan = Plan::factory()->create(['name' => 'Basic Plan', 'price_monthly' => 1000]);
    $higherPlan = Plan::factory()->create(['name' => 'Pro Plan', 'price_monthly' => 3000]);

    Subscription::factory()->create([
        'user_id' => $doctor->id,
        'plan_id' => $currentPlan->id,
        'status' => 'active',
        'ends_at' => now()->addMonth(),
    ]);

    $response = $this->actingAs($doctor)
        ->postJson('/api/subscription/checkout', [
            'plan_uuid' => $higherPlan->uuid,
            'billing_cycle' => 'monthly',
            'payment_method' => 'manual',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('status', 'success');
});

test('doctor can renew the same plan once their subscription has expired', function () {
    $role = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    $doctor = User::factory()->create(['role_id' => $role->id]);
    $plan = Plan::factory()->create(['price_monthly' => 1500]);

    Subscription::factory()->create([
        'user_id' => $doctor->id,
        'plan_id' => $plan->id,
        'status' => 'expired',
        'ends_at' => now()->subDay(),
    ]);

    $response = $this->actingAs($doctor)
        ->postJson('/api/subscription/checkout', [
            'plan_uuid' => $plan->uuid,
            'billing_cycle' => 'monthly',
            'payment_method' => 'manual',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('status', 'success');
});
