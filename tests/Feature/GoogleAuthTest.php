<?php

use App\Models\BlockedDevice;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->patientRole = Role::firstOrCreate(
        ['slug' => 'patient'],
        ['name' => 'Patient']
    );
});

test('redirect endpoint redirects to google oauth consent screen', function () {
    $response = $this->get('/api/auth/google/redirect');

    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toContain('accounts.google.com');
});

test('callback creates new patient and redirects to frontend with token', function () {
    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('google-unique-123456');
    $socialiteUser->shouldReceive('getEmail')->andReturn('googleuser@example.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Jane Google');
    $socialiteUser->user = [
        'given_name' => 'Jane',
        'family_name' => 'Google',
    ];

    $provider = Mockery::mock('Laravel\Socialite\Two\GoogleProvider');
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $response = $this->get('/api/auth/google/callback');

    $response->assertStatus(302);
    $location = $response->headers->get('Location');
    expect($location)->toContain('http://localhost:3000/auth/callback?token=');

    $createdUser = User::where('google_id', 'google-unique-123456')->first();
    expect($createdUser)->not->toBeNull()
        ->and($createdUser->email)->toBe('googleuser@example.com')
        ->and($createdUser->first_name)->toBe('Jane')
        ->and($createdUser->last_name)->toBe('Google')
        ->and($createdUser->account_status)->toBe('active')
        ->and($createdUser->email_verified_at)->not->toBeNull()
        ->and($createdUser->role_id)->toBe($this->patientRole->id);
});

test('callback links google_id if existing user registers with same email', function () {
    $existingUser = User::factory()->create([
        'email' => 'existing@example.com',
        'role_id' => $this->patientRole->id,
        'google_id' => null,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('google-linked-789');
    $socialiteUser->shouldReceive('getEmail')->andReturn('existing@example.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Existing User');
    $socialiteUser->user = [
        'given_name' => 'Existing',
        'family_name' => 'User',
    ];

    $provider = Mockery::mock('Laravel\Socialite\Two\GoogleProvider');
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $response = $this->get('/api/auth/google/callback');

    $response->assertStatus(302);
    $existingUser->refresh();
    expect($existingUser->google_id)->toBe('google-linked-789');
});

test('callback rejects blocked devices', function () {
    BlockedDevice::create([
        'device_id' => 'blocked-device-abc',
        'reason' => 'Bot activity',
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('google-blocked-111');
    $socialiteUser->shouldReceive('getEmail')->andReturn('blocked@example.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Blocked User');

    $provider = Mockery::mock('Laravel\Socialite\Two\GoogleProvider');
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $response = $this->withUnencryptedCookie('da_device_id', 'blocked-device-abc')
        ->get('/api/auth/google/callback');

    $response->assertStatus(403);
    expect($response->json('is_device_blocked'))->toBeTrue();
});
