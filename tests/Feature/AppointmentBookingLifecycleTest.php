<?php

use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\DoctorAvailability;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->doctorRole = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    $this->patientRole = Role::firstOrCreate(['slug' => 'patient'], ['name' => 'Patient']);
    $this->secretaryRole = Role::firstOrCreate(['slug' => 'secretary'], ['name' => 'Secretary']);

    $this->doctor = User::factory()->create(['role_id' => $this->doctorRole->id]);
    $this->patient1 = User::factory()->create(['role_id' => $this->patientRole->id]);
    $this->patient2 = User::factory()->create(['role_id' => $this->patientRole->id]);

    $this->secretary = User::factory()->create([
        'role_id' => $this->secretaryRole->id,
        'doctor_id' => $this->doctor->id,
    ]);

    $this->unrelatedSecretary = User::factory()->create([
        'role_id' => $this->secretaryRole->id,
        'doctor_id' => User::factory()->create(['role_id' => $this->doctorRole->id])->id,
    ]);

    $this->targetDate = now()->addDays(3)->setTime(10, 0, 0);
    $this->startAt = $this->targetDate->format('Y-m-d H:i:s');
    $this->endAt = $this->targetDate->copy()->addHour()->format('Y-m-d H:i:s');

    DoctorAvailability::create([
        'doctor_id' => $this->doctor->id,
        'available_date' => $this->targetDate->toDateString(),
        'start_time' => '08:00:00',
        'end_time' => '17:00:00',
        'is_available' => true,
    ]);

    Conversation::create([
        'uuid' => (string) Str::uuid(),
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient1->id,
    ]);

    Conversation::create([
        'uuid' => (string) Str::uuid(),
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient2->id,
    ]);
});

it('allows a patient to create a pending request without reserving a slot', function () {
    $response = $this->actingAs($this->patient1)->postJson('/api/appointments', [
        'doctor_id' => $this->doctor->id,
        'scheduled_at' => $this->startAt,
        'scheduled_end_at' => $this->endAt,
        'purpose' => 'Skin consultation',
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('appointments', [
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient1->id,
        'status' => 'pending',
    ]);

    // Slot is NOT reserved: querying doctor availability still shows available
    $availRes = $this->actingAs($this->patient1)->getJson("/api/doctors/{$this->doctor->uuid}/availability-check?date={$this->startAt}");
    $availRes->assertOk();
    expect($availRes->json('is_available'))->toBeTrue();
});

it('allows two pending requests for the same otherwise-free slot to coexist', function () {
    // Patient 1 creates pending request
    $this->actingAs($this->patient1)->postJson('/api/appointments', [
        'doctor_id' => $this->doctor->id,
        'scheduled_at' => $this->startAt,
        'scheduled_end_at' => $this->endAt,
        'purpose' => 'Request from patient 1',
    ])->assertOk();

    // Patient 2 creates competing pending request for the EXACT same slot
    $response2 = $this->actingAs($this->patient2)->postJson('/api/appointments', [
        'doctor_id' => $this->doctor->id,
        'scheduled_at' => $this->startAt,
        'scheduled_end_at' => $this->endAt,
        'purpose' => 'Request from patient 2',
    ]);

    $response2->assertOk();
    expect(Appointment::where('doctor_id', $this->doctor->id)->where('status', 'pending')->count())->toBe(2);
});

it('returns conflict response when booking targeting already occupied confirmed interval', function () {
    // Create already confirmed appointment
    Appointment::create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient1->id,
        'scheduled_at' => $this->startAt,
        'scheduled_end_at' => $this->endAt,
        'status' => 'confirmed',
        'location' => 'Main Clinic',
        'purpose' => 'Existing confirmed',
    ]);

    // Patient 2 attempts to request the occupied slot
    $response = $this->actingAs($this->patient2)->postJson('/api/appointments', [
        'doctor_id' => $this->doctor->id,
        'scheduled_at' => $this->startAt,
        'scheduled_end_at' => $this->endAt,
        'purpose' => 'Attempt booking occupied slot',
    ]);

    $response->assertStatus(409);
    $response->assertJsonPath('message', fn ($m) => str_contains($m, 'Conflict detected') || str_contains($m, 'already has an appointment'));
});

it('allows an authorized doctor to accept a valid pending request', function () {
    $appt = Appointment::create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient1->id,
        'scheduled_at' => $this->startAt,
        'scheduled_end_at' => $this->endAt,
        'status' => 'pending',
        'purpose' => 'Pending Request',
    ]);

    $response = $this->actingAs($this->doctor)->postJson("/api/appointments/{$appt->uuid}/accept");
    $response->assertOk();

    $fresh = $appt->fresh();
    expect($fresh->status)->toBe('confirmed')
        ->and($fresh->accepted_by_id)->toBe($this->doctor->id)
        ->and($fresh->confirmed_at)->not->toBeNull();

    $this->assertDatabaseHas('appointment_events', [
        'appointment_id' => $appt->id,
        'event_type' => 'booking_confirmed',
    ]);
});

it('allows an affiliated secretary to accept or reject an authorized doctor request', function () {
    $appt1 = Appointment::create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient1->id,
        'scheduled_at' => $this->startAt,
        'scheduled_end_at' => $this->endAt,
        'status' => 'pending',
        'purpose' => 'Pending Request 1',
    ]);

    // Affiliated secretary accepts
    $resAccept = $this->actingAs($this->secretary)->postJson("/api/appointments/{$appt1->uuid}/accept");
    $resAccept->assertOk();
    expect($appt1->fresh()->status)->toBe('confirmed');

    $appt2 = Appointment::create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient2->id,
        'scheduled_at' => $this->targetDate->copy()->addHours(2)->format('Y-m-d H:i:s'),
        'scheduled_end_at' => $this->targetDate->copy()->addHours(3)->format('Y-m-d H:i:s'),
        'status' => 'pending',
        'purpose' => 'Pending Request 2',
    ]);

    // Affiliated secretary rejects
    $resReject = $this->actingAs($this->secretary)->postJson("/api/appointments/{$appt2->uuid}/reject", [
        'reason' => 'Doctor unavailable during this hour.',
    ]);
    $resReject->assertOk();
    expect($appt2->fresh()->status)->toBe('rejected');
});

it('forbids an unrelated secretary from modifying that doctor bookings', function () {
    $appt = Appointment::create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient1->id,
        'scheduled_at' => $this->startAt,
        'scheduled_end_at' => $this->endAt,
        'status' => 'pending',
    ]);

    $this->actingAs($this->unrelatedSecretary)->postJson("/api/appointments/{$appt->uuid}/accept")
        ->assertStatus(403);

    $this->actingAs($this->unrelatedSecretary)->postJson("/api/appointments/{$appt->uuid}/reject")
        ->assertStatus(403);
});

it('forbids a patient from accepting their own request or acting on another patient booking', function () {
    $appt = Appointment::create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient1->id,
        'scheduled_at' => $this->startAt,
        'scheduled_end_at' => $this->endAt,
        'status' => 'pending',
    ]);

    // Patient cannot accept their own pending booking
    $this->actingAs($this->patient1)->postJson("/api/appointments/{$appt->uuid}/accept")
        ->assertStatus(403);

    // Patient 2 cannot cancel or modify patient 1 booking
    $this->actingAs($this->patient2)->postJson("/api/appointments/{$appt->uuid}/cancel")
        ->assertStatus(403);
});

it('prevents confirming a pending request if the slot has already been taken by another confirmed booking', function () {
    // Two competing pending requests for same slot
    $appt1 = Appointment::create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient1->id,
        'scheduled_at' => $this->startAt,
        'scheduled_end_at' => $this->endAt,
        'status' => 'pending',
    ]);

    $appt2 = Appointment::create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient2->id,
        'scheduled_at' => $this->startAt,
        'scheduled_end_at' => $this->endAt,
        'status' => 'pending',
    ]);

    // First request is accepted
    $this->actingAs($this->doctor)->postJson("/api/appointments/{$appt1->uuid}/accept")->assertOk();
    expect($appt1->fresh()->status)->toBe('confirmed');

    // Second request attempts to confirm for same slot -> MUST fail with 409
    $response2 = $this->actingAs($this->doctor)->postJson("/api/appointments/{$appt2->uuid}/accept");
    $response2->assertStatus(409);
    expect($appt2->fresh()->status)->toBe('pending'); // Leaves second request pending
});

it('supports idempotency keys on booking creation without duplicating records', function () {
    $idempotencyKey = 'req_idemp_'.Str::random(10);

    $res1 = $this->actingAs($this->patient1)->postJson('/api/appointments', [
        'doctor_id' => $this->doctor->id,
        'scheduled_at' => $this->startAt,
        'scheduled_end_at' => $this->endAt,
        'purpose' => 'First attempt',
        'idempotency_key' => $idempotencyKey,
    ])->assertOk();

    $res2 = $this->actingAs($this->patient1)->postJson('/api/appointments', [
        'doctor_id' => $this->doctor->id,
        'scheduled_at' => $this->startAt,
        'scheduled_end_at' => $this->endAt,
        'purpose' => 'Retry attempt',
        'idempotency_key' => $idempotencyKey,
    ])->assertOk();

    expect(Appointment::where('patient_id', $this->patient1->id)->count())->toBe(1);
    expect($res1->json('appointment.uuid'))->toBe($res2->json('appointment.uuid'));
});
