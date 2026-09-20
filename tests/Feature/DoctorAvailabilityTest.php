<?php

use App\Models\DoctorAvailability;
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

test('returns 409 conflict when new schedule overlaps existing schedule without overwrite flag', function () {
    Sanctum::actingAs($this->doctor);

    $dateStr = now()->addDays(3)->toDateString();

    // Create initial duty slot 09:00 - 17:00
    $this->postJson("/api/doctors/{$this->doctor->uuid}/availabilities", [
        'available_date' => $dateStr,
        'start_time' => '09:00',
        'end_time' => '17:00',
        'is_available' => true,
    ])->assertStatus(201);

    // Attempt to add overlapping slot 12:00 - 13:00 without overwrite
    $response = $this->postJson("/api/doctors/{$this->doctor->uuid}/availabilities", [
        'available_date' => $dateStr,
        'start_time' => '12:00',
        'end_time' => '13:00',
        'is_available' => false,
    ]);

    $response->assertStatus(409);
    $response->assertJsonPath('conflict', true);
    $response->assertJsonCount(1, 'overlapping_slots');
});

test('cleanly splits existing schedule when overwrite is confirmed', function () {
    Sanctum::actingAs($this->doctor);

    $dateStr = now()->addDays(4)->toDateString();

    // Create initial duty slot 09:00 - 17:00
    $this->postJson("/api/doctors/{$this->doctor->uuid}/availabilities", [
        'available_date' => $dateStr,
        'start_time' => '09:00',
        'end_time' => '17:00',
        'is_available' => true,
    ])->assertStatus(201);

    // Add blocked period 12:00 - 13:00 with overwrite: true
    $response = $this->postJson("/api/doctors/{$this->doctor->uuid}/availabilities", [
        'available_date' => $dateStr,
        'start_time' => '12:00',
        'end_time' => '13:00',
        'is_available' => false,
        'overwrite' => true,
    ]);

    $response->assertStatus(201);

    // Should have 3 records: 09:00-12:00 (duty), 12:00-13:00 (blocked), 13:00-17:00 (duty)
    $slots = DoctorAvailability::where('doctor_id', $this->doctor->id)
        ->whereDate('available_date', $dateStr)
        ->orderBy('start_time', 'asc')
        ->get();

    expect($slots)->toHaveCount(3);
    expect($slots[0]->start_time)->toBe('09:00:00')
        ->and($slots[0]->end_time)->toBe('12:00:00')
        ->and($slots[0]->is_available)->toBeTrue();

    expect($slots[1]->start_time)->toBe('12:00:00')
        ->and($slots[1]->end_time)->toBe('13:00:00')
        ->and($slots[1]->is_available)->toBeFalse();

    expect($slots[2]->start_time)->toBe('13:00:00')
        ->and($slots[2]->end_time)->toBe('17:00:00')
        ->and($slots[2]->is_available)->toBeTrue();
});

test('whole day block overwrites and clears all other slots on that date', function () {
    Sanctum::actingAs($this->doctor);

    $dateStr = now()->addDays(5)->toDateString();

    // Create two slots
    $this->postJson("/api/doctors/{$this->doctor->uuid}/availabilities", [
        'available_date' => $dateStr,
        'start_time' => '09:00',
        'end_time' => '12:00',
        'is_available' => true,
    ])->assertStatus(201);

    $this->postJson("/api/doctors/{$this->doctor->uuid}/availabilities", [
        'available_date' => $dateStr,
        'start_time' => '13:00',
        'end_time' => '17:00',
        'is_available' => true,
    ])->assertStatus(201);

    // Add whole day block with overwrite: true
    $response = $this->postJson("/api/doctors/{$this->doctor->uuid}/availabilities", [
        'available_date' => $dateStr,
        'start_time' => '00:00',
        'end_time' => '23:59',
        'is_available' => false,
        'overwrite' => true,
    ]);

    $response->assertStatus(201);

    $slots = DoctorAvailability::where('doctor_id', $this->doctor->id)
        ->whereDate('available_date', $dateStr)
        ->get();

    expect($slots)->toHaveCount(1)
        ->and($slots[0]->is_available)->toBeFalse()
        ->and($slots[0]->start_time)->toBe('00:00:00')
        ->and($slots[0]->end_time)->toBe('23:59:00');
});
