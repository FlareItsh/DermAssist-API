<?php

use App\Models\Diagnosis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('can diagnose a skin lesion image', function () {
    // 1. Fake the local storage so we don't actually save images to the disk
    Storage::fake('public');

    // 2. Fake the HTTP response from the Python AI server
    Http::fake([
        config('services.ai.url').'/predict' => Http::response([
            'label' => 'Acne',
            'confidence' => 0.95,
            'all_probabilities' => [
                'Acne' => 0.95,
                'Eczema' => 0.03,
                'Herpes' => 0.02,
            ],
            'device' => 'cpu',
            'architecture' => 'resnet50',
            'image_type' => 'skin',
        ], 200),
    ]);

    // 3. Create a fake image file with valid 1x1 JPEG binary bytes (no GD dependency)
    $jpegBytes = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
    $file = UploadedFile::fake()->createWithContent('test-skin-lesion.jpg', $jpegBytes);

    $user = User::factory()->create(['account_status' => 'active']);
    Sanctum::actingAs($user);

    // 4. Hit the endpoint
    $response = $this->postJson('/api/diagnose', [
        'image' => $file,
        'user_uuid' => $user->uuid,
    ]);

    // 5. Assert the response
    $response->assertSuccessful();
    $response->assertJsonPath('label', 'Acne');

    // 6. Assert the file was stored locally
    $diagnosis = Diagnosis::where('user_uuid', $user->uuid)->first();
    Storage::disk('public')->assertExists($diagnosis->image_path);
});
