<?php

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
