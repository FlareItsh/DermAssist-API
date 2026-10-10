<?php

use App\Events\AppointmentUpdated;
use App\Models\Appointment;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('updating appointment status dispatches AppointmentUpdated broadcast event', function () {
    Event::fake([AppointmentUpdated::class]);

    $doctorRole = Role::factory()->create(['slug' => 'doctor']);
    $patientRole = Role::factory()->create(['slug' => 'patient']);

    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);
    $patient = User::factory()->create(['role_id' => $patientRole->id]);

    $appointment = Appointment::create([
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'scheduled_at' => Carbon::now()->addDays(2),
        'status' => 'pending',
        'location' => 'Clinic 1',
    ]);

    Sanctum::actingAs($doctor);

    $response = $this->putJson('/api/appointments/'.$appointment->uuid, [
        'status' => 'accepted',
    ]);

    $response->assertSuccessful();

    Event::assertDispatched(AppointmentUpdated::class, function ($event) use ($patient, $doctor, $appointment) {
        return $event->patientUuid === $patient->uuid &&
               $event->doctorUuid === $doctor->uuid &&
               $event->action === 'accepted' &&
               $event->appointmentUuid === $appointment->uuid;
    });
});
