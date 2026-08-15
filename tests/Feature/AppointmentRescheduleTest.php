<?php

use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    Role::firstOrCreate(['slug' => 'patient'], ['name' => 'Patient']);
    Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
});

it('allows proposing a reschedule for an appointment', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $patientRole = Role::where('slug', 'patient')->first();

    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);
    $patient = User::factory()->create(['role_id' => $patientRole->id]);

    Conversation::create([
        'uuid' => (string) Str::uuid(),
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
    ]);

    $appointment = Appointment::create([
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'scheduled_at' => now()->addDays(1)->format('Y-m-d H:i:s'),
        'location' => 'Original Clinic',
        'status' => 'scheduled',
    ]);

    $proposedDate = now()->addDays(5)->format('Y-m-d H:i:s');

    $response = $this->actingAs($doctor)->postJson("/api/appointments/{$appointment->uuid}/propose-reschedule", [
        'scheduled_at' => $proposedDate,
        'location' => 'New Clinic Room 101',
    ]);

    $response->assertOk();
    $response->assertJsonPath('appointment.status', 'reschedule_proposed');

    $this->assertDatabaseHas('appointments', [
        'id' => $appointment->id,
        'status' => 'reschedule_proposed',
        'location' => 'New Clinic Room 101',
    ]);
});

it('allows accepting a proposed reschedule', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $patientRole = Role::where('slug', 'patient')->first();

    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);
    $patient = User::factory()->create(['role_id' => $patientRole->id]);

    Conversation::create([
        'uuid' => (string) Str::uuid(),
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
    ]);

    $appointment = Appointment::create([
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'scheduled_at' => now()->addDays(5)->format('Y-m-d H:i:s'),
        'location' => 'New Clinic Room 101',
        'status' => 'reschedule_proposed',
    ]);

    $response = $this->actingAs($patient)->postJson("/api/appointments/{$appointment->uuid}/accept-reschedule", []);

    $response->assertOk();
    $response->assertJsonPath('appointment.status', 'scheduled');

    $this->assertDatabaseHas('appointments', [
        'id' => $appointment->id,
        'status' => 'scheduled',
    ]);
});
