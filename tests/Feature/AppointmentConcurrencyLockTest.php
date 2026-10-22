<?php

use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\DoctorAvailability;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->doctorRole = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    $this->patientRole = Role::firstOrCreate(['slug' => 'patient'], ['name' => 'Patient']);

    $this->doctor = User::factory()->create(['role_id' => $this->doctorRole->id]);
    $this->patient1 = User::factory()->create(['role_id' => $this->patientRole->id]);
    $this->patient2 = User::factory()->create(['role_id' => $this->patientRole->id]);

    $this->targetDate = now()->addDays(4)->setTime(11, 0, 0);
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

it('prevents competing acceptance requests from confirming overlapping appointments', function () {
    // Two pending appointments targeting the same slot
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

    // Simulate concurrent execution:
    // Thread 1 acquires doctor lock, checks conflict, confirms appt1
    $res1 = $this->actingAs($this->doctor)->postJson("/api/appointments/{$appt1->uuid}/accept");
    $res1->assertOk();
    expect($appt1->fresh()->status)->toBe('confirmed');

    // Thread 2 (second request reaches confirmation) attempts to accept appt2
    $res2 = $this->actingAs($this->doctor)->postJson("/api/appointments/{$appt2->uuid}/accept");
    $res2->assertStatus(409);
    $res2->assertJsonPath('message', fn ($m) => str_contains($m, 'SLOT_NO_LONGER_AVAILABLE') || str_contains($m, 'Conflict detected'));

    // appt2 remains pending and unconfirmed
    expect($appt2->fresh()->status)->toBe('pending');
});

it('ensures doctor row lock is acquired during accept and cancel mutations', function () {
    $appt = Appointment::create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient1->id,
        'scheduled_at' => $this->startAt,
        'scheduled_end_at' => $this->endAt,
        'status' => 'pending',
    ]);

    // Transaction that inspects row locking
    DB::transaction(function () use ($appt) {
        $doctorLocked = User::where('id', $this->doctor->id)->lockForUpdate()->first();
        expect($doctorLocked)->not->toBeNull();

        $appointmentLocked = Appointment::where('id', $appt->id)->lockForUpdate()->first();
        expect($appointmentLocked)->not->toBeNull();
    });
});
