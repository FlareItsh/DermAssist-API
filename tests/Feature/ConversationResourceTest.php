<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('conversation list endpoint returns latest message and correct unread count', function () {
    // 1. Setup Roles
    $patientRole = Role::factory()->create(['slug' => 'patient']);
    $doctorRole = Role::factory()->create(['slug' => 'doctor']);

    // 2. Setup Users
    $patient = User::factory()->create(['role_id' => $patientRole->id]);
    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);

    // 3. Setup Conversation
    $conversation = Conversation::create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ]);

    // 4. Send some messages (doctor sends 2 unread messages, patient sends 1)
    // Patient sends first
    $msg1 = Message::create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
        'message' => 'Hello Doctor!',
        'is_read' => false,
    ]);

    // Doctor replies with 2 messages
    $msg2 = Message::create([
        'conversation_id' => $conversation->id,
        'sender_id' => $doctor->id,
        'message' => 'Hello Patient, how are you?',
        'is_read' => false,
    ]);

    $msg3 = Message::create([
        'conversation_id' => $conversation->id,
        'sender_id' => $doctor->id,
        'message' => 'Are you experiencing any itching?',
        'is_read' => false,
    ]);

    // 5. Test from patient perspective
    Sanctum::actingAs($patient);

    $response = $this->getJson('/api/conversations');

    $response->assertSuccessful();

    // Assert latest message is the last one sent
    $response->assertJsonPath('data.0.latest_message.message', 'Are you experiencing any itching?');
    $response->assertJsonPath('data.0.latest_message.sender_id', $doctor->uuid);

    // Patient has 2 unread messages (from Doctor)
    $response->assertJsonPath('data.0.unread_count', 2);

    // 6. Test from doctor perspective
    Sanctum::actingAs($doctor);

    $response = $this->getJson('/api/conversations');

    $response->assertSuccessful();

    // Doctor has 1 unread message (from Patient)
    $response->assertJsonPath('data.0.unread_count', 1);

    // 7. Mark doctor's message as read and verify unread count decreases
    Sanctum::actingAs($patient);
    $response = $this->putJson("/api/messages/{$msg3->uuid}", []);
    $response->assertSuccessful();

    // Patient list check again
    $response = $this->getJson('/api/conversations');
    $response->assertJsonPath('data.0.unread_count', 1);
});
