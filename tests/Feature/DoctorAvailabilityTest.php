<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->doctorRole = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    $this->secretaryRole = Role::firstOrCreate(['slug' => 'secretary'], ['name' => 'Secretary']);

    $this->doctor = User::factory()->create([
        'role_id' => $this->doctorRole->id,
    ]);

    $this->secretary = User::factory()->create([
        'role_id' => $this->secretaryRole->id,
        'doctor_id' => $this->doctor->id,
    ]);
});

test('doctor can create full day blocked slot', function () {
    Sanctum::actingAs($this->doctor);

    $response = $this->postJson("/api/doctors/{$this->doctor->uuid}/availabilities", [
        'available_date' => now()->addDays(5)->toDateString(),
        'start_time' => '00:00',
        'end_time' => '23:59',
        'is_available' => false,
    ]);

    $response->assertStatus(201);
    $this->assertDatabaseHas('doctor_availabilities', [
        'doctor_id' => $this->doctor->id,
        'start_time' => '00:00',
        'end_time' => '23:59',
        'is_available' => 0,
    ]);
});

test('secretary can create full day blocked slot for linked doctor', function () {
    Sanctum::actingAs($this->secretary);

    $response = $this->postJson("/api/doctors/{$this->doctor->uuid}/availabilities", [
        'available_date' => now()->addDays(6)->toDateString(),
        'start_time' => '00:00',
        'end_time' => '23:59',
        'is_available' => false,
    ]);

    $response->assertStatus(201);
    $this->assertDatabaseHas('doctor_availabilities', [
        'doctor_id' => $this->doctor->id,
        'start_time' => '00:00',
        'end_time' => '23:59',
        'is_available' => 0,
    ]);
});
