<?php

use App\Mail\ResetPasswordMail;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
});

test('user can request password reset link and email is sent', function () {
    Mail::fake();

    $role = Role::where('slug', 'patient')->first();
    $user = User::factory()->create([
        'email' => 'patient@dermassist.com',
        'role_id' => $role->id,
        'account_status' => 'active',
    ]);

    $response = $this->postJson('/api/forgot-password', [
        'email' => 'patient@dermassist.com',
    ]);

    $response->assertStatus(200);
    $response->assertJson([
        'message' => 'A secure password reset link has been dispatched to patient@dermassist.com.',
    ]);

    Mail::assertSent(ResetPasswordMail::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email) &&
            str_contains($mail->resetUrl, '/auth/reset-password?token=') &&
            str_contains($mail->resetUrl, 'email='.urlencode($user->email));
    });

    expect(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeTrue();
});

test('forgot password trims and normalizes email casing', function () {
    Mail::fake();

    $role = Role::where('slug', 'doctor')->first();
    $user = User::factory()->create([
        'email' => 'doctor@dermassist.com',
        'role_id' => $role->id,
        'account_status' => 'active',
    ]);

    $response = $this->postJson('/api/forgot-password', [
        'email' => '   DOCTOR@DERMASSIST.COM   ',
    ]);

    $response->assertStatus(200);
    Mail::assertSent(ResetPasswordMail::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email);
    });
});

test('forgot password returns neutral 200 without email enumeration when account does not exist', function () {
    Mail::fake();

    $response = $this->postJson('/api/forgot-password', [
        'email' => 'nonexistent@dermassist.com',
    ]);

    $response->assertStatus(200);
    $response->assertJson([
        'message' => 'If an account is associated with nonexistent@dermassist.com, a password reset link has been dispatched.',
    ]);

    Mail::assertNothingSent();
});

test('forgot password blocks disabled accounts from resetting password', function () {
    Mail::fake();

    $role = Role::where('slug', 'patient')->first();
    $user = User::factory()->create([
        'email' => 'disabled@dermassist.com',
        'role_id' => $role->id,
        'account_status' => 'disabled',
    ]);

    $response = $this->postJson('/api/forgot-password', [
        'email' => 'disabled@dermassist.com',
    ]);

    $response->assertStatus(403);
    $response->assertJson([
        'message' => 'This account is currently disabled. Please contact clinic administration or support.',
    ]);

    Mail::assertNothingSent();
});

test('forgot password validates required and valid email address', function () {
    $response = $this->postJson('/api/forgot-password', [
        'email' => 'invalid-email',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['email']);
});
