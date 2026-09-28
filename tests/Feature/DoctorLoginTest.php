<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
});

test('doctor can successfully log in with valid credentials', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();

    $doctor = User::factory()->create([
        'email' => 'doctor@dermassist.com',
        'password' => Hash::make('password123'),
        'role_id' => $doctorRole->id,
        'account_status' => 'active',
    ]);

    $response = $this->postJson('/api/login', [
        'email' => 'doctor@dermassist.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'user' => [
            'id',
            'uuid',
            'first_name',
            'last_name',
            'email',
            'role',
        ],
        'token',
    ]);
    expect($response->json('user.role'))->toBe('doctor');
});

test('doctor login trims and normalizes email casing', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();

    User::factory()->create([
        'email' => 'doctor@dermassist.com',
        'password' => Hash::make('password123'),
        'role_id' => $doctorRole->id,
        'account_status' => 'active',
    ]);

    $response = $this->postJson('/api/login', [
        'email' => '  DOCTOR@DERMASSIST.COM  ',
        'password' => 'password123',
    ]);

    $response->assertStatus(200);
    expect($response->json('user.role'))->toBe('doctor');
});

test('disabled doctor account cannot log in', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();

    User::factory()->create([
        'email' => 'disabled.doctor@dermassist.com',
        'password' => Hash::make('password123'),
        'role_id' => $doctorRole->id,
        'account_status' => 'disabled',
    ]);

    $response = $this->postJson('/api/login', [
        'email' => 'disabled.doctor@dermassist.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(403);
});
