<?php

use App\Models\Conversation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-id',
    ]);
});

test('user can authenticate to their own private user channel', function () {
    $patientRole = Role::factory()->create(['slug' => 'patient']);
    $user = User::factory()->create(['role_id' => $patientRole->id]);

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/broadcasting/auth', [
        'channel_name' => 'private-user.'.$user->uuid,
        'socket_id' => '1234.5678',
    ]);

    $response->assertSuccessful();
    $response->assertJsonStructure(['auth']);
});

test('user cannot authenticate to another user private channel', function () {
    $patientRole = Role::factory()->create(['slug' => 'patient']);
    $user1 = User::factory()->create(['role_id' => $patientRole->id]);
    $user2 = User::factory()->create(['role_id' => $patientRole->id]);

    Sanctum::actingAs($user1);

    $response = $this->postJson('/api/broadcasting/auth', [
        'channel_name' => 'private-user.'.$user2->uuid,
        'socket_id' => '1234.5678',
    ]);

    $response->assertForbidden();
});

test('conversation participants can authenticate to chat conversation channel', function () {
    $patientRole = Role::factory()->create(['slug' => 'patient']);
    $doctorRole = Role::factory()->create(['slug' => 'doctor']);

    $patient = User::factory()->create(['role_id' => $patientRole->id]);
    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);

    $conversation = Conversation::create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ]);

    // Test patient can authenticate
    Sanctum::actingAs($patient);
    $response = $this->postJson('/api/broadcasting/auth', [
        'channel_name' => 'private-chat.conversation.'.$conversation->uuid,
        'socket_id' => '1234.5678',
    ]);
    $response->assertSuccessful();

    // Test doctor can authenticate
    Sanctum::actingAs($doctor);
    $response = $this->postJson('/api/broadcasting/auth', [
        'channel_name' => 'private-chat.conversation.'.$conversation->uuid,
        'socket_id' => '1234.5678',
    ]);
    $response->assertSuccessful();
});

test('non-participant cannot authenticate to chat conversation channel', function () {
    $patientRole = Role::factory()->create(['slug' => 'patient']);
    $doctorRole = Role::factory()->create(['slug' => 'doctor']);

    $patient = User::factory()->create(['role_id' => $patientRole->id]);
    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);
    $intruder = User::factory()->create(['role_id' => $patientRole->id]);

    $conversation = Conversation::create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ]);

    Sanctum::actingAs($intruder);
    $response = $this->postJson('/api/broadcasting/auth', [
        'channel_name' => 'private-chat.conversation.'.$conversation->uuid,
        'socket_id' => '1234.5678',
    ]);

    $response->assertForbidden();
});

test('admin can authenticate to moderation and retraining channels but regular users cannot', function () {
    $adminRole = Role::factory()->create(['slug' => 'admin']);
    $patientRole = Role::factory()->create(['slug' => 'patient']);

    $admin = User::factory()->create(['role_id' => $adminRole->id]);
    $patient = User::factory()->create(['role_id' => $patientRole->id]);

    // Regular patient fails
    Sanctum::actingAs($patient);
    $this->postJson('/api/broadcasting/auth', [
        'channel_name' => 'private-admin.moderation',
        'socket_id' => '1234.5678',
    ])->assertForbidden();

    // Admin succeeds
    Sanctum::actingAs($admin);
    $this->postJson('/api/broadcasting/auth', [
        'channel_name' => 'private-admin.moderation',
        'socket_id' => '1234.5678',
    ])->assertSuccessful();

    $this->postJson('/api/broadcasting/auth', [
        'channel_name' => 'private-admin.ai-retraining',
        'socket_id' => '1234.5678',
    ])->assertSuccessful();
});
