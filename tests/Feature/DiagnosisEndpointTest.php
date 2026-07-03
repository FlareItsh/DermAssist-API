<?php

use App\Models\Diagnosis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

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

    // 3. Create a fake image file
    $file = UploadedFile::fake()->image('test-skin-lesion.jpg', 600, 600);

    // 4. Hit the endpoint
    $response = $this->postJson('/api/diagnose', [
        'image' => $file,
        'user_uuid' => '123e4567-e89b-12d3-a456-426614174000',
    ]);

    // 5. Assert the response
    $response->assertSuccessful();
    $response->assertJsonPath('label', 'Acne');

    // 6. Assert the file was stored locally
    $diagnosis = Diagnosis::latest()->first();
    Storage::disk('public')->assertExists($diagnosis->image_path);
});
