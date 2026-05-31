<?php

use App\Models\DoctorVerification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('it filters users by role and verification status without throwing a 500 error', function () {
    // 1. Create Roles
    $doctorRole = Role::factory()->create([
        'name' => 'Doctor',
        'slug' => 'doctor',
    ]);

    $patientRole = Role::factory()->create([
        'name' => 'Patient',
        'slug' => 'patient',
    ]);

    // 2. Create Doctor Users
    $verifiedDoctor = User::factory()->create([
        'role_id' => $doctorRole->id,
    ]);

    $pendingDoctor = User::factory()->create([
        'role_id' => $doctorRole->id,
    ]);

    $patient = User::factory()->create([
        'role_id' => $patientRole->id,
    ]);

    // 3. Create Doctor Verifications
    DoctorVerification::create([
        'user_id' => $verifiedDoctor->id,
        'prc_number' => '1234567',
        'status' => 'verified',
    ]);

    DoctorVerification::create([
        'user_id' => $pendingDoctor->id,
        'prc_number' => '7654321',
        'status' => 'pending',
    ]);

    // Authenticate the user
    Sanctum::actingAs($verifiedDoctor);

    // 4. Hit the endpoint for verified doctors
    $response = $this->getJson('/api/users?role=doctor&status=verified');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.uuid', $verifiedDoctor->uuid);

    // 5. Hit the endpoint for pending doctors
    $response = $this->getJson('/api/users?role=doctor&status=pending');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.uuid', $pendingDoctor->uuid);

    // 6. Hit the endpoint for patients
    $response = $this->getJson('/api/users?role=patient');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.uuid', $patient->uuid);
});
