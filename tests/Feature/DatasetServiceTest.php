<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

test('returns empty array when dataset directory does not exist', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/dataset')
        ->assertSuccessful()
        ->assertExactJson([]);
});

test('stores a single image and returns a valid url', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/dataset', [
            'image' => UploadedFile::fake()->image('scan.jpg'),
            'category' => 'Acne',
        ])
        ->assertStatus(201)
        ->assertJsonStructure(['message', 'url']);

    $url = $response->json('url');
    expect($url)->toBeString()->toContain('dataset/acne');
    // Regression guard: URL must not be double-wrapped (http://http://...)
    expect($url)->not->toContain('http://http');
    expect($url)->not->toContain('https://https');
    expect($url)->not->toBeEmpty();
});

test('stores multiple images and returns valid urls', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/dataset', [
            'images' => [
                UploadedFile::fake()->image('scan1.jpg'),
                UploadedFile::fake()->image('scan2.jpg'),
            ],
            'category' => 'Eczema',
        ])
        ->assertStatus(201)
        ->assertJsonStructure(['message', 'urls']);

    $urls = $response->json('urls');
    expect($urls)->toHaveCount(2);
    foreach ($urls as $url) {
        expect($url)->toBeString()->toContain('dataset/eczema');
        // Regression guard: URL must not be double-wrapped
        expect($url)->not->toContain('http://http');
        expect($url)->not->toContain('https://https');
        expect($url)->not->toBeEmpty();
    }
});

test('lists images in a dataset category after upload', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/dataset', [
        'image' => UploadedFile::fake()->image('scan.jpg'),
        'category' => 'Herpes',
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/dataset')
        ->assertSuccessful();

    $data = $response->json();
    expect($data)->toHaveCount(1);
    expect($data[0]['category'])->toBe('herpes');
    expect($data[0]['images'])->toHaveCount(1);
    // Regression guard: URL must not be double-wrapped
    $imgUrl = $data[0]['images'][0];
    expect($imgUrl)->not->toContain('http://http');
    expect($imgUrl)->not->toContain('https://https');
    expect($imgUrl)->toContain('dataset/herpes');
});

test('deletes an image from the dataset successfully', function () {
    $user = User::factory()->create();

    $url = $this->actingAs($user)
        ->postJson('/api/dataset', [
            'image' => UploadedFile::fake()->image('scan_delete.jpg'),
            'category' => 'Acne',
        ])
        ->assertStatus(201)
        ->json('url');

    $this->actingAs($user)
        ->deleteJson('/api/dataset', ['url' => $url])
        ->assertSuccessful()
        ->assertJson(['message' => 'Image deleted successfully']);

    // Confirm listing is now empty
    $this->actingAs($user)
        ->getJson('/api/dataset')
        ->assertSuccessful()
        ->assertExactJson([]);
});

test('returns 404 when deleting a non-existent image url', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->deleteJson('/api/dataset', ['url' => 'http://localhost/storage/dataset/acne/nonexistent.jpg'])
        ->assertNotFound();
});

test('paginates dataset images on the server side', function () {
    $user = User::factory()->create();

    // Upload 5 images into Acne
    for ($i = 1; $i <= 5; $i++) {
        $this->actingAs($user)->postJson('/api/dataset', [
            'image' => UploadedFile::fake()->image("acne_{$i}.jpg"),
            'category' => 'Acne',
        ])->assertStatus(201);
    }

    // Page 1 with per_page=2
    $responseP1 = $this->actingAs($user)
        ->getJson('/api/dataset?category=acne&page=1&per_page=2')
        ->assertSuccessful()
        ->json();

    expect($responseP1)->toHaveCount(1);
    expect($responseP1[0]['total'])->toBe(5);
    expect($responseP1[0]['current_page'])->toBe(1);
    expect($responseP1[0]['per_page'])->toBe(2);
    expect($responseP1[0]['last_page'])->toBe(3);
    expect($responseP1[0]['images'])->toHaveCount(2);

    // Page 3 with per_page=2 should have 1 remaining image
    $responseP3 = $this->actingAs($user)
        ->getJson('/api/dataset?category=acne&page=3&per_page=2')
        ->assertSuccessful()
        ->json();

    expect($responseP3[0]['current_page'])->toBe(3);
    expect($responseP3[0]['images'])->toHaveCount(1);

    // all=true should return all 5 images
    $responseAll = $this->actingAs($user)
        ->getJson('/api/dataset?category=acne&all=true')
        ->assertSuccessful()
        ->json();

    expect($responseAll[0]['images'])->toHaveCount(5);
});
