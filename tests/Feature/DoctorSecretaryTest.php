<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
});

test('doctor can list their assigned secretaries', function () {
    $doctor = User::factory()->create([
        'role_id' => Role::where('slug', 'doctor')->first()->id,
    ]);

    $secretary = User::factory()->create([
        'role_id' => Role::where('slug', 'secretary')->first()->id,
        'doctor_id' => $doctor->id,
    ]);

    $otherSecretary = User::factory()->create([
        'role_id' => Role::where('slug', 'secretary')->first()->id,
    ]);

    Sanctum::actingAs($doctor);
    $response = $this->getJson('/api/doctor/secretaries');

    $response->assertStatus(200);
    $data = $response->json('data') ?? $response->json();
    expect($data)->toHaveCount(1);
});

test('doctor can create/register a secretary', function () {
    $doctor = User::factory()->create([
        'role_id' => Role::where('slug', 'doctor')->first()->id,
    ]);

    $payload = [
        'firstName' => 'Jane',
        'middleName' => 'Ann',
        'lastName' => 'Doe',
        'email' => 'jane.secretary@dermassist.com',
        'password' => 'password123',
    ];

    Sanctum::actingAs($doctor);
    $response = $this->postJson('/api/doctor/secretaries', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('data.email', 'jane.secretary@dermassist.com');

    $this->assertDatabaseHas('users', [
        'email' => 'jane.secretary@dermassist.com',
        'doctor_id' => $doctor->id,
    ]);
});

test('doctor can remove/soft-delete their assigned secretary', function () {
    $doctor = User::factory()->create([
        'role_id' => Role::where('slug', 'doctor')->first()->id,
    ]);

    $secretary = User::factory()->create([
        'role_id' => Role::where('slug', 'secretary')->first()->id,
        'doctor_id' => $doctor->id,
    ]);

    Sanctum::actingAs($doctor);
    $response = $this->deleteJson('/api/doctor/secretaries/'.$secretary->uuid);

    $response->assertStatus(200);

    $this->assertSoftDeleted('users', [
        'id' => $secretary->id,
    ]);
});

test('doctor cannot remove another doctor secretary', function () {
    $doctor1 = User::factory()->create([
        'role_id' => Role::where('slug', 'doctor')->first()->id,
    ]);

    $doctor2 = User::factory()->create([
        'role_id' => Role::where('slug', 'doctor')->first()->id,
    ]);

    $secretaryOfDoctor2 = User::factory()->create([
        'role_id' => Role::where('slug', 'secretary')->first()->id,
        'doctor_id' => $doctor2->id,
    ]);

    Sanctum::actingAs($doctor1);
    $response = $this->deleteJson('/api/doctor/secretaries/'.$secretaryOfDoctor2->uuid);

    $response->assertStatus(404);
});
