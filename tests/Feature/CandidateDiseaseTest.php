<?php

use App\Models\CandidateDisease;
use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
});

test('can list candidate diseases', function () {
    $doctor = User::factory()->create();

    $response = $this->actingAs($doctor)->getJson('/api/candidate-diseases');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'uuid', 'name', 'slug', 'category', 'is_default'],
            ],
        ]);

    expect(count($response->json('data')))->toBeGreaterThan(0);
});

test('can create a custom candidate disease', function () {
    $admin = User::factory()->create();

    $response = $this->actingAs($admin)->postJson('/api/candidate-diseases', [
        'name' => 'Granuloma Annulare',
        'category' => 'Inflammatory Condition',
        'description' => 'Ring-shaped lesions with central clearance.',
        'badge' => 'Custom',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Granuloma Annulare')
        ->assertJsonPath('data.is_default', false);

    $this->assertDatabaseHas('candidate_diseases', [
        'name' => 'Granuloma Annulare',
        'category' => 'Inflammatory Condition',
    ]);
});

test('can delete a custom candidate disease', function () {
    $admin = User::factory()->create();
    $custom = CandidateDisease::create([
        'name' => 'Test Disease',
        'slug' => 'test_disease_unique',
        'category' => 'Testing',
        'is_default' => false,
    ]);

    $response = $this->actingAs($admin)->deleteJson("/api/candidate-diseases/{$custom->uuid}");

    $response->assertStatus(200);
    $this->assertDatabaseMissing('candidate_diseases', ['id' => $custom->id]);
});

test('cannot delete default candidate disease', function () {
    $admin = User::factory()->create();
    $default = CandidateDisease::where('is_default', true)->first();

    if ($default) {
        $response = $this->actingAs($admin)->deleteJson("/api/candidate-diseases/{$default->uuid}");
        $response->assertStatus(422);
    }
});

test('cannot delete core model disease', function () {
    $admin = User::factory()->create();
    $core = CandidateDisease::where('is_core', true)->first();

    if ($core) {
        $response = $this->actingAs($admin)->deleteJson("/api/candidate-diseases/{$core->uuid}");
        $response->assertStatus(422)
            ->assertJsonPath('message', 'Core model and default system diseases cannot be deleted.');
    }
});
