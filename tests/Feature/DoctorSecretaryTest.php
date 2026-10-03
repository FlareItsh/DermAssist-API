<?php

use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    $plan = Plan::factory()->create([
        'name' => 'Clinic Pro',
        'max_secretaries' => 5,
    ]);

    Subscription::factory()->create([
        'user_id' => $doctor->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'ends_at' => now()->addMonth(),
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

test('doctor can update their secretary details', function () {
    $doctor = User::factory()->create([
        'role_id' => Role::where('slug', 'doctor')->first()->id,
    ]);

    $secretary = User::factory()->create([
        'role_id' => Role::where('slug', 'secretary')->first()->id,
        'doctor_id' => $doctor->id,
        'first_name' => 'Alice',
        'last_name' => 'Smith',
        'age' => 25,
        'gender' => 'Female',
    ]);

    Sanctum::actingAs($doctor);
    $response = $this->putJson('/api/doctor/secretaries/'.$secretary->uuid, [
        'firstName' => 'Alicia',
        'middleName' => 'Marie',
        'lastName' => 'Johnson',
        'affiliation' => 'Skin Health Clinic',
        'age' => 28,
        'gender' => 'Female',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.first_name', 'Alicia')
        ->assertJsonPath('data.middle_name', 'Marie')
        ->assertJsonPath('data.last_name', 'Johnson')
        ->assertJsonPath('data.affiliation', 'Skin Health Clinic')
        ->assertJsonPath('data.age', 28)
        ->assertJsonPath('data.gender', 'Female');

    $this->assertDatabaseHas('users', [
        'id' => $secretary->id,
        'first_name' => 'Alicia',
        'last_name' => 'Johnson',
        'affiliation' => 'Skin Health Clinic',
        'age' => 28,
        'gender' => 'Female',
    ]);
});

test('doctor can reset secretary password and revoke active tokens', function () {
    $doctor = User::factory()->create([
        'role_id' => Role::where('slug', 'doctor')->first()->id,
    ]);

    $secretary = User::factory()->create([
        'role_id' => Role::where('slug', 'secretary')->first()->id,
        'doctor_id' => $doctor->id,
        'password' => bcrypt('oldpassword123'),
    ]);

    $secretary->createToken('test-token');
    expect($secretary->tokens()->count())->toBe(1);

    Sanctum::actingAs($doctor);
    $response = $this->putJson('/api/doctor/secretaries/'.$secretary->uuid, [
        'password' => 'NewSecurePassword123!',
    ]);

    $response->assertStatus(200);

    // Refresh secretary and verify password changed and token deleted
    $secretary->refresh();
    expect(Hash::check('NewSecurePassword123!', $secretary->password))->toBeTrue();
    expect($secretary->tokens()->count())->toBe(0);
});

test('doctor cannot update another doctor secretary', function () {
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
    $response = $this->putJson('/api/doctor/secretaries/'.$secretaryOfDoctor2->uuid, [
        'firstName' => 'HackedName',
    ]);

    $response->assertStatus(404);
});

test('update secretary validates numbers in names and invalid age', function () {
    $doctor = User::factory()->create([
        'role_id' => Role::where('slug', 'doctor')->first()->id,
    ]);

    $secretary = User::factory()->create([
        'role_id' => Role::where('slug', 'secretary')->first()->id,
        'doctor_id' => $doctor->id,
    ]);

    Sanctum::actingAs($doctor);
    $response = $this->putJson('/api/doctor/secretaries/'.$secretary->uuid, [
        'firstName' => 'John123',
        'age' => -5,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['firstName', 'age']);
});
