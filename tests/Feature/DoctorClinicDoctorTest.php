<?php

use App\Models\Clinic;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
});

test('allows clinic owner with multi-doctor plan to list seat usage and assigned doctors', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();

    $owner = User::factory()->create(['role_id' => $doctorRole->id]);
    $clinic = Clinic::factory()->create([
        'owner_doctor_id' => $owner->id,
        'name' => 'Metro Skin Clinic',
    ]);

    $plan = Plan::factory()->create([
        'name' => 'Clinic Group Plan',
        'tier_type' => 'clinic_multi_doctor',
        'max_doctors' => 10,
        'max_clinics' => 3,
    ]);

    Subscription::factory()->create([
        'user_id' => $owner->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'ends_at' => now()->addMonth(),
    ]);

    Sanctum::actingAs($owner);
    $response = $this->getJson('/api/doctor/clinic-doctors');

    $response->assertStatus(200)
        ->assertJsonPath('seat_usage.max_doctors', 10)
        ->assertJsonPath('seat_usage.used_seats', 1)
        ->assertJsonPath('seat_usage.available_seats', 9)
        ->assertJsonPath('seat_usage.can_add', true);
});

test('allows searching eligible candidate doctors', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();

    $owner = User::factory()->create(['role_id' => $doctorRole->id]);
    $candidate = User::factory()->create([
        'role_id' => $doctorRole->id,
        'first_name' => 'Maria',
        'last_name' => 'Santos',
        'email' => 'dr.maria.santos@dermassist.ph',
        'prc_number' => 'PRC-998877',
        'account_status' => 'active',
    ]);

    Sanctum::actingAs($owner);
    $response = $this->getJson('/api/doctor/clinic-doctors/search?query=Santos');

    $response->assertStatus(200);
    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['email'])->toBe('dr.maria.santos@dermassist.ph');
});

test('allows assigning an associate doctor to a clinic branch within quota', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();

    $owner = User::factory()->create(['role_id' => $doctorRole->id]);
    $associate = User::factory()->create([
        'role_id' => $doctorRole->id,
        'first_name' => 'John',
        'last_name' => 'Reyes',
        'email' => 'dr.reyes@dermassist.ph',
        'account_status' => 'active',
    ]);

    $clinic = Clinic::factory()->create([
        'owner_doctor_id' => $owner->id,
        'name' => 'Metro Skin Clinic',
    ]);

    $plan = Plan::factory()->create([
        'tier_type' => 'clinic_multi_doctor',
        'max_doctors' => 5,
    ]);

    Subscription::factory()->create([
        'user_id' => $owner->id,
        'plan_id' => $plan->id,
        'status' => 'active',
    ]);

    Sanctum::actingAs($owner);
    $response = $this->postJson('/api/doctor/clinic-doctors', [
        'clinic_id' => $clinic->id,
        'doctor_id' => $associate->id,
        'role' => 'associate',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('seat_usage.used_seats', 2)
        ->assertJsonPath('seat_usage.available_seats', 3);

    $this->assertDatabaseHas('clinic_doctors', [
        'clinic_id' => $clinic->id,
        'doctor_user_id' => $associate->id,
        'role' => 'associate',
        'status' => 'pending',
    ]);
});

test('prevents assigning associate doctor if plan only allows 1 doctor', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();

    $owner = User::factory()->create(['role_id' => $doctorRole->id]);
    $associate = User::factory()->create(['role_id' => $doctorRole->id]);
    $clinic = Clinic::factory()->create(['owner_doctor_id' => $owner->id]);

    $singlePlan = Plan::factory()->create([
        'max_doctors' => 1,
    ]);

    Subscription::factory()->create([
        'user_id' => $owner->id,
        'plan_id' => $singlePlan->id,
        'status' => 'active',
    ]);

    Sanctum::actingAs($owner);
    $response = $this->postJson('/api/doctor/clinic-doctors', [
        'clinic_id' => $clinic->id,
        'doctor_id' => $associate->id,
    ]);

    $response->assertStatus(403);
});

test('prevents assigning associate doctor when max_doctors quota is full', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();

    $owner = User::factory()->create(['role_id' => $doctorRole->id]);
    $clinic = Clinic::factory()->create(['owner_doctor_id' => $owner->id]);

    $plan = Plan::factory()->create([
        'max_doctors' => 2, // 1 owner + 1 associate max
    ]);

    Subscription::factory()->create([
        'user_id' => $owner->id,
        'plan_id' => $plan->id,
        'status' => 'active',
    ]);

    $doc1 = User::factory()->create(['role_id' => $doctorRole->id]);
    $doc2 = User::factory()->create(['role_id' => $doctorRole->id]);

    Sanctum::actingAs($owner);
    // Add 1st associate (Total: 2 seats used, quota full)
    $res1 = $this->postJson('/api/doctor/clinic-doctors', [
        'clinic_id' => $clinic->id,
        'doctor_id' => $doc1->id,
    ]);
    $res1->assertStatus(201);

    // Try to add 2nd associate
    $res2 = $this->postJson('/api/doctor/clinic-doctors', [
        'clinic_id' => $clinic->id,
        'doctor_id' => $doc2->id,
    ]);
    $res2->assertStatus(422);
});

test('allows removing an associate doctor seat', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();

    $owner = User::factory()->create(['role_id' => $doctorRole->id]);
    $clinic = Clinic::factory()->create(['owner_doctor_id' => $owner->id]);

    $plan = Plan::factory()->create(['max_doctors' => 5]);
    Subscription::factory()->create([
        'user_id' => $owner->id,
        'plan_id' => $plan->id,
        'status' => 'active',
    ]);

    $associate = User::factory()->create(['role_id' => $doctorRole->id]);

    Sanctum::actingAs($owner);
    $addRes = $this->postJson('/api/doctor/clinic-doctors', [
        'clinic_id' => $clinic->id,
        'doctor_id' => $associate->id,
    ]);
    $pivotId = $addRes->json('data.pivot_id');

    $delRes = $this->deleteJson('/api/doctor/clinic-doctors/'.$pivotId);
    $delRes->assertStatus(200);

    $this->assertDatabaseMissing('clinic_doctors', [
        'id' => $pivotId,
    ]);
});

test('assigned associate doctor inherits active subscription capabilities', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();

    $owner = User::factory()->create(['role_id' => $doctorRole->id]);
    $clinic = Clinic::factory()->create(['owner_doctor_id' => $owner->id]);

    $plan = Plan::factory()->create([
        'name' => 'Clinic Group Plan',
        'max_doctors' => 10,
    ]);

    $subscription = Subscription::factory()->create([
        'user_id' => $owner->id,
        'plan_id' => $plan->id,
        'status' => 'active',
    ]);

    $associate = User::factory()->create(['role_id' => $doctorRole->id]);

    // Before assignment: associate has no active subscription
    expect($associate->getActiveSubscription())->toBeNull();

    // Owner sends invitation
    Sanctum::actingAs($owner);
    $assignRes = $this->postJson('/api/doctor/clinic-doctors', [
        'clinic_id' => $clinic->id,
        'doctor_id' => $associate->id,
    ])->assertStatus(201);

    $pivotId = $assignRes->json('data.pivot_id');

    // While invitation is pending: associate does NOT inherit subscription yet
    expect($associate->fresh()->getActiveSubscription())->toBeNull();

    // Associate checks pending invitations
    Sanctum::actingAs($associate);
    $invitesRes = $this->getJson('/api/doctor/clinic-doctors/invitations')->assertStatus(200);
    expect($invitesRes->json('data'))->toHaveCount(1);
    expect($invitesRes->json('data.0.pivot_id'))->toBe($pivotId);

    // Associate accepts invitation
    $this->postJson("/api/doctor/clinic-doctors/invitations/{$pivotId}/accept")->assertStatus(200);

    // After accepting: associate resolves owner's active subscription
    $associateFresh = $associate->fresh();
    expect($associateFresh->getActiveSubscription())->not->toBeNull();
    expect($associateFresh->getActiveSubscription()->id)->toBe($subscription->id);
});

test('doctor can decline a clinic seat invitation', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();

    $owner = User::factory()->create(['role_id' => $doctorRole->id]);
    $associate = User::factory()->create(['role_id' => $doctorRole->id]);
    $clinic = Clinic::factory()->create(['owner_doctor_id' => $owner->id]);

    $plan = Plan::factory()->create([
        'max_doctors' => 5,
    ]);

    Subscription::factory()->create([
        'user_id' => $owner->id,
        'plan_id' => $plan->id,
        'status' => 'active',
    ]);

    Sanctum::actingAs($owner);
    $assignRes = $this->postJson('/api/doctor/clinic-doctors', [
        'clinic_id' => $clinic->id,
        'doctor_id' => $associate->id,
    ])->assertStatus(201);

    $pivotId = $assignRes->json('data.pivot_id');

    // Associate declines invitation
    Sanctum::actingAs($associate);
    $this->postJson("/api/doctor/clinic-doctors/invitations/{$pivotId}/decline")->assertStatus(200);

    // Record removed from database
    $this->assertDatabaseMissing('clinic_doctors', [
        'id' => $pivotId,
    ]);

    // Seat count frees up
    expect($owner->fresh()->getDoctorSeatUsage()['used_seats'])->toBe(1);
});
