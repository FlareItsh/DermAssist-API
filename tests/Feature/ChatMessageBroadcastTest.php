<?php

use App\Events\ChatMessageDeleted;
use App\Events\ChatMessageEdited;
use App\Events\ChatMessageSent;
use App\Events\ChatMessagesRead;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('sending a message dispatches ChatMessageSent broadcast event', function () {
    Event::fake([ChatMessageSent::class]);

    $patientRole = Role::factory()->create(['slug' => 'patient']);
    $doctorRole = Role::factory()->create(['slug' => 'doctor']);

    $patient = User::factory()->create(['role_id' => $patientRole->id]);
    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);

    $conversation = Conversation::create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ]);

    Sanctum::actingAs($patient);

    $response = $this->postJson('/api/conversations/'.$conversation->uuid.'/messages', [
        'message' => 'Hello doctor, live via websockets!',
    ]);

    $response->assertSuccessful();

    Event::assertDispatched(ChatMessageSent::class, function ($event) use ($conversation) {
        return $event->conversationUuid === $conversation->uuid &&
               $event->message['message'] === 'Hello doctor, live via websockets!';
    });
});

test('marking a message as read dispatches ChatMessagesRead broadcast event', function () {
    Event::fake([ChatMessagesRead::class]);

    $patientRole = Role::factory()->create(['slug' => 'patient']);
    $doctorRole = Role::factory()->create(['slug' => 'doctor']);

    $patient = User::factory()->create(['role_id' => $patientRole->id]);
    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);

    $conversation = Conversation::create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ]);

    $message = Message::create([
        'conversation_id' => $conversation->id,
        'sender_id' => $doctor->id,
        'message' => 'Unread message from doctor',
        'is_read' => false,
    ]);

    Sanctum::actingAs($patient);

    $response = $this->putJson('/api/messages/'.$message->uuid, [
        'is_read' => true,
    ]);

    $response->assertSuccessful();

    Event::assertDispatched(ChatMessagesRead::class, function ($event) use ($conversation, $message) {
        return $event->conversationUuid === $conversation->uuid &&
               in_array($message->uuid, $event->messageUuids);
    });
});

test('editing a message dispatches ChatMessageEdited broadcast event', function () {
    Event::fake([ChatMessageEdited::class]);

    $patientRole = Role::factory()->create(['slug' => 'patient']);
    $doctorRole = Role::factory()->create(['slug' => 'doctor']);

    $patient = User::factory()->create(['role_id' => $patientRole->id]);
    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);

    $conversation = Conversation::create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ]);

    $message = Message::create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
        'message' => 'Original text',
    ]);

    Sanctum::actingAs($patient);

    $response = $this->putJson('/api/messages/'.$message->uuid, [
        'message' => 'Updated text via websockets',
    ]);

    $response->assertSuccessful();

    Event::assertDispatched(ChatMessageEdited::class, function ($event) use ($conversation, $message) {
        return $event->conversationUuid === $conversation->uuid &&
               $event->message['id'] === $message->uuid &&
               $event->message['message'] === 'Updated text via websockets';
    });
});

test('deleting a message dispatches ChatMessageDeleted broadcast event', function () {
    Event::fake([ChatMessageDeleted::class]);

    $patientRole = Role::factory()->create(['slug' => 'patient']);
    $doctorRole = Role::factory()->create(['slug' => 'doctor']);

    $patient = User::factory()->create(['role_id' => $patientRole->id]);
    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);

    $conversation = Conversation::create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ]);

    $message = Message::create([
        'conversation_id' => $conversation->id,
        'sender_id' => $patient->id,
        'message' => 'Message to be deleted',
    ]);

    Sanctum::actingAs($patient);

    $response = $this->deleteJson('/api/messages/'.$message->uuid);

    $response->assertSuccessful();

    Event::assertDispatched(ChatMessageDeleted::class, function ($event) use ($conversation, $message) {
        return $event->conversationUuid === $conversation->uuid &&
               $event->messageUuid === $message->uuid;
    });
});
