<?php

use App\Console\Commands\ProcessScheduledAccountActions;
use App\Mail\PatientResetPasswordMail;
use App\Models\DoctorAvailability;
use App\Models\DoctorVerification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->doctorRole = Role::firstOrCreate(['slug' => 'doctor', 'name' => 'Doctor']);
    $this->patientRole = Role::firstOrCreate(['slug' => 'patient', 'name' => 'Patient']);

    $this->doctor = User::factory()->create(['role_id' => $this->doctorRole->id]);
});

test('doctor can create patient', function () {
    Sanctum::actingAs($this->doctor);

    $response = $this->postJson('/api/doctor/patients', [
        'firstName' => 'John',
        'lastName' => 'Doe',
        'email' => 'john@test.com',
        'password' => 'password',
    ]);

    $response->assertStatus(201);

    $this->assertDatabaseHas('users', [
        'email' => 'john@test.com',
        'is_doctor_registered' => true,
        'registered_by_doctor_id' => $this->doctor->id,
        'account_status' => 'active',
    ]);
});

test('disabled patient cannot login', function () {
    $patient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'password' => bcrypt('password'),
        'account_status' => 'disabled',
    ]);

    $response = $this->postJson('/api/login', [
        'email' => $patient->email,
        'password' => 'password',
    ]);

    $response->assertStatus(403);
});

test('doctor can schedule patient action', function () {
    Sanctum::actingAs($this->doctor);
    $patient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'registered_by_doctor_id' => $this->doctor->id,
    ]);

    $response = $this->postJson("/api/doctor/patients/{$patient->uuid}/schedule-action", [
        'action' => 'disable',
        'scheduled_at' => now()->addDay()->toDateTimeString(),
    ]);

    $response->assertStatus(200);
    $this->assertDatabaseHas('users', [
        'id' => $patient->id,
        'account_action' => 'disable',
    ]);
});

test('scheduled patient deletion is processed when time is due', function () {
    $patient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'registered_by_doctor_id' => $this->doctor->id,
        'account_action' => 'delete',
        'account_action_scheduled_at' => now()->subMinute()->toDateTimeString(),
    ]);

    ProcessScheduledAccountActions::processDueActions();

    $this->assertDatabaseMissing('users', [
        'id' => $patient->id,
    ]);
});

test('doctor registered patient cannot create appointment with other doctor', function () {
    $otherDoctor = User::factory()->create(['role_id' => $this->doctorRole->id]);

    $patient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'is_doctor_registered' => true,
        'registered_by_doctor_id' => $this->doctor->id,
        'account_status' => 'active',
    ]);

    Sanctum::actingAs($patient);

    $response = $this->postJson('/api/appointments', [
        'doctor_id' => $otherDoctor->id,
        'message' => 'Want appointment with another doctor',
    ]);

    $response->assertStatus(403);
    $this->assertStringContainsString('registered under an attending doctor', $response->json('message'));
});

test('doctor registered patient can create appointment with registering doctor', function () {
    $patient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'is_doctor_registered' => true,
        'registered_by_doctor_id' => $this->doctor->id,
        'account_status' => 'active',
    ]);

    Sanctum::actingAs($patient);

    $response = $this->postJson('/api/appointments', [
        'doctor_id' => $this->doctor->id,
        'message' => 'Consultation with my assigned doctor',
    ]);

    $response->assertStatus(200);
    $this->assertDatabaseHas('appointments', [
        'doctor_id' => $this->doctor->id,
        'patient_id' => $patient->id,
        'status' => 'pending',
    ]);
});

test('availability check for doctor registered patient does not return alternatives when doctor is unavailable', function () {
    // Block doctor availability
    DoctorAvailability::create([
        'doctor_id' => $this->doctor->id,
        'available_date' => now()->toDateString(),
        'start_time' => '00:00:00',
        'end_time' => '23:59:59',
        'is_available' => false,
    ]);

    // Create another doctor who would otherwise be an alternative
    $otherDoc = User::factory()->create([
        'role_id' => $this->doctorRole->id,
    ]);
    DoctorVerification::create([
        'user_id' => $otherDoc->id,
        'prc_number' => '9988776',
        'status' => 'verified',
    ]);

    $patient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'is_doctor_registered' => true,
        'registered_by_doctor_id' => $this->doctor->id,
        'account_status' => 'active',
    ]);

    Sanctum::actingAs($patient);

    $response = $this->getJson("/api/doctors/{$this->doctor->uuid}/availability-check?date=".now()->toDateString());

    $response->assertStatus(200);
    $response->assertJson([
        'is_available' => false,
        'alternatives' => [],
    ]);
});

test('doctor can create walk-in patient without email and password', function () {
    Sanctum::actingAs($this->doctor);

    $response = $this->postJson('/api/doctor/patients', [
        'firstName' => 'Maria',
        'lastName' => 'Santos',
        'date_of_birth' => '1995-05-10',
        'age' => 31,
        'gender' => 'Female',
        'is_walk_in' => true,
    ]);

    $response->assertStatus(201);
    $user = $response->json('user');
    expect($user['first_name'])->toBe('Maria')
        ->and($user['last_name'])->toBe('Santos')
        ->and($user['uuid'])->not->toBeEmpty();

    $this->assertDatabaseHas('users', [
        'first_name' => 'Maria',
        'last_name' => 'Santos',
        'is_doctor_registered' => true,
        'registered_by_doctor_id' => $this->doctor->id,
    ]);
});

test('doctor can update patient details and assign real email to walk-in', function () {
    Sanctum::actingAs($this->doctor);

    $patient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'first_name' => 'WalkIn',
        'last_name' => 'Patient',
        'email' => 'walkin.12345@clinic.local',
        'registered_by_doctor_id' => $this->doctor->id,
    ]);

    $response = $this->putJson("/api/doctor/patients/{$patient->uuid}", [
        'first_name' => 'Maria Clara',
        'last_name' => 'Dela Cruz',
        'email' => 'mariaclara@example.com',
        'contact_number' => '+63 912 345 6789',
        'date_of_birth' => '2000-01-15',
        'gender' => 'Female',
        'street' => '123 Rizal St',
        'city' => 'Davao City',
        'province' => 'Davao del Sur',
    ]);

    $response->assertStatus(200);
    $data = $response->json('data') ?? $response->json();
    expect($data['first_name'])->toBe('Maria Clara')
        ->and($data['last_name'])->toBe('Dela Cruz')
        ->and($data['email'])->toBe('mariaclara@example.com')
        ->and($data['is_walk_in'])->toBeFalse();

    $this->assertDatabaseHas('users', [
        'id' => $patient->id,
        'first_name' => 'Maria Clara',
        'email' => 'mariaclara@example.com',
        'city' => 'Davao City',
    ]);
});

test('doctor can dispatch password reset link to patient', function () {
    Mail::fake();
    Sanctum::actingAs($this->doctor);

    $patient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'email' => 'registered.patient@example.com',
        'registered_by_doctor_id' => $this->doctor->id,
    ]);

    $response = $this->postJson("/api/doctor/patients/{$patient->uuid}/send-reset-link");
    $response->assertStatus(200);

    Mail::assertSent(PatientResetPasswordMail::class, function ($mail) use ($patient) {
        return $mail->hasTo($patient->email);
    });
});

test('doctor can issue temporary password with must_change_password flag', function () {
    Sanctum::actingAs($this->doctor);

    $patient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'registered_by_doctor_id' => $this->doctor->id,
        'must_change_password' => false,
    ]);

    $response = $this->postJson("/api/doctor/patients/{$patient->uuid}/temporary-password", [
        'temporary_password' => 'TempPass1234!',
    ]);

    $response->assertStatus(200);
    expect($response->json('temporary_password'))->toBe('TempPass1234!')
        ->and($response->json('must_change_password'))->toBeTrue();

    $patient->refresh();
    expect($patient->must_change_password)->toBeTrue()
        ->and(Hash::check('TempPass1234!', $patient->password))->toBeTrue();
});

test('patient can reset password with token', function () {
    $patient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'email' => 'reset.patient@example.com',
        'must_change_password' => true,
    ]);

    $token = Password::broker()->createToken($patient);

    $response = $this->postJson('/api/reset-password', [
        'email' => 'reset.patient@example.com',
        'token' => $token,
        'password' => 'NewSecretPassword123!',
        'password_confirmation' => 'NewSecretPassword123!',
    ]);

    $response->assertStatus(200);

    $patient->refresh();
    expect($patient->must_change_password)->toBeFalse()
        ->and(Hash::check('NewSecretPassword123!', $patient->password))->toBeTrue();
});

test('reset password rejects weak password failing complexity criteria', function () {
    $patient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'email' => 'weak.patient@example.com',
    ]);

    $token = Password::broker()->createToken($patient);

    $response = $this->postJson('/api/reset-password', [
        'email' => 'weak.patient@example.com',
        'token' => $token,
        'password' => 'simplepassword',
        'password_confirmation' => 'simplepassword',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

test('unauthorized doctor cannot update another doctors patient', function () {
    $otherDoctor = User::factory()->create(['role_id' => $this->doctorRole->id]);
    $patient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'registered_by_doctor_id' => $otherDoctor->id,
    ]);

    Sanctum::actingAs($this->doctor);

    $response = $this->putJson("/api/doctor/patients/{$patient->uuid}", [
        'first_name' => 'Hacked',
        'last_name' => 'Patient',
        'email' => 'hacked@example.com',
    ]);

    $response->assertStatus(403);
});

test('doctor registered patient has no 48 hour deadline and is email verified', function () {
    Sanctum::actingAs($this->doctor);

    $response = $this->postJson('/api/doctor/patients', [
        'firstName' => 'Active',
        'lastName' => 'Patient',
        'email' => 'active.patient@example.com',
        'password' => 'TemporaryPass123!',
    ]);

    $response->assertStatus(201);

    $created = User::where('email', 'active.patient@example.com')->first();
    expect($created)->not->toBeNull()
        ->and($created->account_status)->toBe('active')
        ->and($created->verification_deadline)->toBeNull()
        ->and($created->verification_token)->toBeNull()
        ->and($created->email_verified_at)->not->toBeNull();
});

test('doctor registered patient can update their email in profile', function () {
    $patient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'is_doctor_registered' => true,
        'registered_by_doctor_id' => $this->doctor->id,
        'email' => 'walkin.original@clinic.local',
        'account_status' => 'active',
        'verification_deadline' => null,
    ]);

    Sanctum::actingAs($patient);

    $response = $this->putJson("/api/users/{$patient->uuid}", [
        'email' => 'patient.newemail@example.com',
    ]);

    $response->assertStatus(200);

    $patient->refresh();
    expect($patient->email)->toBe('patient.newemail@example.com')
        ->and($patient->account_status)->toBe('active')
        ->and($patient->verification_deadline)->toBeNull()
        ->and($patient->email_verified_at)->not->toBeNull();
});

test('patient email update rejects duplicate email', function () {
    User::factory()->create([
        'role_id' => $this->patientRole->id,
        'email' => 'existing.user@example.com',
    ]);

    $patient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'is_doctor_registered' => true,
        'registered_by_doctor_id' => $this->doctor->id,
        'email' => 'walkin.dup@clinic.local',
    ]);

    Sanctum::actingAs($patient);

    $response = $this->putJson("/api/users/{$patient->uuid}", [
        'email' => 'existing.user@example.com',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});
