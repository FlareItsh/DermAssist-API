<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['slug' => 'patient', 'name' => 'Patient']);
    Role::firstOrCreate(['slug' => 'doctor', 'name' => 'Doctor']);
    Role::firstOrCreate(['slug' => 'secretary', 'name' => 'Secretary']);
});

test('profile update rejects numeric characters in names', function () {
    $patientRole = Role::where('slug', 'patient')->first();
    $user = User::factory()->create([
        'role_id' => $patientRole->id,
        'first_name' => 'John',
        'middle_name' => 'Isaac',
        'last_name' => 'Doe',
        'age' => 25,
    ]);

    Sanctum::actingAs($user);

    // Numeric first name
    $res1 = $this->putJson("/api/users/{$user->uuid}", [
        'first_name' => 'John123',
    ]);
    $res1->assertStatus(422);
    $res1->assertJsonValidationErrors(['first_name']);

    // Numeric middle name
    $res2 = $this->putJson("/api/users/{$user->uuid}", [
        'middle_name' => 'Isaac99',
    ]);
    $res2->assertStatus(422);
    $res2->assertJsonValidationErrors(['middle_name']);

    // Numeric last name
    $res3 = $this->putJson("/api/users/{$user->uuid}", [
        'last_name' => 'Doe7',
    ]);
    $res3->assertStatus(422);
    $res3->assertJsonValidationErrors(['last_name']);
});

test('profile update rejects negative or invalid age', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $user = User::factory()->create([
        'role_id' => $doctorRole->id,
        'first_name' => 'Beatriz',
        'last_name' => 'Cruz',
        'age' => 34,
    ]);

    Sanctum::actingAs($user);

    // Negative age
    $res1 = $this->putJson("/api/users/{$user->uuid}", [
        'age' => -13,
    ]);
    $res1->assertStatus(422);
    $res1->assertJsonValidationErrors(['age']);

    // Negative age string
    $res2 = $this->putJson("/api/users/{$user->uuid}", [
        'age' => '-8',
    ]);
    $res2->assertStatus(422);
    $res2->assertJsonValidationErrors(['age']);

    // Age exceeding 130
    $res3 = $this->putJson("/api/users/{$user->uuid}", [
        'age' => 150,
    ]);
    $res3->assertStatus(422);
    $res3->assertJsonValidationErrors(['age']);
});

test('profile update succeeds with valid sanitized names and positive age', function () {
    $patientRole = Role::where('slug', 'patient')->first();
    $user = User::factory()->create([
        'role_id' => $patientRole->id,
        'first_name' => 'John',
        'last_name' => 'Doe',
        'age' => 25,
    ]);

    Sanctum::actingAs($user);

    $res = $this->putJson("/api/users/{$user->uuid}", [
        'first_name' => 'Mary-Jane',
        'middle_name' => "O'Connor",
        'last_name' => 'Dela Cruz',
        'age' => 28,
    ]);

    $res->assertStatus(200);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'first_name' => 'Mary-Jane',
        'middle_name' => "O'Connor",
        'last_name' => 'Dela Cruz',
        'age' => 28,
    ]);
});

test('profile update accepts age below 18 without restriction', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $user = User::factory()->create([
        'role_id' => $doctorRole->id,
        'first_name' => 'DrJane',
        'last_name' => 'Doe',
        'age' => 30,
    ]);

    Sanctum::actingAs($user);

    $res = $this->putJson("/api/users/{$user->uuid}", [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'age' => 16,
    ]);

    $res->assertStatus(200);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'age' => 16,
    ]);
});

test('profile update normalizes gender and handles Not Set as null', function () {
    $patientRole = Role::where('slug', 'patient')->first();
    $user = User::factory()->create([
        'role_id' => $patientRole->id,
        'first_name' => 'John',
        'last_name' => 'Doe',
        'gender' => 'Male',
    ]);

    Sanctum::actingAs($user);

    // Update with lowercase female -> stored as Female
    $res1 = $this->putJson("/api/users/{$user->uuid}", [
        'gender' => 'female',
    ]);
    $res1->assertStatus(200);
    expect($user->fresh()->gender)->toBe('Female');

    // Update with 'Not Set' -> stored as null
    $res2 = $this->putJson("/api/users/{$user->uuid}", [
        'gender' => 'Not Set',
    ]);
    $res2->assertStatus(200);
    expect($user->fresh()->gender)->toBeNull();

    // Update with empty string '' -> stored as null
    $res3 = $this->putJson("/api/users/{$user->uuid}", [
        'gender' => '',
    ]);
    $res3->assertStatus(200);
    expect($user->fresh()->gender)->toBeNull();
});
