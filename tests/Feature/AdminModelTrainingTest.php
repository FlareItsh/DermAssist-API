<?php

use App\Models\Diagnosis;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

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

test('admin cannot trigger expansion retraining with insufficient dataset', function () {
    $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $admin = User::factory()->create(['role_id' => $role->id]);

    Storage::fake('public');

    $response = $this->actingAs($admin)->postJson('/api/admin/model/retrain', [
        'architecture' => 'ensemble',
        'epochs' => 5,
        'sync_dataset' => true,
        'expansion_disease' => 'psoriasis',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('required', 10)
        ->assertJsonPath('current', 0);
});

test('admin can trigger expansion retraining with candidate disease when dataset is sufficient', function () {
    $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $admin = User::factory()->create(['role_id' => $role->id]);

    Storage::fake('public');
    for ($i = 1; $i <= 10; $i++) {
        Storage::disk('public')->put("out_of_scope_dataset/psoriasis/test_{$i}.jpg", 'dummy image content');
    }

    Http::fake([
        '*/train/start' => function ($request) {
            $data = $request->data();
            expect($data)->toHaveKey('expansion_disease', 'psoriasis');

            return Http::response([
                'message' => 'Retraining started for ensemble (+psoriasis)',
                'status' => 'started',
                'epochs' => 5,
                'expansion_disease' => 'psoriasis',
            ], 200);
        },
    ]);

    $response = $this->actingAs($admin)->postJson('/api/admin/model/retrain', [
        'architecture' => 'ensemble',
        'epochs' => 5,
        'sync_dataset' => true,
        'expansion_disease' => 'psoriasis',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('status', 'started')
        ->assertJsonPath('expansion_disease', 'psoriasis');
});

test('admin can retrieve training images with tangibility status', function () {
    $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $admin = User::factory()->create(['role_id' => $role->id]);

    Storage::fake('public');

    // 1. Diagnosis scan not yet in local storage or raw (TANGIBLE)
    Storage::disk('public')->put('diagnoses/scan_new.jpg', 'fake content');
    $tangibleDiag = Diagnosis::create([
        'uuid' => (string) Str::uuid(),
        'label' => 'Acne',
        'confidence' => 0.95,
        'probabilities' => ['Acne' => 0.95, 'Eczema' => 0.03, 'Herpes' => 0.02],
        'image_path' => 'diagnoses/scan_new.jpg',
        'status' => 'completed',
        'patient_consented_dataset' => true,
        'contributed_to_dataset' => false,
    ]);

    // 2. Diagnosis scan already in local storage (INTANGIBLE)
    Storage::disk('public')->put('diagnoses/scan_stored.jpg', 'fake content');
    Storage::disk('public')->put('dataset/acne/scan_stored.jpg', 'fake content');
    $intangibleDiag = Diagnosis::create([
        'uuid' => (string) Str::uuid(),
        'label' => 'Acne',
        'confidence' => 0.92,
        'probabilities' => ['Acne' => 0.92, 'Eczema' => 0.05, 'Herpes' => 0.03],
        'image_path' => 'diagnoses/scan_stored.jpg',
        'status' => 'completed',
        'patient_consented_dataset' => true,
        'contributed_to_dataset' => true,
    ]);

    $response = $this->actingAs($admin)->getJson('/api/admin/model/training-images');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => ['uuid', 'filename', 'image_url', 'category', 'label', 'is_in_local_storage', 'is_in_raw', 'is_tangible', 'storage_status'],
            ],
            'summary' => ['total', 'tangible_count', 'in_local_storage_count', 'in_raw_count'],
        ]);

    $data = collect($response->json('data'));
    $tangibleItem = $data->firstWhere('uuid', $tangibleDiag->uuid);
    $intangibleItem = $data->firstWhere('uuid', $intangibleDiag->uuid);

    expect($tangibleItem['is_tangible'])->toBeTrue();
    expect($tangibleItem['storage_status'])->toBe('ready');

    expect($intangibleItem['is_tangible'])->toBeFalse();
    expect($intangibleItem['is_in_local_storage'])->toBeTrue();
});

test('admin can retrain with selected candidate image uuids', function () {
    $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $admin = User::factory()->create(['role_id' => $role->id]);

    Storage::fake('public');
    Storage::disk('public')->put('diagnoses/scan_to_train.jpg', 'sample scan photo content');

    $diag = Diagnosis::create([
        'uuid' => (string) Str::uuid(),
        'label' => 'Eczema',
        'confidence' => 0.88,
        'probabilities' => ['Acne' => 0.05, 'Eczema' => 0.88, 'Herpes' => 0.07],
        'image_path' => 'diagnoses/scan_to_train.jpg',
        'status' => 'completed',
        'patient_consented_dataset' => true,
        'contributed_to_dataset' => false,
    ]);

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
        'selected_image_uuids' => [$diag->uuid],
    ]);

    $response->assertStatus(200);

    // Assert that the diagnosis was promoted to local dataset storage
    expect(Storage::disk('public')->exists('dataset/eczema/scan_to_train.jpg'))->toBeTrue();
    expect($diag->fresh()->contributed_to_dataset)->toBeTrue();
});

test('admin can permanently delete candidate training images', function () {
    $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $admin = User::factory()->create(['role_id' => $role->id]);

    Storage::fake('public');
    Storage::disk('public')->put('diagnoses/delete_me.jpg', 'scan image data');
    Storage::disk('public')->put('dataset/acne/delete_me.jpg', 'dataset copy');

    $diag = Diagnosis::create([
        'uuid' => (string) Str::uuid(),
        'label' => 'Acne',
        'confidence' => 0.95,
        'probabilities' => ['Acne' => 0.95, 'Eczema' => 0.03, 'Herpes' => 0.02],
        'image_path' => 'diagnoses/delete_me.jpg',
        'status' => 'completed',
        'patient_consented_dataset' => true,
        'contributed_to_dataset' => true,
    ]);

    $response = $this->actingAs($admin)->deleteJson('/api/admin/model/training-images', [
        'uuids' => [$diag->uuid],
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'deleted_count' => 1,
        ]);

    expect(Storage::disk('public')->exists('diagnoses/delete_me.jpg'))->toBeFalse();
    expect(Storage::disk('public')->exists('dataset/acne/delete_me.jpg'))->toBeFalse();
    expect($diag->fresh()->image_path)->toBeNull();
    expect($diag->fresh()->contributed_to_dataset)->toBeFalse();
});

test('admin can sync dataset and promote webapp scans into local dataset storage', function () {
    $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $admin = User::factory()->create(['role_id' => $role->id]);

    Storage::fake('public');
    Storage::disk('public')->put('diagnoses/new_scan.jpg', 'new clinical scan');

    $diag = Diagnosis::create([
        'uuid' => (string) Str::uuid(),
        'label' => 'Herpes',
        'confidence' => 0.91,
        'probabilities' => ['Acne' => 0.04, 'Eczema' => 0.05, 'Herpes' => 0.91],
        'image_path' => 'diagnoses/new_scan.jpg',
        'status' => 'completed',
        'patient_consented_dataset' => true,
        'contributed_to_dataset' => false,
    ]);

    Http::fake([
        '*/dataset/sync' => Http::response([
            'message' => 'Synced 1 images from webapp storage.',
            'copied_count' => 1,
        ], 200),
    ]);

    $response = $this->actingAs($admin)->postJson('/api/admin/model/dataset/sync');

    $response->assertStatus(200)
        ->assertJson([
            'promoted_count' => 1,
            'raw_copied_count' => 1,
        ]);

    expect(Storage::disk('public')->exists('dataset/herpes/new_scan.jpg'))->toBeTrue();
    expect($diag->fresh()->contributed_to_dataset)->toBeTrue();
});

test('getTrainingImages includes is_synced and sync_status indicators', function () {
    $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $admin = User::factory()->create(['role_id' => $role->id]);

    Storage::fake('public');
    Storage::disk('public')->put('diagnoses/unsynced_scan.jpg', 'unsynced scan');
    Storage::disk('public')->put('dataset/acne/synced_photo.jpg', 'already in dataset');

    $unsynced = Diagnosis::create([
        'uuid' => (string) Str::uuid(),
        'label' => 'Acne',
        'confidence' => 0.90,
        'probabilities' => ['Acne' => 0.90, 'Eczema' => 0.05, 'Herpes' => 0.05],
        'image_path' => 'diagnoses/unsynced_scan.jpg',
        'status' => 'completed',
        'patient_consented_dataset' => true,
        'contributed_to_dataset' => false,
    ]);

    $response = $this->actingAs($admin)->getJson('/api/admin/model/training-images');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => ['uuid', 'is_synced', 'sync_status'],
            ],
            'summary' => ['synced_count', 'needs_sync_count'],
        ]);

    $data = collect($response->json('data'));
    $unsyncedItem = $data->firstWhere('uuid', $unsynced->uuid);

    expect($unsyncedItem['is_synced'])->toBeFalse();
    expect($unsyncedItem['sync_status'])->toBe('needs_sync');
});
