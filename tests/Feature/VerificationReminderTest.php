<?php

use App\Mail\VerificationReminderMail;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->patientRole = Role::firstOrCreate(
        ['slug' => 'patient'],
        ['name' => 'Patient']
    );
});

test('send verification reminders dispatches emails at 24h and 6h marks', function () {
    Mail::fake();

    // User A: 20 hours remaining, reminder not sent yet (should get 24h reminder)
    $userA = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'account_status' => 'pending_verification',
        'verification_token' => Str::random(40),
        'verification_deadline' => now()->addHours(20),
        'verification_reminders_sent' => 0,
    ]);

    // User B: 4 hours remaining, reminder count = 1 (should get 6h reminder)
    $userB = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'account_status' => 'pending_verification',
        'verification_token' => Str::random(40),
        'verification_deadline' => now()->addHours(4),
        'verification_reminders_sent' => 1,
    ]);

    // User C: 40 hours remaining, reminder not due yet
    $userC = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'account_status' => 'pending_verification',
        'verification_token' => Str::random(40),
        'verification_deadline' => now()->addHours(40),
        'verification_reminders_sent' => 0,
    ]);

    // User D: Already active / verified
    $userD = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'account_status' => 'active',
        'verification_deadline' => now()->addHours(10),
        'verification_reminders_sent' => 0,
    ]);

    $this->artisan('app:send-verification-reminders')->assertSuccessful();

    Mail::assertSent(VerificationReminderMail::class, function ($mail) use ($userA) {
        return $mail->user->id === $userA->id;
    });

    Mail::assertSent(VerificationReminderMail::class, function ($mail) use ($userB) {
        return $mail->user->id === $userB->id;
    });

    Mail::assertNotSent(VerificationReminderMail::class, function ($mail) use ($userC) {
        return $mail->user->id === $userC->id;
    });

    Mail::assertNotSent(VerificationReminderMail::class, function ($mail) use ($userD) {
        return $mail->user->id === $userD->id;
    });

    $userA->refresh();
    expect($userA->verification_reminders_sent)->toBe(1);

    $userB->refresh();
    expect($userB->verification_reminders_sent)->toBe(2);
});
