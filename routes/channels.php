<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::routes(['prefix' => 'api', 'middleware' => ['auth:sanctum']]);

Broadcast::channel('user.{uuid}', function (User $user, string $uuid) {
    return (string) $user->uuid === (string) $uuid;
}, ['guards' => ['sanctum', 'web']]);

Broadcast::channel('chat.conversation.{uuid}', function (User $user, string $uuid) {
    $conversation = Conversation::where('uuid', $uuid)->first();
    if (! $conversation) {
        return false;
    }

    $isDoctor = $conversation->doctor_id === $user->id;
    $isPatient = $conversation->patient_id === $user->id;
    $isSecretary = $user->role?->slug === 'secretary' && $conversation->doctor_id === $user->doctor_id;

    return $isDoctor || $isPatient || $isSecretary;
}, ['guards' => ['sanctum', 'web']]);

Broadcast::channel('admin.moderation', function (User $user) {
    return $user->role?->slug === 'admin';
}, ['guards' => ['sanctum', 'web']]);

Broadcast::channel('admin.ai-retraining', function (User $user) {
    return $user->role?->slug === 'admin';
}, ['guards' => ['sanctum', 'web']]);
