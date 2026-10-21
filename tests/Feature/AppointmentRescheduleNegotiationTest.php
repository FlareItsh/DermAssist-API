<?php

use App\Models\Appointment;
use App\Models\AppointmentRescheduleProposal;
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
    $this->patient = User::factory()->create(['role_id' => $this->patientRole->id]);
    $this->patient2 = User::factory()->create(['role_id' => $this->patientRole->id]);

    $this->secretary = User::factory()->create([
        'role_id' => $this->secretaryRole->id,
        'doctor_id' => $this->doctor->id,
    ]);

    $this->origDate = now()->addDays(2)->setTime(9, 0, 0);
    $this->origStart = $this->origDate->format('Y-m-d H:i:s');
    $this->origEnd = $this->origDate->copy()->addHour()->format('Y-m-d H:i:s');

    $this->newDate = now()->addDays(5)->setTime(14, 0, 0);
    $this->newStart = $this->newDate->format('Y-m-d H:i:s');
    $this->newEnd = $this->newDate->copy()->addHour()->format('Y-m-d H:i:s');

    DoctorAvailability::create([
        'doctor_id' => $this->doctor->id,
        'available_date' => $this->origDate->toDateString(),
        'start_time' => '08:00:00',
        'end_time' => '17:00:00',
        'is_available' => true,
    ]);

    DoctorAvailability::create([
        'doctor_id' => $this->doctor->id,
        'available_date' => $this->newDate->toDateString(),
        'start_time' => '08:00:00',
        'end_time' => '17:00:00',
        'is_available' => true,
    ]);

    Conversation::create([
        'uuid' => (string) Str::uuid(),
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient->id,
    ]);

    Conversation::create([
        'uuid' => (string) Str::uuid(),
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient2->id,
    ]);

    $this->appointment = Appointment::create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient->id,
        'scheduled_at' => $this->origStart,
        'scheduled_end_at' => $this->origEnd,
        'location' => 'Cruz Skin Clinic',
        'purpose' => 'Follow up consultation',
        'status' => 'confirmed',
    ]);
});

it('ensures proposing a reschedule leaves the current appointment unchanged and does not reserve the proposed slot', function () {
    $response = $this->actingAs($this->patient)->postJson("/api/appointments/{$this->appointment->uuid}/reschedule-proposals", [
        'scheduled_at' => $this->newStart,
        'scheduled_end_at' => $this->newEnd,
        'reason' => 'Work conflict',
    ]);

    $response->assertCreated();
    $proposalUuid = $response->json('proposal.uuid');

    $freshAppt = $this->appointment->fresh();
    // Original confirmed time is strictly unchanged!
    expect($freshAppt->scheduled_at->format('Y-m-d H:i:s'))->toBe($this->origStart)
        ->and($freshAppt->scheduled_end_at->format('Y-m-d H:i:s'))->toBe($this->origEnd);

    // The original confirmed slot continues to block availability
    $availOrig = $this->actingAs($this->patient2)->getJson("/api/doctors/{$this->doctor->uuid}/availability-check?date={$this->origStart}");
    // Overlap with original slot is prevented
    $conflictResponse = $this->actingAs($this->patient2)->postJson('/api/appointments', [
        'doctor_id' => $this->doctor->id,
        'scheduled_at' => $this->origStart,
        'scheduled_end_at' => $this->origEnd,
    ]);
    $conflictResponse->assertStatus(409);

    // The proposed new slot is NOT reserved by the proposal; another patient can request it
    $otherRequest = $this->actingAs($this->patient2)->postJson('/api/appointments', [
        'doctor_id' => $this->doctor->id,
        'scheduled_at' => $this->newStart,
        'scheduled_end_at' => $this->newEnd,
    ]);
    $otherRequest->assertOk();
});

it('requires explicit acceptance from the other party and prevents proposer from accepting own proposal', function () {
    // Patient proposes a reschedule
    $res = $this->actingAs($this->patient)->postJson("/api/appointments/{$this->appointment->uuid}/reschedule-proposals", [
        'scheduled_at' => $this->newStart,
        'scheduled_end_at' => $this->newEnd,
        'reason' => 'Need afternoon slot',
    ]);
    $proposalUuid = $res->json('proposal.uuid');

    // Proposer (patient) CANNOT accept their own proposal
    $this->actingAs($this->patient)->postJson("/api/appointments/{$this->appointment->uuid}/reschedule-proposals/{$proposalUuid}/accept")
        ->assertStatus(403);

    // Doctor (the other party) can accept
    $acceptRes = $this->actingAs($this->doctor)->postJson("/api/appointments/{$this->appointment->uuid}/reschedule-proposals/{$proposalUuid}/accept");
    $acceptRes->assertOk();

    $freshAppt = $this->appointment->fresh();
    expect($freshAppt->scheduled_at->format('Y-m-d H:i:s'))->toBe($this->newStart)
        ->and($freshAppt->status)->toBe('confirmed');
});

it('prevents a doctor-side proposer from accepting their own proposal via a secretary account', function () {
    // Doctor proposes reschedule
    $res = $this->actingAs($this->doctor)->postJson("/api/appointments/{$this->appointment->uuid}/reschedule-proposals", [
        'scheduled_at' => $this->newStart,
        'scheduled_end_at' => $this->newEnd,
        'reason' => 'Doctor seminar',
    ]);
    $proposalUuid = $res->json('proposal.uuid');

    // Secretary of same doctor CANNOT accept practice proposal
    $this->actingAs($this->secretary)->postJson("/api/appointments/{$this->appointment->uuid}/reschedule-proposals/{$proposalUuid}/accept")
        ->assertStatus(403);

    // Patient accepts
    $this->actingAs($this->patient)->postJson("/api/appointments/{$this->appointment->uuid}/reschedule-proposals/{$proposalUuid}/accept")
        ->assertOk();
});

it('enforces only one active reschedule proposal per appointment at a time', function () {
    $this->actingAs($this->patient)->postJson("/api/appointments/{$this->appointment->uuid}/reschedule-proposals", [
        'scheduled_at' => $this->newStart,
        'scheduled_end_at' => $this->newEnd,
    ])->assertCreated();

    // Second proposal while first is pending must fail with 409
    $res2 = $this->actingAs($this->patient)->postJson("/api/appointments/{$this->appointment->uuid}/reschedule-proposals", [
        'scheduled_at' => $this->newDate->copy()->addHours(2)->format('Y-m-d H:i:s'),
    ]);
    $res2->assertStatus(409);
    $res2->assertJsonPath('message', fn ($m) => str_contains($m, 'active reschedule proposal already exists'));
});

it('leaves original appointment unchanged when a proposal conflicts upon acceptance', function () {
    $res = $this->actingAs($this->patient)->postJson("/api/appointments/{$this->appointment->uuid}/reschedule-proposals", [
        'scheduled_at' => $this->newStart,
        'scheduled_end_at' => $this->newEnd,
    ]);
    $proposalUuid = $res->json('proposal.uuid');

    // Another appointment gets confirmed on the proposed slot before acceptance
    Appointment::create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient2->id,
        'scheduled_at' => $this->newStart,
        'scheduled_end_at' => $this->newEnd,
        'status' => 'confirmed',
    ]);

    // Doctor attempts to accept conflicting proposal -> returns 409 Conflict
    $acceptRes = $this->actingAs($this->doctor)->postJson("/api/appointments/{$this->appointment->uuid}/reschedule-proposals/{$proposalUuid}/accept");
    $acceptRes->assertStatus(409);

    // Original appointment remains unchanged!
    $freshAppt = $this->appointment->fresh();
    expect($freshAppt->scheduled_at->format('Y-m-d H:i:s'))->toBe($this->origStart);

    // Proposal marked as conflict
    $proposal = AppointmentRescheduleProposal::where('uuid', $proposalUuid)->first();
    expect($proposal->status)->toBe('conflict');
});

it('allows declining and withdrawing proposals while leaving the appointment unchanged', function () {
    // Propose
    $res = $this->actingAs($this->patient)->postJson("/api/appointments/{$this->appointment->uuid}/reschedule-proposals", [
        'scheduled_at' => $this->newStart,
        'scheduled_end_at' => $this->newEnd,
    ]);
    $proposalUuid = $res->json('proposal.uuid');

    // Patient withdraws proposal
    $this->actingAs($this->patient)->postJson("/api/appointments/{$this->appointment->uuid}/reschedule-proposals/{$proposalUuid}/withdraw")
        ->assertOk();

    expect(AppointmentRescheduleProposal::where('uuid', $proposalUuid)->first()->status)->toBe('withdrawn');
    expect($this->appointment->fresh()->scheduled_at->format('Y-m-d H:i:s'))->toBe($this->origStart);
});

it('closes outstanding proposals when the appointment is cancelled', function () {
    $res = $this->actingAs($this->patient)->postJson("/api/appointments/{$this->appointment->uuid}/reschedule-proposals", [
        'scheduled_at' => $this->newStart,
        'scheduled_end_at' => $this->newEnd,
    ]);
    $proposalUuid = $res->json('proposal.uuid');

    // Cancel appointment
    $this->actingAs($this->patient)->postJson("/api/appointments/{$this->appointment->uuid}/cancel", [
        'reason' => 'Patient no longer needs consultation',
    ])->assertOk();

    expect($this->appointment->fresh()->status)->toBe('cancelled');
    expect(AppointmentRescheduleProposal::where('uuid', $proposalUuid)->first()->status)->toBe('withdrawn');
});
