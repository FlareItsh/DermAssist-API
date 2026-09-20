<?php

use App\Console\Commands\ProcessScheduledAccountActions;
use App\Models\DoctorAvailability;
use App\Models\DoctorVerification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->doctorRole = Role::firstOrCreate(['slug' => 'doctor', 'name' => 'Doctor']);
    $this->patientRole = Role::firstOrCreate(['slug' => 'patient', 'name' => 'Patient']);

    $this->doctor = User::factory()->create(['role_id' => $this->doctorRole->id]);
});

test('doctor can create patient', function () {
    Sanctum::actingAs($this->doctor);

    $response = $this->postJson('/api/doctor/patients', [
        'firstName' => 'John',
        'lastName' => 'Doe',
        'email' => 'john@test.com',
        'password' => 'password',
    ]);

    $response->assertStatus(201);

    $this->assertDatabaseHas('users', [
        'email' => 'john@test.com',
        'is_doctor_registered' => true,
        'registered_by_doctor_id' => $this->doctor->id,
        'account_status' => 'active',
    ]);
});

test('disabled patient cannot login', function () {
    $patient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'password' => bcrypt('password'),
        'account_status' => 'disabled',
    ]);

    $response = $this->postJson('/api/login', [
        'email' => $patient->email,
        'password' => 'password',
    ]);

    $response->assertStatus(403);
});

test('doctor can schedule patient action', function () {
    Sanctum::actingAs($this->doctor);
    $patient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'registered_by_doctor_id' => $this->doctor->id,
    ]);

    $response = $this->postJson("/api/doctor/patients/{$patient->uuid}/schedule-action", [
        'action' => 'disable',
        'scheduled_at' => now()->addDay()->toDateTimeString(),
    ]);

    $response->assertStatus(200);
    $this->assertDatabaseHas('users', [
        'id' => $patient->id,
        'account_action' => 'disable',
    ]);
});

test('scheduled patient deletion is processed when time is due', function () {
    $patient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'registered_by_doctor_id' => $this->doctor->id,
        'account_action' => 'delete',
        'account_action_scheduled_at' => now()->subMinute()->toDateTimeString(),
    ]);

    ProcessScheduledAccountActions::processDueActions();

    $this->assertDatabaseMissing('users', [
        'id' => $patient->id,
    ]);
});

test('doctor registered patient cannot create appointment with other doctor', function () {
    $otherDoctor = User::factory()->create(['role_id' => $this->doctorRole->id]);

    $patient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'is_doctor_registered' => true,
        'registered_by_doctor_id' => $this->doctor->id,
        'account_status' => 'active',
    ]);

    Sanctum::actingAs($patient);

    $response = $this->postJson('/api/appointments', [
        'doctor_id' => $otherDoctor->id,
        'message' => 'Want appointment with another doctor',
    ]);

    $response->assertStatus(403);
    $this->assertStringContainsString('registered under an attending doctor', $response->json('message'));
});

test('doctor registered patient can create appointment with registering doctor', function () {
    $patient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'is_doctor_registered' => true,
        'registered_by_doctor_id' => $this->doctor->id,
        'account_status' => 'active',
    ]);

    Sanctum::actingAs($patient);

    $response = $this->postJson('/api/appointments', [
        'doctor_id' => $this->doctor->id,
        'message' => 'Consultation with my assigned doctor',
    ]);

    $response->assertStatus(200);
    $this->assertDatabaseHas('appointments', [
        'doctor_id' => $this->doctor->id,
        'patient_id' => $patient->id,
        'status' => 'pending',
    ]);
});

test('availability check for doctor registered patient does not return alternatives when doctor is unavailable', function () {
    // Block doctor availability
    DoctorAvailability::create([
        'doctor_id' => $this->doctor->id,
        'available_date' => now()->toDateString(),
        'start_time' => '00:00:00',
        'end_time' => '23:59:59',
        'is_available' => false,
    ]);

    // Create another doctor who would otherwise be an alternative
    $otherDoc = User::factory()->create([
        'role_id' => $this->doctorRole->id,
    ]);
    DoctorVerification::create([
        'user_id' => $otherDoc->id,
        'prc_number' => '9988776',
        'status' => 'verified',
    ]);

    $patient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'is_doctor_registered' => true,
        'registered_by_doctor_id' => $this->doctor->id,
        'account_status' => 'active',
    ]);

    Sanctum::actingAs($patient);

    $response = $this->getJson("/api/doctors/{$this->doctor->uuid}/availability-check?date=".now()->toDateString());

    $response->assertStatus(200);
    $response->assertJson([
        'is_available' => false,
        'alternatives' => [],
    ]);
});
