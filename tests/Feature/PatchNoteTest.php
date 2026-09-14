<?php

use App\Models\PatchNote;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    Role::firstOrCreate(['slug' => 'patient'], ['name' => 'Patient']);
});

test('admin can create and list patch notes', function () {
    $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $admin = User::factory()->create(['role_id' => $role->id]);

    $response = $this->actingAs($admin)->postJson('/api/admin/patch-notes', [
        'version' => 'v1.4.0',
        'title' => 'AI Retraining & UI Polish',
        'description' => 'Added new AI dataset retraining options for doctors and updated system patch notes.',
        'changes' => [
            'Added dataset contribute checkbox to diagnosis detail view',
            'Implemented patch notes system',
        ],
        'is_published' => true,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.version', 'v1.4.0')
        ->assertJsonPath('data.title', 'AI Retraining & UI Polish')
        ->assertJsonPath('data.is_published', true);

    $listResponse = $this->actingAs($admin)->getJson('/api/admin/patch-notes');
    $listResponse->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonCount(1, 'data');
});

test('admin can toggle publish status of a patch note', function () {
    $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $admin = User::factory()->create(['role_id' => $role->id]);

    $patchNote = PatchNote::factory()->create([
        'is_published' => false,
        'published_at' => null,
    ]);

    $response = $this->actingAs($admin)
        ->patchJson("/api/admin/patch-notes/{$patchNote->id}/toggle-publish");

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.is_published', true);

    expect($patchNote->fresh()->is_published)->toBeTrue();
});

test('users can retrieve only published patch notes', function () {
    $doctorRole = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);

    PatchNote::factory()->create([
        'title' => 'Published Note',
        'is_published' => true,
        'published_at' => now(),
    ]);

    PatchNote::factory()->create([
        'title' => 'Draft Note',
        'is_published' => false,
        'published_at' => null,
    ]);

    $response = $this->actingAs($doctor)->getJson('/api/patch-notes');

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Published Note');
});
