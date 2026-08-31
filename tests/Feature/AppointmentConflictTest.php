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
});

it('prevents overlapping appointments for the same doctor', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $patientRole = Role::where('slug', 'patient')->first();

    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);
    $patient1 = User::factory()->create(['role_id' => $patientRole->id]);
    $patient2 = User::factory()->create(['role_id' => $patientRole->id]);

    $startAt = now()->addDays(2)->setTime(10, 0, 0)->format('Y-m-d H:i:s');
    $endAt = now()->addDays(2)->setTime(11, 0, 0)->format('Y-m-d H:i:s');

    // First appointment
    $this->actingAs($doctor)->postJson('/api/appointments/schedule-for-patient', [
        'patient_id' => $patient1->id,
        'scheduled_at' => $startAt,
        'scheduled_end_at' => $endAt,
        'location' => 'Clinic Room 1',
        'purpose' => 'Initial Assessment',
    ])->assertOk();

    // Overlapping appointment (10:30 to 11:30)
    $overlapStart = now()->addDays(2)->setTime(10, 30, 0)->format('Y-m-d H:i:s');
    $overlapEnd = now()->addDays(2)->setTime(11, 30, 0)->format('Y-m-d H:i:s');

    $response = $this->actingAs($doctor)->postJson('/api/appointments/schedule-for-patient', [
        'patient_id' => $patient2->id,
        'scheduled_at' => $overlapStart,
        'scheduled_end_at' => $overlapEnd,
        'location' => 'Clinic Room 2',
        'purpose' => 'Follow up',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('message', function ($msg) {
        return str_contains($msg, 'Conflict detected');
    });
});

it('allows non-overlapping appointments for the same doctor', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $patientRole = Role::where('slug', 'patient')->first();

    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);
    $patient1 = User::factory()->create(['role_id' => $patientRole->id]);
    $patient2 = User::factory()->create(['role_id' => $patientRole->id]);

    $start1 = now()->addDays(2)->setTime(9, 0, 0)->format('Y-m-d H:i:s');
    $end1 = now()->addDays(2)->setTime(10, 0, 0)->format('Y-m-d H:i:s');

    $start2 = now()->addDays(2)->setTime(10, 0, 0)->format('Y-m-d H:i:s');
    $end2 = now()->addDays(2)->setTime(11, 0, 0)->format('Y-m-d H:i:s');

    $this->actingAs($doctor)->postJson('/api/appointments/schedule-for-patient', [
        'patient_id' => $patient1->id,
        'scheduled_at' => $start1,
        'scheduled_end_at' => $end1,
        'location' => 'Room A',
        'purpose' => 'Slot 1',
    ])->assertOk();

    $this->actingAs($doctor)->postJson('/api/appointments/schedule-for-patient', [
        'patient_id' => $patient2->id,
        'scheduled_at' => $start2,
        'scheduled_end_at' => $end2,
        'location' => 'Room B',
        'purpose' => 'Slot 2',
    ])->assertOk();
});
