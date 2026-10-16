<?php

use App\Mail\GoogleLoginConfirmMail;
use App\Models\BlockedDevice;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->patientRole = Role::firstOrCreate(
        ['slug' => 'patient'],
        ['name' => 'Patient']
    );
    $this->doctorRole = Role::firstOrCreate(
        ['slug' => 'doctor'],
        ['name' => 'Doctor']
    );
});

test('redirect endpoint redirects to google oauth consent screen with basic profile and email scopes', function () {
    $response = $this->get('/api/auth/google/redirect');

    $response->assertStatus(302);
    $location = (string) $response->headers->get('Location');
    expect($location)->toContain('accounts.google.com')
        ->and($location)->not->toContain('birthday.read')
        ->and($location)->not->toContain('gender.read');
});

test('redirect endpoint sets oauth role cookie and state when requested as doctor', function () {
    $response = $this->get('/api/auth/google/redirect?role=doctor');

    $response->assertStatus(302);
    $location = (string) $response->headers->get('Location');
    expect($location)->toContain('accounts.google.com')
        ->and($location)->toContain('state=');
    $response->assertPlainCookie('da_oauth_role', 'doctor');
});

test('callback for new user creates patient and sends confirmation email', function () {
    Mail::fake();

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('google-unique-123456');
    $socialiteUser->shouldReceive('getEmail')->andReturn('googleuser@example.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Jane Google');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'mock-google-token';
    $socialiteUser->user = [
        'given_name' => 'Jane',
        'family_name' => 'Google',
    ];

    $provider = Mockery::mock('Laravel\Socialite\Two\GoogleProvider');
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $response = $this->withUnencryptedCookie('da_device_id', 'device-xyz-123')
        ->get('/api/auth/google/callback');

    $response->assertStatus(302);
    $location = (string) $response->headers->get('Location');
    expect($location)->toContain('http://localhost:3000/auth/check-email?email=googleuser%40example.com');

    $createdUser = User::where('google_id', 'google-unique-123456')->first();
    expect($createdUser)->not->toBeNull()
        ->and($createdUser->email)->toBe('googleuser@example.com')
        ->and($createdUser->first_name)->toBe('Jane')
        ->and($createdUser->last_name)->toBe('Google')
        ->and($createdUser->account_status)->toBe('active')
        ->and($createdUser->role_id)->toBe($this->patientRole->id);

    Mail::assertSent(GoogleLoginConfirmMail::class, function ($mail) {
        return $mail->user->email === 'googleuser@example.com';
    });
});

test('callback creates doctor when doctor role is requested via state', function () {
    Mail::fake();

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('google-doctor-123456');
    $socialiteUser->shouldReceive('getEmail')->andReturn('doctorgoogle@example.com');
    $socialiteUser->shouldReceive('getName')->andReturn('John Doctor');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'mock-google-token';
    $socialiteUser->user = [
        'given_name' => 'John',
        'family_name' => 'Doctor',
    ];

    $provider = Mockery::mock('Laravel\Socialite\Two\GoogleProvider');
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $state = base64_encode(json_encode(['role' => 'doctor']));

    $response = $this->withUnencryptedCookie('da_device_id', 'device-doc-123')
        ->get("/api/auth/google/callback?state={$state}");

    $response->assertStatus(302);
    $location = (string) $response->headers->get('Location');
    expect($location)->toContain('http://localhost:3000/auth/check-email?email=doctorgoogle%40example.com');

    Mail::assertSent(GoogleLoginConfirmMail::class, function ($mail) {
        return $mail->user->email === 'doctorgoogle@example.com';
    });

    $createdDoctor = User::where('google_id', 'google-doctor-123456')->first();
    expect($createdDoctor)->not->toBeNull()
        ->and($createdDoctor->email)->toBe('doctorgoogle@example.com')
        ->and($createdDoctor->first_name)->toBe('John')
        ->and($createdDoctor->last_name)->toBe('Doctor')
        ->and($createdDoctor->role_id)->toBe($this->doctorRole->id)
        ->and($createdDoctor->prc_number)->toBeNull();
});

test('callback for existing user on recognized device skips email confirmation and issues auth code', function () {
    $existingUser = User::factory()->create([
        'email' => 'recognized@example.com',
        'role_id' => $this->patientRole->id,
        'google_id' => 'google-recog-999',
        'device_token' => 'recognized-device-token',
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('google-recog-999');
    $socialiteUser->shouldReceive('getEmail')->andReturn('recognized@example.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Recognized User');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'mock-token';
    $socialiteUser->user = [];

    $provider = Mockery::mock('Laravel\Socialite\Two\GoogleProvider');
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $response = $this->withUnencryptedCookie('da_device_id', 'recognized-device-token')
        ->get('/api/auth/google/callback');

    $response->assertStatus(302);
    $location = (string) $response->headers->get('Location');
    expect($location)->toContain('http://localhost:3000/auth/callback?code=');
});

test('confirming email with signed route authorizes device and redirects with code', function () {
    $user = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'device_token' => 'old-device',
    ]);

    $signedUrl = URL::temporarySignedRoute(
        'auth.google.confirm',
        now()->addMinutes(15),
        ['uuid' => $user->uuid, 'device_id' => 'new-authorized-device']
    );

    $response = $this->get($signedUrl);

    $response->assertStatus(302);
    $location = (string) $response->headers->get('Location');
    expect($location)->toContain('http://localhost:3000/auth/callback?code=');

    $user->refresh();
    expect($user->device_token)->toBe('new-authorized-device');
});

test('confirming email for doctor without prc details sets needs_prc_onboarding in code', function () {
    $doctor = User::factory()->create([
        'role_id' => $this->doctorRole->id,
        'prc_number' => null,
    ]);

    $signedUrl = URL::temporarySignedRoute(
        'auth.google.confirm',
        now()->addMinutes(15),
        ['uuid' => $doctor->uuid, 'device_id' => 'device-doc-confirm']
    );

    $response = $this->get($signedUrl);

    $response->assertStatus(302);
    $location = (string) $response->headers->get('Location');
    expect($location)->toContain('http://localhost:3000/auth/callback?code=');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $queryParams);
    $authCode = $queryParams['code'] ?? null;
    expect($authCode)->not->toBeNull();

    $cached = Cache::get("google_auth_code_{$authCode}");
    expect($cached)->not->toBeNull()
        ->and($cached['needs_prc_onboarding'])->toBeTrue();
});

test('exchanging auth code returns sanctum token and user payload', function () {
    $user = User::factory()->create([
        'role_id' => $this->patientRole->id,
    ]);

    Cache::put('google_auth_code_testcode123', [
        'user_id' => $user->id,
        'device_id' => 'some-device',
    ], now()->addMinutes(2));

    $response = $this->postJson('/api/auth/google/exchange', [
        'code' => 'testcode123',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'token',
            'user' => ['uuid', 'first_name', 'last_name', 'email', 'role', 'account_status'],
        ]);

    expect(Cache::has('google_auth_code_testcode123'))->toBeFalse();
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
