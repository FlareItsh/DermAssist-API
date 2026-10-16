<?php

use App\Events\AppointmentUpdated;
use App\Models\Appointment;
use App\Models\Diagnosis;
use App\Models\Role;
use App\Models\User;
use App\Service\ClinicalNoteService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('storeNoteForDiagnosis marks existing active appointment as completed and dispatches broadcast', function () {
    Event::fake([AppointmentUpdated::class]);

    $doctorRole = Role::factory()->create(['slug' => 'doctor']);
    $patientRole = Role::factory()->create(['slug' => 'patient']);

    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);
    $patient = User::factory()->create(['role_id' => $patientRole->id]);

    $activeAppointment = Appointment::create([
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'scheduled_at' => Carbon::now()->addDays(1),
        'status' => 'scheduled',
        'location' => 'Cruz Skin Clinic',
    ]);

    $diagnosis = Diagnosis::create([
        'user_uuid' => $doctor->uuid,
        'patient_uuid' => $patient->uuid,
        'doctor_uuid' => $doctor->uuid,
        'image_path' => 'diagnoses/test.jpg',
        'label' => 'Acne',
        'confidence' => 0.88,
        'probabilities' => ['Acne' => 0.88],
        'status' => 'active',
    ]);

    $service = app(ClinicalNoteService::class);
    $clinicalNote = $service->storeNoteForDiagnosis($diagnosis->uuid, [
        'final_diagnosis' => 'Acne Vulgaris',
        'history_of_present_illness' => 'Patient has mild papules on cheeks.',
    ], $doctor);

    expect($clinicalNote)->not->toBeNull();
    expect($clinicalNote->appointment_id)->toBe($activeAppointment->id);

    $activeAppointment->refresh();
    expect($activeAppointment->status)->toBe('completed');
    expect($activeAppointment->diagnosis_id)->toBe($diagnosis->id);

    Event::assertDispatched(AppointmentUpdated::class, function ($event) use ($patient, $doctor, $activeAppointment) {
        return $event->patientUuid === $patient->uuid &&
               $event->doctorUuid === $doctor->uuid &&
               $event->action === 'completed' &&
               $event->appointmentUuid === $activeAppointment->uuid;
    });
});

test('updating appointment status to completed via api updates status and broadcasts', function () {
    Event::fake([AppointmentUpdated::class]);

    $doctorRole = Role::factory()->create(['slug' => 'doctor']);
    $patientRole = Role::factory()->create(['slug' => 'patient']);

    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);
    $patient = User::factory()->create(['role_id' => $patientRole->id]);

    $appointment = Appointment::create([
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'scheduled_at' => Carbon::now()->addDays(2),
        'status' => 'scheduled',
        'location' => 'Main Clinic',
    ]);

    Sanctum::actingAs($doctor);

    $response = $this->putJson('/api/appointments/'.$appointment->uuid, [
        'status' => 'completed',
    ]);

    $response->assertSuccessful();

    $appointment->refresh();
    expect($appointment->status)->toBe('completed');

    Event::assertDispatched(AppointmentUpdated::class, function ($event) use ($patient, $doctor, $appointment) {
        return $event->patientUuid === $patient->uuid &&
               $event->doctorUuid === $doctor->uuid &&
               $event->action === 'completed' &&
               $event->appointmentUuid === $appointment->uuid;
    });
});
