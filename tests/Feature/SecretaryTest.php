<?php

use App\Models\Appointment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    Role::firstOrCreate(['slug' => 'patient'], ['name' => 'Patient']);
    Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    Role::firstOrCreate(['slug' => 'secretary'], ['name' => 'Secretary']);
});

it('allows a secretary to schedule an appointment for a patient on behalf of their doctor', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $secretaryRole = Role::where('slug', 'secretary')->first();
    $patientRole = Role::where('slug', 'patient')->first();

    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);
    $secretary = User::factory()->create([
        'role_id' => $secretaryRole->id,
        'doctor_id' => $doctor->id,
    ]);
    $patient = User::factory()->create(['role_id' => $patientRole->id]);

    $scheduledAt = now()->addDays(2)->format('Y-m-d H:i:s');

    $response = $this->actingAs($secretary)->postJson('/api/appointments/schedule-for-patient', [
        'patient_id' => $patient->id,
        'scheduled_at' => $scheduledAt,
        'location' => 'Room 101',
        'purpose' => 'Consultation by Secretary',
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('appointments', [
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'location' => 'Room 101',
        'purpose' => 'Consultation by Secretary',
        'status' => 'scheduled',
    ]);
});

it('allows a secretary to list appointments belonging to their doctor', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $secretaryRole = Role::where('slug', 'secretary')->first();
    $patientRole = Role::where('slug', 'patient')->first();

    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);
    $secretary = User::factory()->create([
        'role_id' => $secretaryRole->id,
        'doctor_id' => $doctor->id,
    ]);
    $patient = User::factory()->create(['role_id' => $patientRole->id]);

    Appointment::create([
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'scheduled_at' => now()->addDays(1),
        'location' => 'Main Clinic',
        'status' => 'scheduled',
    ]);

    $response = $this->actingAs($secretary)->getJson('/api/appointments');

    $response->assertOk();
    $response->assertJsonCount(1);
});

it('allows a secretary to manage doctor availabilities', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $secretaryRole = Role::where('slug', 'secretary')->first();

    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);
    $secretary = User::factory()->create([
        'role_id' => $secretaryRole->id,
        'doctor_id' => $doctor->id,
    ]);

    $response = $this->actingAs($secretary)->postJson("/api/doctors/{$doctor->uuid}/availabilities", [
        'available_date' => now()->addDays(5)->format('Y-m-d'),
        'start_time' => '09:00',
        'end_time' => '17:00',
        'is_available' => true,
    ]);

    $response->assertCreated();

    $this->assertDatabaseHas('doctor_availabilities', [
        'doctor_id' => $doctor->id,
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);
});
