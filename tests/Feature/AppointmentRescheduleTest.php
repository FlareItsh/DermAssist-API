<?php

use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\DoctorAvailability;
use App\Models\Message;
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

    $targetDay5 = now()->addDays(5);
    $proposedDate = $targetDay5->copy()->setTime(10, 0, 0)->format('Y-m-d H:i:s');

    DoctorAvailability::create([
        'doctor_id' => $doctor->id,
        'available_date' => $targetDay5->toDateString(),
        'start_time' => '08:00:00',
        'end_time' => '17:00:00',
        'is_available' => true,
    ]);

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

    $targetDay5 = now()->addDays(5);
    $scheduledAt = $targetDay5->copy()->setTime(10, 0, 0)->format('Y-m-d H:i:s');

    DoctorAvailability::create([
        'doctor_id' => $doctor->id,
        'available_date' => $targetDay5->toDateString(),
        'start_time' => '08:00:00',
        'end_time' => '17:00:00',
        'is_available' => true,
    ]);

    $appointment = Appointment::create([
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'scheduled_at' => $scheduledAt,
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

it('allows patient to request a reschedule with preferred date and time', function () {
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
        'scheduled_at' => now()->addDays(1)->setTime(10, 0, 0)->format('Y-m-d H:i:s'),
        'location' => 'Main Clinic',
        'status' => 'scheduled',
    ]);

    $targetDay3 = now()->addDays(3);
    $targetDate = $targetDay3->toDateString();
    $targetTime = '14:00:00';

    DoctorAvailability::create([
        'doctor_id' => $doctor->id,
        'available_date' => $targetDate,
        'start_time' => '08:00:00',
        'end_time' => '17:00:00',
        'is_available' => true,
    ]);

    $response = $this->actingAs($patient)->putJson("/api/appointments/{$appointment->uuid}", [
        'status' => 'reschedule_requested',
        'requested_reschedule_date' => $targetDate,
        'requested_reschedule_time' => '14:00',
    ]);

    $response->assertOk();
    $response->assertJsonPath('status', 'reschedule_requested');

    $this->assertDatabaseHas('appointments', [
        'id' => $appointment->id,
        'status' => 'reschedule_requested',
        'requested_reschedule_date' => $targetDate,
        'requested_reschedule_time' => '14:00',
    ]);

    $this->assertDatabaseHas('messages', [
        'sender_id' => $patient->id,
    ]);

    $message = Message::latest()->first();
    expect($message->message)->toContain('[APPOINTMENT_RESCHEDULE_REQUESTED:')
        ->and($message->message)->toContain('Preferred:');
});

it('allows doctor to accept a reschedule request and updates scheduled_at to requested date and time', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $patientRole = Role::where('slug', 'patient')->first();

    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);
    $patient = User::factory()->create(['role_id' => $patientRole->id]);

    Conversation::create([
        'uuid' => (string) Str::uuid(),
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
    ]);

    $targetDay3 = now()->addDays(3);
    $targetDate = $targetDay3->toDateString();
    $targetTime = '14:00:00';

    DoctorAvailability::create([
        'doctor_id' => $doctor->id,
        'available_date' => $targetDate,
        'start_time' => '08:00:00',
        'end_time' => '17:00:00',
        'is_available' => true,
    ]);

    $appointment = Appointment::create([
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'scheduled_at' => now()->addDays(1)->setTime(10, 0, 0)->format('Y-m-d H:i:s'),
        'location' => 'Main Clinic',
        'status' => 'reschedule_requested',
        'requested_reschedule_date' => $targetDate,
        'requested_reschedule_time' => $targetTime,
    ]);

    $response = $this->actingAs($doctor)->postJson("/api/appointments/{$appointment->uuid}/accept-reschedule", []);

    $response->assertOk();
    $response->assertJsonPath('appointment.status', 'scheduled');

    $expectedDateTime = "{$targetDate} {$targetTime}";
    $fresh = $appointment->fresh();
    expect($fresh->status)->toBe('scheduled')
        ->and($fresh->scheduled_at->format('Y-m-d H:i:s'))->toBe($expectedDateTime)
        ->and($fresh->requested_reschedule_date)->toBeNull()
        ->and($fresh->requested_reschedule_time)->toBeNull();

    $message = Message::latest()->first();
    expect($message->message)->toContain('[APPOINTMENT_RESCHEDULE_ACCEPTED:')
        ->and($message->message)->toContain('The reschedule request has been accepted');
});

it('clears requested reschedule date and time when doctor proposes an alternative schedule', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $patientRole = Role::where('slug', 'patient')->first();

    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);
    $patient = User::factory()->create(['role_id' => $patientRole->id]);

    Conversation::create([
        'uuid' => (string) Str::uuid(),
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
    ]);

    $targetDay4 = now()->addDays(4);
    $proposedDate = $targetDay4->copy()->setTime(11, 0, 0)->format('Y-m-d H:i:s');

    DoctorAvailability::create([
        'doctor_id' => $doctor->id,
        'available_date' => $targetDay4->toDateString(),
        'start_time' => '08:00:00',
        'end_time' => '17:00:00',
        'is_available' => true,
    ]);

    $appointment = Appointment::create([
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'scheduled_at' => now()->addDays(1)->setTime(10, 0, 0)->format('Y-m-d H:i:s'),
        'location' => 'Main Clinic',
        'status' => 'reschedule_requested',
        'requested_reschedule_date' => now()->addDays(2)->toDateString(),
        'requested_reschedule_time' => '15:00:00',
    ]);

    $response = $this->actingAs($doctor)->postJson("/api/appointments/{$appointment->uuid}/propose-reschedule", [
        'scheduled_at' => $proposedDate,
        'location' => 'Alternative Clinic',
    ]);

    $response->assertOk();

    $fresh = $appointment->fresh();
    expect($fresh->status)->toBe('reschedule_proposed')
        ->and($fresh->requested_reschedule_date)->toBeNull()
        ->and($fresh->requested_reschedule_time)->toBeNull();
});
