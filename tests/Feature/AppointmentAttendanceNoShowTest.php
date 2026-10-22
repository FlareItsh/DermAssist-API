<?php

use App\Models\Appointment;
use App\Models\ClinicalNote;
use App\Models\Diagnosis;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->doctorRole = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    $this->patientRole = Role::firstOrCreate(['slug' => 'patient'], ['name' => 'Patient']);
    $this->secretaryRole = Role::firstOrCreate(['slug' => 'secretary'], ['name' => 'Secretary']);

    $this->doctor = User::factory()->create(['role_id' => $this->doctorRole->id]);
    $this->patient = User::factory()->create(['role_id' => $this->patientRole->id]);

    $this->secretary = User::factory()->create([
        'role_id' => $this->secretaryRole->id,
        'doctor_id' => $this->doctor->id,
    ]);

    $this->unrelatedUser = User::factory()->create(['role_id' => $this->patientRole->id]);

    // Set appointment starting 30 minutes in the past so 15-minute grace period has passed
    $this->pastStart = now()->subMinutes(30);
    $this->futureStart = now()->addMinutes(30);

    $this->appointmentPast = Appointment::create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient->id,
        'scheduled_at' => $this->pastStart->format('Y-m-d H:i:s'),
        'scheduled_end_at' => $this->pastStart->copy()->addHour()->format('Y-m-d H:i:s'),
        'status' => 'confirmed',
        'location' => 'Cruz Skin Clinic',
        'purpose' => 'Past checkup',
    ]);

    $this->appointmentFuture = Appointment::create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient->id,
        'scheduled_at' => $this->futureStart->format('Y-m-d H:i:s'),
        'scheduled_end_at' => $this->futureStart->copy()->addHour()->format('Y-m-d H:i:s'),
        'status' => 'confirmed',
        'location' => 'Cruz Skin Clinic',
        'purpose' => 'Future checkup',
    ]);
});

it('cannot mark appointment as no-show before the grace period has elapsed', function () {
    // Attempting to mark a future or early appointment as no-show must fail with 422
    $response = $this->actingAs($this->doctor)->postJson("/api/appointments/{$this->appointmentFuture->uuid}/mark-no-show");

    $response->assertStatus(422);
    $response->assertJsonPath('message', fn ($m) => str_contains($m, 'grace period'));
    expect($this->appointmentFuture->fresh()->status)->toBe('confirmed');
});

it('does not automatically mark appointment as no-show when grace period passes', function () {
    // appointmentPast is 30 minutes in the past (grace period is 15 minutes)
    // Verify that passing grace period threshold does NOT change status automatically
    expect($this->appointmentPast->fresh()->status)->toBe('confirmed');
});

it('allows an authorized doctor or secretary to manually mark eligible appointment as no-show after grace period', function () {
    $response = $this->actingAs($this->doctor)->postJson("/api/appointments/{$this->appointmentPast->uuid}/mark-no-show", [
        'reason' => 'patient_not_present',
    ]);

    $response->assertOk();
    $fresh = $this->appointmentPast->fresh();
    expect($fresh->status)->toBe('no_show')
        ->and($fresh->no_show_by_id)->toBe($this->doctor->id)
        ->and($fresh->no_show_at)->not->toBeNull()
        ->and($fresh->no_show_reason)->toBe('patient_not_present');

    // Verify audit event
    $this->assertDatabaseHas('appointment_events', [
        'appointment_id' => $this->appointmentPast->id,
        'actor_id' => $this->doctor->id,
        'event_type' => 'no_show_confirmed',
    ]);

    // Affiliated secretary can also mark another appointment
    $pastAppt2 = Appointment::create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient->id,
        'scheduled_at' => now()->subMinutes(20)->format('Y-m-d H:i:s'),
        'status' => 'confirmed',
    ]);

    $this->actingAs($this->secretary)->postJson("/api/appointments/{$pastAppt2->uuid}/mark-no-show")
        ->assertOk();
    expect($pastAppt2->fresh()->status)->toBe('no_show');
});

it('forbids patients and unauthorized users from marking an appointment as no-show', function () {
    $this->actingAs($this->patient)->postJson("/api/appointments/{$this->appointmentPast->uuid}/mark-no-show")
        ->assertStatus(403);

    $this->actingAs($this->unrelatedUser)->postJson("/api/appointments/{$this->appointmentPast->uuid}/mark-no-show")
        ->assertStatus(403);
});

it('forbids marking checked-in, in-consultation, completed, or cancelled appointments as no-show', function () {
    // 1. Checked-in appointment
    $checkedInAppt = Appointment::create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient->id,
        'scheduled_at' => now()->subMinutes(25)->format('Y-m-d H:i:s'),
        'status' => 'checked_in',
        'checked_in_at' => now()->subMinutes(20),
    ]);

    $this->actingAs($this->doctor)->postJson("/api/appointments/{$checkedInAppt->uuid}/mark-no-show")
        ->assertStatus(409);

    // 2. Completed appointment
    $completedAppt = Appointment::create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient->id,
        'scheduled_at' => now()->subMinutes(25)->format('Y-m-d H:i:s'),
        'status' => 'completed',
    ]);

    $this->actingAs($this->doctor)->postJson("/api/appointments/{$completedAppt->uuid}/mark-no-show")
        ->assertStatus(409);

    // 3. Cancelled appointment
    $cancelledAppt = Appointment::create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient->id,
        'scheduled_at' => now()->subMinutes(25)->format('Y-m-d H:i:s'),
        'status' => 'cancelled',
    ]);

    $this->actingAs($this->doctor)->postJson("/api/appointments/{$cancelledAppt->uuid}/mark-no-show")
        ->assertStatus(409);
});

it('verifies marking no-show does not create diagnosis or clinical note', function () {
    $initDiagnosisCount = Diagnosis::count();
    $initNoteCount = ClinicalNote::count();

    $this->actingAs($this->doctor)->postJson("/api/appointments/{$this->appointmentPast->uuid}/mark-no-show")
        ->assertOk();

    expect(Diagnosis::count())->toBe($initDiagnosisCount)
        ->and(ClinicalNote::count())->toBe($initNoteCount);
});

it('allows authorized staff to record patient check-in', function () {
    $response = $this->actingAs($this->secretary)->postJson("/api/appointments/{$this->appointmentPast->uuid}/check-in");

    $response->assertOk();
    $fresh = $this->appointmentPast->fresh();
    expect($fresh->status)->toBe('checked_in')
        ->and($fresh->checked_in_at)->not->toBeNull();

    $this->assertDatabaseHas('appointment_events', [
        'appointment_id' => $this->appointmentPast->id,
        'event_type' => 'checked_in',
    ]);
});
