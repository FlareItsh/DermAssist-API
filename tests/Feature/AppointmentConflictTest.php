<?php

use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\DoctorAvailability;
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

    $targetDate = now()->addDays(2);
    $startAt = $targetDate->copy()->setTime(10, 0, 0)->format('Y-m-d H:i:s');
    $endAt = $targetDate->copy()->setTime(11, 0, 0)->format('Y-m-d H:i:s');

    DoctorAvailability::create([
        'doctor_id' => $doctor->id,
        'available_date' => $targetDate->toDateString(),
        'start_time' => '08:00:00',
        'end_time' => '17:00:00',
        'is_available' => true,
    ]);

    // First appointment
    $this->actingAs($doctor)->postJson('/api/appointments/schedule-for-patient', [
        'patient_id' => $patient1->id,
        'scheduled_at' => $startAt,
        'scheduled_end_at' => $endAt,
        'location' => 'Clinic Room 1',
        'purpose' => 'Initial Assessment',
    ])->assertOk();

    // Overlapping appointment (10:30 to 11:30)
    $overlapStart = $targetDate->copy()->setTime(10, 30, 0)->format('Y-m-d H:i:s');
    $overlapEnd = $targetDate->copy()->setTime(11, 30, 0)->format('Y-m-d H:i:s');

    $response = $this->actingAs($doctor)->postJson('/api/appointments/schedule-for-patient', [
        'patient_id' => $patient2->id,
        'scheduled_at' => $overlapStart,
        'scheduled_end_at' => $overlapEnd,
        'location' => 'Clinic Room 2',
        'purpose' => 'Follow up',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('message', function ($msg) {
        return str_contains($msg, 'Conflict detected') && str_contains($msg, 'already has an appointment');
    });
});

it('allows non-overlapping appointments for the same doctor', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $patientRole = Role::where('slug', 'patient')->first();

    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);
    $patient1 = User::factory()->create(['role_id' => $patientRole->id]);
    $patient2 = User::factory()->create(['role_id' => $patientRole->id]);

    $targetDate = now()->addDays(2);
    $start1 = $targetDate->copy()->setTime(9, 0, 0)->format('Y-m-d H:i:s');
    $end1 = $targetDate->copy()->setTime(10, 0, 0)->format('Y-m-d H:i:s');

    $start2 = $targetDate->copy()->setTime(10, 0, 0)->format('Y-m-d H:i:s');
    $end2 = $targetDate->copy()->setTime(11, 0, 0)->format('Y-m-d H:i:s');

    DoctorAvailability::create([
        'doctor_id' => $doctor->id,
        'available_date' => $targetDate->toDateString(),
        'start_time' => '08:00:00',
        'end_time' => '17:00:00',
        'is_available' => true,
    ]);

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

it('prevents booking an appointment outside doctor duty hours', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $patientRole = Role::where('slug', 'patient')->first();

    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);
    $patient = User::factory()->create(['role_id' => $patientRole->id]);

    $targetDate = now()->addDays(2);

    // Doctor duty hours start at 10:00 AM and end at 5:00 PM
    DoctorAvailability::create([
        'doctor_id' => $doctor->id,
        'available_date' => $targetDate->toDateString(),
        'start_time' => '10:00:00',
        'end_time' => '17:00:00',
        'is_available' => true,
    ]);

    // Booking at 8:00 AM to 9:00 AM (before duty hours)
    $earlyStart = $targetDate->copy()->setTime(8, 0, 0)->format('Y-m-d H:i:s');
    $earlyEnd = $targetDate->copy()->setTime(9, 0, 0)->format('Y-m-d H:i:s');

    $response = $this->actingAs($doctor)->postJson('/api/appointments/schedule-for-patient', [
        'patient_id' => $patient->id,
        'scheduled_at' => $earlyStart,
        'scheduled_end_at' => $earlyEnd,
        'location' => 'Clinic Room 1',
        'purpose' => 'Early Checkup',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('message', function ($msg) {
        return str_contains($msg, 'outside the doctor\'s duty hours');
    });

    // Booking at 16:30 to 17:30 (extends beyond 17:00 duty end)
    $lateStart = $targetDate->copy()->setTime(16, 30, 0)->format('Y-m-d H:i:s');
    $lateEnd = $targetDate->copy()->setTime(17, 30, 0)->format('Y-m-d H:i:s');

    $lateResponse = $this->actingAs($doctor)->postJson('/api/appointments/schedule-for-patient', [
        'patient_id' => $patient->id,
        'scheduled_at' => $lateStart,
        'scheduled_end_at' => $lateEnd,
        'location' => 'Clinic Room 1',
        'purpose' => 'Late Checkup',
    ]);

    $lateResponse->assertStatus(422);
    $lateResponse->assertJsonPath('message', function ($msg) {
        return str_contains($msg, 'outside the doctor\'s duty hours');
    });
});

it('prevents booking an appointment during blocked hours such as lunch break', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $patientRole = Role::where('slug', 'patient')->first();

    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);
    $patient = User::factory()->create(['role_id' => $patientRole->id]);

    $targetDate = now()->addDays(2);

    // Duty hours: 09:00 - 18:00
    DoctorAvailability::create([
        'doctor_id' => $doctor->id,
        'available_date' => $targetDate->toDateString(),
        'start_time' => '09:00:00',
        'end_time' => '18:00:00',
        'is_available' => true,
    ]);

    // Blocked lunch break: 12:00 - 13:00
    DoctorAvailability::create([
        'doctor_id' => $doctor->id,
        'available_date' => $targetDate->toDateString(),
        'start_time' => '12:00:00',
        'end_time' => '13:00:00',
        'location_name' => 'Lunch Break',
        'is_available' => false,
    ]);

    // Attempting to book during lunch: 12:00 to 13:00
    $lunchStart = $targetDate->copy()->setTime(12, 0, 0)->format('Y-m-d H:i:s');
    $lunchEnd = $targetDate->copy()->setTime(13, 0, 0)->format('Y-m-d H:i:s');

    $response = $this->actingAs($doctor)->postJson('/api/appointments/schedule-for-patient', [
        'patient_id' => $patient->id,
        'scheduled_at' => $lunchStart,
        'scheduled_end_at' => $lunchEnd,
        'location' => 'Clinic Room 1',
        'purpose' => 'Lunch Consultation',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('message', function ($msg) {
        return str_contains($msg, 'overlaps with doctor\'s blocked hours') && str_contains($msg, 'Lunch Break');
    });
});

it('prevents booking on a date where doctor has no scheduled duty hours', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $patientRole = Role::where('slug', 'patient')->first();

    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);
    $patient = User::factory()->create(['role_id' => $patientRole->id]);

    $offDate = now()->addDays(4);
    $start = $offDate->copy()->setTime(10, 0, 0)->format('Y-m-d H:i:s');
    $end = $offDate->copy()->setTime(11, 0, 0)->format('Y-m-d H:i:s');

    // No availability records created for $offDate

    $response = $this->actingAs($doctor)->postJson('/api/appointments/schedule-for-patient', [
        'patient_id' => $patient->id,
        'scheduled_at' => $start,
        'scheduled_end_at' => $end,
        'location' => 'Clinic Room 1',
        'purpose' => 'Off day booking',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('message', function ($msg) {
        return str_contains($msg, 'no scheduled duty hours');
    });
});

it('returns doctor booked appointments to another patient and prevents conflicting bookings', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $patientRole = Role::where('slug', 'patient')->first();

    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);
    $patient1 = User::factory()->create(['role_id' => $patientRole->id, 'first_name' => 'John', 'last_name' => 'Patient']);
    $patient2 = User::factory()->create(['role_id' => $patientRole->id, 'first_name' => 'Maria', 'last_name' => 'Santos']);

    $targetDate = now()->addDays(1);

    DoctorAvailability::create([
        'doctor_id' => $doctor->id,
        'available_date' => $targetDate->toDateString(),
        'start_time' => '08:00:00',
        'end_time' => '17:00:00',
        'is_available' => true,
    ]);

    // Patient 1 has an appointment booked at 08:30 - 09:30
    $start1 = $targetDate->copy()->setTime(8, 30, 0)->format('Y-m-d H:i:s');
    $end1 = $targetDate->copy()->setTime(9, 30, 0)->format('Y-m-d H:i:s');

    $appt1 = Appointment::create([
        'doctor_id' => $doctor->id,
        'patient_id' => $patient1->id,
        'scheduled_at' => $start1,
        'scheduled_end_at' => $end1,
        'status' => 'scheduled',
        'location' => 'Clinic Room 1',
    ]);

    // Patient 2 queries doctor's appointments via ?doctor_id=
    $listResponse = $this->actingAs($patient2)->getJson("/api/appointments?doctor_id={$doctor->id}");
    $listResponse->assertOk();
    $listResponse->assertJsonFragment([
        'id' => $appt1->id,
        'doctor_id' => $doctor->id,
        'status' => 'scheduled',
    ]);
    // Verifies that patient 2 sees anonymized patient details for privacy
    $listData = $listResponse->json();
    expect($listData[0]['patient']['first_name'])->toBe('Booked');

    // Patient 2 has a conversation and a pending appointment
    Conversation::create([
        'doctor_id' => $doctor->id,
        'patient_id' => $patient2->id,
    ]);

    $appt2 = Appointment::create([
        'doctor_id' => $doctor->id,
        'patient_id' => $patient2->id,
        'status' => 'pending',
    ]);

    // Patient 2 tries to propose reschedule / schedule at the same 08:30 - 09:30 time slot
    $conflictResponse = $this->actingAs($patient2)->postJson("/api/appointments/{$appt2->uuid}/propose-reschedule", [
        'scheduled_at' => $start1,
        'scheduled_end_at' => $end1,
        'location' => 'Clinic Room 1',
    ]);

    $conflictResponse->assertStatus(422);
    $conflictResponse->assertJsonPath('message', function ($msg) {
        return str_contains($msg, 'already has an appointment scheduled');
    });
});
