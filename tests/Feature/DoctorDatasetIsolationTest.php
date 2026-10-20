<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');

    $this->doctorRole = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    $this->adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $this->patientRole = Role::firstOrCreate(['slug' => 'patient'], ['name' => 'Patient']);

    $this->doctorA = User::factory()->create(['role_id' => $this->doctorRole->id]);
    $this->doctorB = User::factory()->create(['role_id' => $this->doctorRole->id]);
    $this->admin = User::factory()->create(['role_id' => $this->adminRole->id]);
});

test('doctor only sees their own scans and direct uploads in dataset query', function () {
    // 1. Doctor A uploads an image to Acne
    Sanctum::actingAs($this->doctorA);
    $uploadResponse = $this->postJson('/api/dataset', [
        'image' => UploadedFile::fake()->image('doctor_a_acne.jpg'),
        'category' => 'Acne',
    ])->assertStatus(201);

    $doctorAUrl = $uploadResponse->json('url');

    // 2. Doctor B uploads an image to Acne
    Sanctum::actingAs($this->doctorB);
    $this->postJson('/api/dataset', [
        'image' => UploadedFile::fake()->image('doctor_b_acne.jpg'),
        'category' => 'Acne',
    ])->assertStatus(201);

    // 3. Doctor A queries the dataset with scope=doctor
    Sanctum::actingAs($this->doctorA);
    $responseA = $this->getJson('/api/dataset?scope=doctor')
        ->assertSuccessful()
        ->json();

    $acneCatA = collect($responseA)->firstWhere('category', 'acne');
    expect($acneCatA)->not->toBeNull();
    expect($acneCatA['images'])->toHaveCount(1);
    expect($acneCatA['images'][0])->toBe($doctorAUrl);

    // 4. Doctor B queries the dataset with scope=doctor
    Sanctum::actingAs($this->doctorB);
    $responseB = $this->getJson('/api/dataset?scope=doctor')
        ->assertSuccessful()
        ->json();

    $acneCatB = collect($responseB)->firstWhere('category', 'acne');
    expect($acneCatB)->not->toBeNull();
    expect($acneCatB['images'])->toHaveCount(1);
    expect($acneCatB['images'][0])->not->toBe($doctorAUrl);
});

test('doctor dataset excludes static global benchmark images', function () {
    // Put a static file into storage/app/public/dataset/acne/global_benchmark.jpg
    Storage::disk('public')->put('dataset/acne/global_benchmark.jpg', 'fake content');

    // Doctor with no scans should see 0 images in category even though static file exists
    Sanctum::actingAs($this->doctorA);
    $response = $this->getJson('/api/dataset?scope=doctor')
        ->assertSuccessful()
        ->json();

    $acneCat = collect($response)->firstWhere('category', 'acne');
    expect($acneCat)->not->toBeNull();
    expect($acneCat['images'])->toHaveCount(0);
    expect($acneCat['total'])->toBe(0);

    // Admin should see the global benchmark file
    Sanctum::actingAs($this->admin);
    $adminResponse = $this->getJson('/api/dataset')
        ->assertSuccessful()
        ->json();

    $adminAcne = collect($adminResponse)->firstWhere('category', 'acne');
    expect($adminAcne)->not->toBeNull();
    expect($adminAcne['total'])->toBeGreaterThanOrEqual(1);
});

test('doctor cannot delete another doctors dataset scan', function () {
    // Doctor A uploads an image
    Sanctum::actingAs($this->doctorA);
    $uploadResponse = $this->postJson('/api/dataset', [
        'image' => UploadedFile::fake()->image('doctor_a_scan.jpg'),
        'category' => 'Eczema',
    ])->assertStatus(201);

    $url = $uploadResponse->json('url');

    // Doctor B tries to delete Doctor A's image
    Sanctum::actingAs($this->doctorB);
    $this->deleteJson('/api/dataset', ['url' => $url])
        ->assertStatus(403)
        ->assertJson(['error' => 'Unauthorized: You can only remove your own clinical scans.']);

    // Doctor A can delete their own image
    Sanctum::actingAs($this->doctorA);
    $this->deleteJson('/api/dataset', ['url' => $url])
        ->assertSuccessful()
        ->assertJson(['message' => 'Image deleted successfully']);
});

test('doctor download only zips that doctors scans', function () {
    // Doctor A uploads an image
    Sanctum::actingAs($this->doctorA);
    $this->postJson('/api/dataset', [
        'image' => UploadedFile::fake()->image('doctor_a_scan.jpg'),
        'category' => 'Herpes',
    ])->assertStatus(201);

    // Doctor A can download their category zip
    $responseA = $this->get('/api/dataset/download?category=herpes&scope=doctor');
    $responseA->assertSuccessful();

    // Doctor B has no Herpes scans, so download returns 404
    Sanctum::actingAs($this->doctorB);
    $this->getJson('/api/dataset/download?category=herpes&scope=doctor')
        ->assertStatus(404)
        ->assertJson(['error' => 'No images found to download']);
});
