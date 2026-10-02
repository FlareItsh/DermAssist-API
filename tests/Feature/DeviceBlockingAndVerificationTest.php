<?php

use App\Console\Commands\ProcessScheduledAccountActions;
use App\Models\BlockedDevice;
use App\Models\BlockedIp;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->patientRole = Role::firstOrCreate(['slug' => 'patient', 'name' => 'Patient']);
    $this->doctorRole = Role::firstOrCreate(['slug' => 'doctor', 'name' => 'Doctor']);
    $this->adminRole = Role::firstOrCreate(['slug' => 'admin', 'name' => 'Admin']);
});

test('public patient registration sets active status with device and cookie tracking', function () {
    $response = $this->postJson('/api/register', [
        'firstName' => 'Spam',
        'lastName' => 'Tester',
        'email' => 'spamtester@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'patient',
        'device_token' => 'device-uuid-12345',
        'cookies_accepted' => true,
    ]);

    $response->assertStatus(201);

    $this->assertDatabaseHas('users', [
        'email' => 'spamtester@example.com',
        'account_status' => 'active',
        'device_token' => 'device-uuid-12345',
    ]);

    $user = User::where('email', 'spamtester@example.com')->first();
    expect($user->account_status)->toBe('active')
        ->and($user->cookies_accepted_at)->not->toBeNull();
});

test('blocked device cannot login or register', function () {
    BlockedDevice::create([
        'device_id' => 'banned-hw-device-999',
        'reason' => 'Automated bot registration detected',
    ]);

    // Registration attempt
    $registerResponse = $this->withHeaders([
        'X-Device-Id' => 'banned-hw-device-999',
    ])->postJson('/api/register', [
        'firstName' => 'Bot',
        'lastName' => 'Attempt',
        'email' => 'bot@example.com',
        'password' => 'password123',
        'role' => 'patient',
    ]);

    $registerResponse->assertStatus(403);
    expect($registerResponse->json('is_device_blocked'))->toBeTrue();

    // Login attempt
    $user = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'email' => 'innocent@example.com',
        'password' => bcrypt('password123'),
    ]);

    $loginResponse = $this->withHeaders([
        'X-Device-Id' => 'banned-hw-device-999',
    ])->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password123',
    ]);

    $loginResponse->assertStatus(403);
});

test('blocked ip address cannot access auth routes', function () {
    BlockedIp::create([
        'ip_address' => '192.168.1.100',
        'reason' => 'DDoS attempt',
    ]);

    $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.100'])
        ->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

    $response->assertStatus(403);
    expect($response->json('is_ip_blocked'))->toBeTrue();
});

test('valid token verifies account and activates it', function () {
    $user = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'account_status' => 'pending_verification',
        'verification_token' => 'valid-secret-token-xyz',
        'verification_deadline' => now()->addHours(24),
    ]);

    $response = $this->postJson('/api/verify-account', [
        'token' => 'valid-secret-token-xyz',
    ]);

    $response->assertStatus(200);

    $user->refresh();
    expect($user->account_status)->toBe('active')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->verification_token)->toBeNull();
});

test('overdue unverified account is soft-deleted by scheduled processor', function () {
    $expiredUser = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'account_status' => 'pending_verification',
        'verification_token' => 'expired-token',
        'verification_deadline' => now()->subHour(),
    ]);

    $activeUser = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'account_status' => 'pending_verification',
        'verification_token' => 'valid-token',
        'verification_deadline' => now()->addHours(12),
    ]);

    ProcessScheduledAccountActions::processDueActions();

    // Expired user should be soft deleted (deleted_at is set)
    expect(User::find($expiredUser->id))->toBeNull()
        ->and(User::withTrashed()->find($expiredUser->id)->trashed())->toBeTrue();

    // Active unexpired user should still exist
    expect(User::find($activeUser->id))->not->toBeNull();
});

test('aged trashed dummy accounts are permanently force deleted', function () {
    $trashedUser = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'account_status' => 'pending_verification',
    ]);

    // Soft delete it
    $trashedUser->delete();

    // Update deleted_at to 15 days ago
    User::withTrashed()->where('id', $trashedUser->id)->update([
        'deleted_at' => now()->subDays(15),
    ]);

    ProcessScheduledAccountActions::pruneExpiredTrash(14);

    // Should be permanently gone
    expect(User::withTrashed()->find($trashedUser->id))->toBeNull();
});
