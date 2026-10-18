<?php

use App\Models\DoctorAvailability;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Seed the required roles using the model so the uuid boot hook fires.
    Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    Role::firstOrCreate(['slug' => 'patient'], ['name' => 'Patient']);
    Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
});

it('allows a doctor to schedule a new appointment for a patient', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $patientRole = Role::where('slug', 'patient')->first();

    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);
    $patient = User::factory()->create(['role_id' => $patientRole->id]);

    $targetDate = now()->addDays(3);
    $scheduledAt = $targetDate->copy()->setTime(10, 0, 0)->format('Y-m-d H:i:s');

    DoctorAvailability::create([
        'doctor_id' => $doctor->id,
        'available_date' => $targetDate->toDateString(),
        'start_time' => '08:00:00',
        'end_time' => '17:00:00',
        'is_available' => true,
    ]);

    $response = $this->actingAs($doctor)->postJson('/api/appointments/schedule-for-patient', [
        'patient_id' => $patient->id,
        'scheduled_at' => $scheduledAt,
        'location' => 'SkinCare Clinic, Rm 302',
        'purpose' => 'Follow-up on eczema treatment',
    ]);

    $response->assertOk();
    $response->assertJsonPath('message', 'Appointment scheduled successfully.');

    $this->assertDatabaseHas('appointments', [
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'location' => 'SkinCare Clinic, Rm 302',
        'purpose' => 'Follow-up on eczema treatment',
        'status' => 'scheduled',
    ]);
});

it('forbids a patient from scheduling an appointment for another patient', function () {
    $patientRole = Role::where('slug', 'patient')->first();

    $patient = User::factory()->create(['role_id' => $patientRole->id]);
    $otherPatient = User::factory()->create(['role_id' => $patientRole->id]);

    $response = $this->actingAs($patient)->postJson('/api/appointments/schedule-for-patient', [
        'patient_id' => $otherPatient->id,
        'scheduled_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
        'location' => 'Clinic A',
        'purpose' => 'Test',
    ]);

    $response->assertForbidden();
});

it('validates required fields when scheduling for a patient', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);

    $response = $this->actingAs($doctor)->postJson('/api/appointments/schedule-for-patient', []);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['patient_id', 'scheduled_at', 'location', 'purpose']);
});

it('resolves patient correctly when uuid is passed in patient_id or patient_uuid', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $patientRole = Role::where('slug', 'patient')->first();

    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);
    $patient = User::factory()->create(['role_id' => $patientRole->id]);

    $targetDate = now()->addDays(3);
    $scheduledAt = $targetDate->copy()->setTime(14, 0, 0)->format('Y-m-d H:i:s');

    DoctorAvailability::create([
        'doctor_id' => $doctor->id,
        'available_date' => $targetDate->toDateString(),
        'start_time' => '08:00:00',
        'end_time' => '17:00:00',
        'is_available' => true,
    ]);

    // Test case 1: UUID passed as string in patient_id
    $response1 = $this->actingAs($doctor)->postJson('/api/appointments/schedule-for-patient', [
        'patient_id' => $patient->uuid,
        'scheduled_at' => $scheduledAt,
        'location' => 'Clinic Room 101',
        'purpose' => 'Consultation with UUID in patient_id',
    ]);

    $response1->assertOk();
    $this->assertDatabaseHas('appointments', [
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'purpose' => 'Consultation with UUID in patient_id',
    ]);

    // Test case 2: UUID passed in patient_uuid
    $scheduledAt2 = $targetDate->copy()->setTime(15, 0, 0)->format('Y-m-d H:i:s');
    $response2 = $this->actingAs($doctor)->postJson('/api/appointments/schedule-for-patient', [
        'patient_uuid' => $patient->uuid,
        'scheduled_at' => $scheduledAt2,
        'location' => 'Clinic Room 102',
        'purpose' => 'Consultation with patient_uuid',
    ]);

    $response2->assertOk();
    $this->assertDatabaseHas('appointments', [
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'purpose' => 'Consultation with patient_uuid',
    ]);
});
