<?php

use App\Models\Appeal;
use App\Models\Appointment;
use App\Models\Diagnosis;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->doctorRole = Role::firstOrCreate(['slug' => 'doctor', 'name' => 'Doctor']);
    $this->patientRole = Role::firstOrCreate(['slug' => 'patient', 'name' => 'Patient']);
    $this->adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);

    $this->doctor = User::factory()->create(['role_id' => $this->doctorRole->id]);
    $this->patient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'consent_dataset' => true,
    ]);
});

test('face-to-face consultation cannot complete without in-clinic scan or documented exception', function () {
    Sanctum::actingAs($this->doctor);

    // Home pre-consultation scan
    $homeScan = Diagnosis::create([
        'user_uuid' => $this->patient->uuid,
        'patient_uuid' => $this->patient->uuid,
        'label' => 'Acne',
        'confidence' => 0.88,
        'scan_type' => 'home',
        'is_technical_failure' => false,
        'status' => 'completed',
    ]);

    $appointment = Appointment::create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient->id,
        'diagnosis_id' => $homeScan->id,
        'scheduled_at' => now()->addDay(),
        'status' => 'in_consultation',
        'location' => 'Room 101 Clinic',
    ]);

    // Attempting completion without in-clinic scan must fail with 422
    $response = $this->patchJson("/api/appointments/{$appointment->uuid}", [
        'status' => 'completed',
    ]);

    $response->assertStatus(422);

    expect($appointment->fresh()->status)->toBe('in_consultation');
});

test('face-to-face consultation can complete with documented scan exception', function () {
    Sanctum::actingAs($this->doctor);

    $appointment = Appointment::create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient->id,
        'scheduled_at' => now()->addDay(),
        'status' => 'in_consultation',
        'location' => 'In-Clinic Room 204',
    ]);

    // Record scan exception
    $exceptionResponse = $this->postJson("/api/appointments/{$appointment->uuid}/scan-exception", [
        'reason' => 'Patient has lesion in sensitive non-photographable location, physical exam performed.',
        'category' => 'lesion_inaccessible',
    ]);

    $exceptionResponse->assertStatus(200);

    // Appointment must now permit completion
    $completeResponse = $this->patchJson("/api/appointments/{$appointment->uuid}", [
        'status' => 'completed',
    ]);

    $completeResponse->assertStatus(200);
    expect($appointment->fresh()->status)->toBe('completed')
        ->and($appointment->fresh()->scan_exception_reason)->not->toBeNull();
});

test('attaching in-clinic scan satisfies consultation scan gate', function () {
    Sanctum::actingAs($this->doctor);

    $appointment = Appointment::create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient->id,
        'scheduled_at' => now()->addDay(),
        'status' => 'in_consultation',
        'location' => 'Main Clinic',
    ]);

    $inClinicScan = Diagnosis::create([
        'user_uuid' => $this->doctor->uuid,
        'doctor_uuid' => $this->doctor->uuid,
        'patient_uuid' => $this->patient->uuid,
        'label' => 'Herpes',
        'confidence' => 0.94,
        'scan_type' => 'in_clinic',
        'is_technical_failure' => false,
        'status' => 'completed',
    ]);

    $attachResponse = $this->postJson("/api/appointments/{$appointment->uuid}/attach-in-clinic-scan", [
        'diagnosis_uuid' => $inClinicScan->uuid,
    ]);

    $attachResponse->assertStatus(200);
    expect($appointment->fresh()->in_clinic_diagnosis_id)->toBe($inClinicScan->id);

    // Now completion succeeds
    $completeResponse = $this->patchJson("/api/appointments/{$appointment->uuid}", [
        'status' => 'completed',
    ]);

    $completeResponse->assertStatus(200);
    expect($appointment->fresh()->status)->toBe('completed');
});

test('technical failure scans can be recorded and cannot be appealed as AI predictions', function () {
    Sanctum::actingAs($this->doctor);

    $response = $this->postJson('/api/diagnoses/technical-failure', [
        'reason' => 'Severe camera blur and focus failure during capture.',
        'patient_uuid' => $this->patient->uuid,
    ]);

    $response->assertStatus(201);
    expect($response->json('is_technical_failure'))->toBeTrue()
        ->and($response->json('label'))->toBeNull()
        ->and($response->json('confidence'))->toBeNull();

    $diagnosisUuid = $response->json('uuid');

    // Attempting to appeal a technical failure must be rejected
    $appealResponse = $this->postJson('/api/appeals', [
        'user_uuid' => $this->doctor->uuid,
        'diagnosis_uuid' => $diagnosisUuid,
        'diagnosis_label' => 'None',
        'suggested_label' => 'Eczema',
    ]);

    $appealResponse->assertStatus(422);
});

test('doctor appeal resubmission links parent appeal and increments count', function () {
    Sanctum::actingAs($this->doctor);

    $diagnosis = Diagnosis::create([
        'user_uuid' => $this->doctor->uuid,
        'doctor_uuid' => $this->doctor->uuid,
        'patient_uuid' => $this->patient->uuid,
        'label' => 'Acne',
        'confidence' => 0.90,
        'status' => 'completed',
        'patient_consented_dataset' => true,
    ]);

    // First appeal submission
    $initialAppeal = Appeal::create([
        'user_id' => $this->doctor->id,
        'diagnosis_id' => $diagnosis->id,
        'diagnosis_label' => 'Acne',
        'suggested_label' => 'Eczema',
        'status' => 'rejected',
        'resolution_notes' => 'Insufficient dermoscopic features provided.',
        'resubmission_count' => 0,
    ]);

    // Resubmission by doctor
    $resubmitResponse = $this->postJson('/api/appeals', [
        'user_uuid' => $this->doctor->uuid,
        'diagnosis_uuid' => $diagnosis->uuid,
        'parent_appeal_uuid' => $initialAppeal->uuid,
        'diagnosis_label' => 'Acne',
        'suggested_label' => 'Eczema',
        'description' => 'Attached secondary polarization notes confirming atopic eczema pattern.',
    ]);

    $resubmitResponse->assertStatus(201)
        ->assertJsonPath('data.parent_appeal_id', $initialAppeal->id)
        ->assertJsonPath('data.resubmission_count', 1);

    expect($diagnosis->fresh()->label)->toBe('Eczema')
        ->and($diagnosis->fresh()->original_ai_label)->toBe('Acne');
});

test('saving clinical note with diagnosis_uuid attaches in-clinic diagnosis and allows appointment completion', function () {
    Sanctum::actingAs($this->doctor);

    $appointment = Appointment::create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient->id,
        'scheduled_at' => now()->addDay(),
        'status' => 'confirmed',
        'location' => 'Main Clinic',
    ]);

    $inClinicScan = Diagnosis::create([
        'user_uuid' => $this->doctor->uuid,
        'doctor_uuid' => $this->doctor->uuid,
        'patient_uuid' => $this->patient->uuid,
        'label' => 'Acne',
        'confidence' => 0.91,
        'scan_type' => 'in_clinic',
        'is_technical_failure' => false,
        'status' => 'completed',
    ]);

    // Save clinical note with diagnosis_uuid and status completed
    $noteResponse = $this->postJson("/api/appointments/{$appointment->uuid}/clinical-note", [
        'diagnosis_uuid' => $inClinicScan->uuid,
        'final_diagnosis' => 'Acne Vulgaris',
        'status' => 'completed',
    ]);

    $noteResponse->assertStatus(200);

    expect($appointment->fresh()->in_clinic_diagnosis_id)->toBe($inClinicScan->id)
        ->and($appointment->fresh()->status)->toBe('completed');
});
