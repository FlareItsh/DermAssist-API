<?php

use App\Models\DoctorAvailability;
use App\Models\DoctorVerification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('a doctor can set and manage availability windows', function () {
    // 1. Create Role
    $doctorRole = Role::factory()->create([
        'slug' => 'doctor',
    ]);

    // 2. Create Doctor User
    $doctor = User::factory()->create([
        'role_id' => $doctorRole->id,
    ]);

    Sanctum::actingAs($doctor);

    // 3. Create Availability (POST)
    $response = $this->postJson("/api/doctors/{$doctor->uuid}/availabilities", [
        'available_date' => now()->addDays(2)->toDateString(),
        'start_time' => '09:00',
        'end_time' => '12:00',
        'is_available' => true,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('is_available', true);

    $availabilityUuid = $response->json('uuid');

    // 4. List Availabilities (GET)
    $response = $this->getJson("/api/doctors/{$doctor->uuid}/availabilities");
    $response->assertSuccessful()
        ->assertJsonCount(1);

    // 5. Update Availability (PUT)
    $response = $this->putJson("/api/availabilities/{$availabilityUuid}", [
        'is_available' => false,
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('is_available', false);

    // 6. Delete Availability (DELETE)
    $response = $this->deleteJson("/api/availabilities/{$availabilityUuid}");
    $response->assertSuccessful();

    $this->assertDatabaseMissing('doctor_availabilities', [
        'uuid' => $availabilityUuid,
    ]);
});

test('checking doctor availability and showing next available or alternatives', function () {
    $doctorRole = Role::factory()->create(['slug' => 'doctor']);
    $patientRole = Role::factory()->create(['slug' => 'patient']);

    $doctor1 = User::factory()->create([
        'role_id' => $doctorRole->id,
        'city' => 'Manila',
        'province' => 'Metro Manila',
    ]);

    $doctor2 = User::factory()->create([
        'role_id' => $doctorRole->id,
        'city' => 'Manila',
        'province' => 'Metro Manila',
    ]);

    // Verify doctors
    DoctorVerification::create([
        'user_id' => $doctor1->id,
        'prc_number' => '1111111',
        'status' => 'verified',
    ]);

    DoctorVerification::create([
        'user_id' => $doctor2->id,
        'prc_number' => '2222222',
        'status' => 'verified',
    ]);

    $patient = User::factory()->create([
        'role_id' => $patientRole->id,
        'city' => 'Manila',
        'province' => 'Metro Manila',
    ]);

    // Add availability for Doctor 2 on a specific day/time, but NOT Doctor 1
    $targetDate = now()->addDays(2);
    $targetDateStr = $targetDate->toDateString();

    DoctorAvailability::create([
        'doctor_id' => $doctor2->id,
        'available_date' => $targetDateStr,
        'start_time' => '10:00:00',
        'end_time' => '14:00:00',
        'is_available' => true,
    ]);

    // Add a future availability for Doctor 1 (so we can test "next available")
    $futureDate = now()->addDays(5);
    $futureDateStr = $futureDate->toDateString();

    DoctorAvailability::create([
        'doctor_id' => $doctor1->id,
        'available_date' => $futureDateStr,
        'start_time' => '09:00:00',
        'end_time' => '12:00:00',
        'is_available' => true,
    ]);

    Sanctum::actingAs($patient);

    // Hit the availability check for Doctor 1 (who is unavailable at target date/time)
    $checkUrl = "/api/doctors/{$doctor1->uuid}/availability-check?date=".urlencode($targetDateStr.' 11:00:00');
    $response = $this->getJson($checkUrl);

    $response->assertSuccessful()
        ->assertJsonPath('is_available', false)
        ->assertJsonPath('next_available.date', $futureDateStr)
        ->assertJsonCount(1, 'alternatives')
        ->assertJsonPath('alternatives.0.uuid', $doctor2->uuid);
});

test('booking appointment with unavailable doctor flags the availability and suggests alternatives', function () {
    $doctorRole = Role::factory()->create(['slug' => 'doctor']);
    $patientRole = Role::factory()->create(['slug' => 'patient']);

    $doctor1 = User::factory()->create([
        'role_id' => $doctorRole->id,
        'city' => 'Cebu',
        'province' => 'Cebu',
    ]);

    $doctor2 = User::factory()->create([
        'role_id' => $doctorRole->id,
        'city' => 'Cebu',
        'province' => 'Cebu',
    ]);

    // Verify doctors
    DoctorVerification::create([
        'user_id' => $doctor1->id,
        'prc_number' => '1111111',
        'status' => 'verified',
    ]);

    DoctorVerification::create([
        'user_id' => $doctor2->id,
        'prc_number' => '2222222',
        'status' => 'verified',
    ]);

    $patient = User::factory()->create([
        'role_id' => $patientRole->id,
        'city' => 'Cebu',
        'province' => 'Cebu',
    ]);

    // Set Doctor 2 available now, Doctor 1 unavailable
    $nowStr = now()->toDateString();

    DoctorAvailability::create([
        'doctor_id' => $doctor2->id,
        'available_date' => $nowStr,
        'start_time' => '00:00:00',
        'end_time' => '23:59:00',
        'is_available' => true,
    ]);

    Sanctum::actingAs($patient);

    // Book appointment with Doctor 1 (who has no availabilities at all -> unavailable)
    $response = $this->postJson('/api/appointments', [
        'doctor_id' => $doctor1->id,
        'message' => 'Consultation request',
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('doctor_availability.is_available', false)
        ->assertJsonCount(1, 'doctor_availability.alternatives')
        ->assertJsonPath('doctor_availability.alternatives.0.uuid', $doctor2->uuid);
});
