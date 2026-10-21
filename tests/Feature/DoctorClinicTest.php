<?php

use App\Models\Clinic;
use App\Models\DoctorAvailability;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
});

test('doctor can list their clinics', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);

    $clinic = Clinic::factory()->create([
        'owner_doctor_id' => $doctor->id,
        'name' => 'St. Jude Dermatology',
        'address' => 'Room 201, Medical Arts',
    ]);

    Sanctum::actingAs($doctor);

    $response = $this->getJson('/api/doctor/clinics');

    $response->assertStatus(200)
        ->assertJsonFragment(['name' => 'St. Jude Dermatology'])
        ->assertJsonFragment(['address' => 'Room 201, Medical Arts']);
});

test('doctor can create a clinic', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);

    Sanctum::actingAs($doctor);

    $response = $this->postJson('/api/doctor/clinics', [
        'name' => 'Skin Health Hub',
        'address' => '5th Floor, Health Tower',
        'phone' => '+63 917 111 2222',
        'email' => 'hub@skinhealth.ph',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Skin Health Hub')
        ->assertJsonPath('data.address', '5th Floor, Health Tower')
        ->assertJsonPath('data.phone', '+63 917 111 2222')
        ->assertJsonPath('data.email', 'hub@skinhealth.ph');

    $this->assertDatabaseHas('clinics', [
        'owner_doctor_id' => $doctor->id,
        'name' => 'Skin Health Hub',
    ]);
});

test('clinic owner can update clinic and syncs existing availability slot names', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);

    $clinic = Clinic::factory()->create([
        'owner_doctor_id' => $doctor->id,
        'name' => 'Old Clinic Name',
        'address' => 'Old Address',
        'phone' => '1234567',
        'email' => 'old@clinic.local',
        'is_active' => true,
    ]);

    $slot = DoctorAvailability::create([
        'doctor_id' => $doctor->id,
        'clinic_id' => $clinic->id,
        'location_name' => 'Old Clinic Name',
        'available_date' => now()->addDays(2)->toDateString(),
        'start_time' => '09:00',
        'end_time' => '12:00',
        'is_available' => true,
    ]);

    Sanctum::actingAs($doctor);

    $response = $this->putJson("/api/doctor/clinics/{$clinic->uuid}", [
        'name' => 'Renovated Skin Care Center',
        'address' => 'Unit 305, East Wing Tower',
        'phone' => '+63 999 888 7777',
        'email' => 'eastwing@skincare.ph',
        'is_active' => true,
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.name', 'Renovated Skin Care Center')
        ->assertJsonPath('data.address', 'Unit 305, East Wing Tower')
        ->assertJsonPath('data.phone', '+63 999 888 7777')
        ->assertJsonPath('data.email', 'eastwing@skincare.ph');

    $this->assertDatabaseHas('clinics', [
        'id' => $clinic->id,
        'name' => 'Renovated Skin Care Center',
        'address' => 'Unit 305, East Wing Tower',
    ]);

    // Verify availability location_name was updated in sync
    $slot->refresh();
    expect($slot->location_name)->toBe('Renovated Skin Care Center');
});

test('non-owner doctor cannot update another doctors clinic', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $owner = User::factory()->create(['role_id' => $doctorRole->id]);
    $otherDoctor = User::factory()->create(['role_id' => $doctorRole->id]);

    $clinic = Clinic::factory()->create([
        'owner_doctor_id' => $owner->id,
        'name' => 'Private Practice',
    ]);

    Sanctum::actingAs($otherDoctor);

    $response = $this->putJson("/api/doctor/clinics/{$clinic->uuid}", [
        'name' => 'Hacked Clinic Name',
    ]);

    $response->assertStatus(403);
});

test('clinic owner can delete clinic', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);

    $clinic = Clinic::factory()->create([
        'owner_doctor_id' => $doctor->id,
        'name' => 'Temporary Clinic',
    ]);

    Sanctum::actingAs($doctor);

    $response = $this->deleteJson("/api/doctor/clinics/{$clinic->uuid}");

    $response->assertStatus(200);

    $this->assertDatabaseMissing('clinics', [
        'id' => $clinic->id,
    ]);
});
