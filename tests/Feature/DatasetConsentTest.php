<?php

use App\Models\Diagnosis;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->doctorRole = Role::firstOrCreate(['slug' => 'doctor', 'name' => 'Doctor']);
    $this->patientRole = Role::firstOrCreate(['slug' => 'patient', 'name' => 'Patient']);

    $this->doctor = User::factory()->create(['role_id' => $this->doctorRole->id]);
});

test('patient can register with dataset consent', function () {
    $response = $this->postJson('/api/register', [
        'firstName' => 'Consenting',
        'lastName' => 'Patient',
        'email' => 'consent@test.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'patient',
        'consent_dataset' => true,
        'agree_to_terms' => true,
    ]);

    $response->assertStatus(201);

    $this->assertDatabaseHas('users', [
        'email' => 'consent@test.com',
        'consent_dataset' => true,
    ]);

    $user = User::where('email', 'consent@test.com')->first();
    expect($user->terms_accepted_at)->not->toBeNull();
});

test('doctor created patient defaults to consent false', function () {
    Sanctum::actingAs($this->doctor);

    $response = $this->postJson('/api/doctor/patients', [
        'firstName' => 'Clinic',
        'lastName' => 'Patient',
        'email' => 'clinicpatient@test.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(201);

    $this->assertDatabaseHas('users', [
        'email' => 'clinicpatient@test.com',
        'is_doctor_registered' => true,
        'consent_dataset' => false,
    ]);
});

test('saveFromDiagnosis blocks saving when patient has not consented', function () {
    Storage::fake('public');
    Sanctum::actingAs($this->doctor);

    $patient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'consent_dataset' => false,
    ]);

    Storage::disk('public')->put('diagnoses/scan1.jpg', 'fake-image-bytes');
    $path = 'diagnoses/scan1.jpg';

    $diagnosis = Diagnosis::create([
        'user_uuid' => $this->doctor->uuid,
        'doctor_uuid' => $this->doctor->uuid,
        'patient_uuid' => $patient->uuid,
        'image_path' => $path,
        'label' => 'Eczema',
        'confidence' => 0.95,
        'probabilities' => ['Eczema' => 0.95],
        'status' => 'completed',
        'patient_consented_dataset' => false,
    ]);

    $response = $this->postJson('/api/dataset/save-diagnosis', [
        'diagnosis_uuid' => $diagnosis->uuid,
    ]);

    $response->assertStatus(403);
    $response->assertJsonFragment([
        'status' => 'error',
    ]);

    // Ensure it was NOT copied to dataset
    expect(Storage::disk('public')->exists('dataset/eczema/'.basename($path)))->toBeFalse();
});

test('saveFromDiagnosis succeeds when patient has consented', function () {
    Storage::fake('public');
    Sanctum::actingAs($this->doctor);

    $patient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'consent_dataset' => true,
    ]);

    Storage::disk('public')->put('diagnoses/scan2.jpg', 'fake-image-bytes');
    $path = 'diagnoses/scan2.jpg';

    $diagnosis = Diagnosis::create([
        'user_uuid' => $this->doctor->uuid,
        'doctor_uuid' => $this->doctor->uuid,
        'patient_uuid' => $patient->uuid,
        'image_path' => $path,
        'label' => 'Eczema',
        'confidence' => 0.95,
        'probabilities' => ['Eczema' => 0.95],
        'status' => 'completed',
        'patient_consented_dataset' => true,
    ]);

    $response = $this->postJson('/api/dataset/save-diagnosis', [
        'diagnosis_uuid' => $diagnosis->uuid,
    ]);

    $response->assertStatus(200);
    $response->assertJson(['message' => 'Saved to dataset']);

    // Ensure it was copied to dataset
    expect(Storage::disk('public')->exists('dataset/eczema/'.basename($path)))->toBeTrue();

    $diagnosis->refresh();
    expect($diagnosis->contributed_to_dataset)->toBeTrue();
    expect($diagnosis->contributed_at)->not->toBeNull();
});
