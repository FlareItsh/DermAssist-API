<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    Role::firstOrCreate(['slug' => 'patient'], ['name' => 'Patient']);
});

test('admin can retrieve model and dataset stats', function () {
    $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $admin = User::factory()->create(['role_id' => $role->id]);

    Http::fake([
        '*/model/stats' => Http::response([
            'total_baseline_images' => 15719,
            'classes' => ['Acne' => 6837, 'Eczema' => 6715, 'Herpes' => 2167],
            'active_architecture' => 'swin_transformer',
            'models_available' => ['best_model_swin_transformer.pth'],
        ], 200),
    ]);

    $response = $this->actingAs($admin)->getJson('/api/admin/model/stats');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'gathered_dataset' => ['total', 'by_category'],
            'ai_service' => ['total_baseline_images', 'classes', 'active_architecture', 'models_available'],
        ])
        ->assertJsonPath('ai_service.total_baseline_images', 15719)
        ->assertJsonPath('ai_service.active_architecture', 'swin_transformer');
});

test('admin can trigger model retraining', function () {
    $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $admin = User::factory()->create(['role_id' => $role->id]);

    Http::fake([
        '*/train/start' => Http::response([
            'message' => 'Retraining started for swin_transformer',
            'status' => 'started',
            'epochs' => 5,
        ], 200),
    ]);

    $response = $this->actingAs($admin)->postJson('/api/admin/model/retrain', [
        'architecture' => 'swin_transformer',
        'epochs' => 5,
        'sync_dataset' => true,
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('status', 'started')
        ->assertJsonPath('epochs', 5);
});

test('admin can trigger ensemble retraining', function () {
    $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $admin = User::factory()->create(['role_id' => $role->id]);

    Http::fake([
        '*/train/start' => Http::response([
            'message' => 'Retraining started for ensemble',
            'status' => 'started',
            'epochs' => 3,
        ], 200),
    ]);

    $response = $this->actingAs($admin)->postJson('/api/admin/model/retrain', [
        'architecture' => 'ensemble',
        'epochs' => 3,
        'sync_dataset' => true,
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('status', 'started')
        ->assertJsonPath('epochs', 3);
});

test('admin can query live training status', function () {
    $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $admin = User::factory()->create(['role_id' => $role->id]);

    Http::fake([
        '*/train/status' => Http::response([
            'status' => 'training',
            'progress' => 45.5,
            'architecture' => 'swin_transformer',
            'current_epoch' => 2,
            'total_epochs' => 5,
            'current_batch' => 25,
            'total_batches' => 50,
            'train_loss' => 0.2451,
            'train_acc' => 91.2,
            'val_loss' => 0.2612,
            'val_acc' => 89.8,
            'baseline_val_acc' => 88.5,
            'best_val_acc' => 89.8,
            'model_promoted' => false,
            'message' => 'Training Epoch 2/5',
            'eta_seconds' => 120,
            'elapsed_seconds' => 65,
            'logs' => ['[11:00:00] Training Epoch 2/5'],
            'history' => [
                'train_loss' => [0.35],
                'train_acc' => [86.0],
                'val_loss' => [0.32],
                'val_acc' => [87.5],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($admin)->getJson('/api/admin/model/retrain/status');

    $response->assertStatus(200)
        ->assertJsonPath('status', 'training')
        ->assertJsonPath('progress', 45.5)
        ->assertJsonPath('current_epoch', 2)
        ->assertJsonPath('train_acc', 91.2);
});

test('admin can cancel active training', function () {
    $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $admin = User::factory()->create(['role_id' => $role->id]);

    Http::fake([
        '*/train/cancel' => Http::response([
            'message' => 'Cancellation request submitted.',
            'status' => 'cancelling',
        ], 200),
    ]);

    $response = $this->actingAs($admin)->postJson('/api/admin/model/retrain/cancel');

    $response->assertStatus(200)
        ->assertJsonPath('status', 'cancelling');
});
