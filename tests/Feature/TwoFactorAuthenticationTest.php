<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
});

test('authenticated user can start 2fa setup and receives secret and svg qr code', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $user = User::factory()->create([
        'role_id' => $doctorRole->id,
        'account_status' => 'active',
    ]);

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/two-factor/enable');

    $response->assertStatus(200);
    $response->assertJsonStructure(['secret', 'qr_code_svg', 'otpauth_url']);
    expect($response->json('secret'))->not->toBeEmpty()
        ->and($response->json('qr_code_svg'))->toContain('<svg')
        ->and($user->fresh()->two_factor_secret)->not->toBeNull()
        ->and($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

test('confirming 2fa with invalid code fails with 422', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $user = User::factory()->create([
        'role_id' => $doctorRole->id,
        'account_status' => 'active',
    ]);

    Sanctum::actingAs($user);

    $this->postJson('/api/two-factor/enable');

    $response = $this->postJson('/api/two-factor/confirm', [
        'code' => '000000',
    ]);

    $response->assertStatus(422);
    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

test('confirming 2fa with valid totp code activates 2fa and returns recovery codes', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $user = User::factory()->create([
        'role_id' => $doctorRole->id,
        'account_status' => 'active',
    ]);

    Sanctum::actingAs($user);

    $enableRes = $this->postJson('/api/two-factor/enable');
    $secret = $enableRes->json('secret');

    $validCode = (new Google2FA)->getCurrentOtp($secret);

    $confirmRes = $this->postJson('/api/two-factor/confirm', [
        'code' => $validCode,
    ]);

    $confirmRes->assertStatus(200);
    $confirmRes->assertJsonStructure(['message', 'recovery_codes']);
    expect($confirmRes->json('recovery_codes'))->toHaveCount(8)
        ->and($user->fresh()->hasTwoFactorEnabled())->toBeTrue()
        ->and($user->fresh()->two_factor_recovery_codes)->toHaveCount(8);
});

test('user login with 2fa enabled returns challenge instead of token', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $g2fa = new Google2FA;
    $secret = $g2fa->generateSecretKey();

    $user = User::factory()->create([
        'email' => 'twofa.doc@dermassist.com',
        'password' => Hash::make('secret123'),
        'role_id' => $doctorRole->id,
        'account_status' => 'active',
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => ['TEST1-RECOV', 'TEST2-RECOV'],
    ]);

    $loginRes = $this->postJson('/api/login', [
        'email' => 'twofa.doc@dermassist.com',
        'password' => 'secret123',
    ]);

    $loginRes->assertStatus(200);
    $loginRes->assertJsonStructure(['two_factor_required', 'challenge']);
    expect($loginRes->json('two_factor_required'))->toBeTrue()
        ->and($loginRes->json('token'))->toBeNull();

    $challenge = $loginRes->json('challenge');

    // Solve challenge with valid TOTP code
    $validCode = $g2fa->getCurrentOtp($secret);

    $challengeRes = $this->postJson('/api/two-factor/challenge', [
        'challenge' => $challenge,
        'code' => $validCode,
    ]);

    $challengeRes->assertStatus(200);
    $challengeRes->assertJsonStructure(['token', 'user']);
    expect($challengeRes->json('user.two_factor_enabled'))->toBeTrue()
        ->and($challengeRes->json('token'))->not->toBeEmpty();
});

test('challenge with valid recovery code issues token and consumes recovery code', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $g2fa = new Google2FA;
    $secret = $g2fa->generateSecretKey();

    $user = User::factory()->create([
        'email' => 'recov.doc@dermassist.com',
        'password' => Hash::make('secret123'),
        'role_id' => $doctorRole->id,
        'account_status' => 'active',
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => ['RC111-AAAAA', 'RC222-BBBBB'],
    ]);

    $loginRes = $this->postJson('/api/login', [
        'email' => 'recov.doc@dermassist.com',
        'password' => 'secret123',
    ]);

    $challenge = $loginRes->json('challenge');

    // Solve using recovery code
    $challengeRes = $this->postJson('/api/two-factor/challenge', [
        'challenge' => $challenge,
        'recovery_code' => 'rc111-aaaaa', // Case-insensitive check
    ]);

    $challengeRes->assertStatus(200);
    expect($challengeRes->json('token'))->not->toBeEmpty()
        ->and($user->fresh()->two_factor_recovery_codes)->toEqual(['RC222-BBBBB']);
});

test('disabling 2fa requires valid password and clears 2fa fields', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $g2fa = new Google2FA;
    $secret = $g2fa->generateSecretKey();

    $user = User::factory()->create([
        'password' => Hash::make('my-password-123'),
        'role_id' => $doctorRole->id,
        'account_status' => 'active',
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => ['RC111-AAAAA'],
    ]);

    Sanctum::actingAs($user);

    // Wrong password fails
    $failRes = $this->deleteJson('/api/two-factor', [
        'password' => 'wrong-password',
    ]);
    $failRes->assertStatus(422);
    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();

    // Correct password succeeds
    $successRes = $this->deleteJson('/api/two-factor', [
        'password' => 'my-password-123',
    ]);
    $successRes->assertStatus(200);
    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse()
        ->and($user->fresh()->two_factor_secret)->toBeNull()
        ->and($user->fresh()->two_factor_recovery_codes)->toBeNull();
});

test('regenerating recovery codes requires valid password', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $g2fa = new Google2FA;
    $secret = $g2fa->generateSecretKey();

    $user = User::factory()->create([
        'password' => Hash::make('pass-word-999'),
        'role_id' => $doctorRole->id,
        'account_status' => 'active',
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => ['OLD1-CODE'],
    ]);

    Sanctum::actingAs($user);

    $regenRes = $this->postJson('/api/two-factor/recovery-codes', [
        'password' => 'pass-word-999',
    ]);

    $regenRes->assertStatus(200);
    expect($regenRes->json('recovery_codes'))->toHaveCount(8)
        ->and($regenRes->json('recovery_codes'))->not->toContain('OLD1-CODE')
        ->and($user->fresh()->two_factor_recovery_codes)->toHaveCount(8);
});
