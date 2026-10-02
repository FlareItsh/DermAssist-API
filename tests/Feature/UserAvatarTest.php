<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('authenticated user can upload avatar via base64 and old avatar is replaced', function () {
    Storage::fake('public');

    $role = Role::firstOrCreate(['slug' => 'doctor'], [
        'name' => 'Doctor',
    ]);

    $user = User::factory()->create([
        'role_id' => $role->id,
        'first_name' => 'Beatriz',
        'last_name' => 'Cruz',
        'avatar_path' => null,
    ]);

    Sanctum::actingAs($user);

    // 1x1 transparent PNG data URI
    $base64Png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    $response = $this->putJson("/api/users/{$user->uuid}", [
        'avatar' => $base64Png,
    ]);

    $response->assertStatus(200);

    $user->refresh();
    expect($user->avatar_path)->not->toBeNull();
    Storage::disk('public')->assertExists($user->avatar_path);

    $firstAvatarPath = $user->avatar_path;

    // Upload a second avatar
    $secondPng = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    $response2 = $this->putJson("/api/users/{$user->uuid}", [
        'avatar' => $secondPng,
    ]);

    $response2->assertStatus(200);

    $user->refresh();
    expect($user->avatar_path)->not->toBe($firstAvatarPath);
    Storage::disk('public')->assertExists($user->avatar_path);
    Storage::disk('public')->assertMissing($firstAvatarPath);
});
