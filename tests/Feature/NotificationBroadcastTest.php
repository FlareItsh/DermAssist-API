<?php

use App\Events\DoctorVerificationStatusUpdated;
use App\Models\DoctorVerification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('updating doctor verification status dispatches DoctorVerificationStatusUpdated event', function () {
    Event::fake([DoctorVerificationStatusUpdated::class]);

    $adminRole = Role::factory()->create(['slug' => 'admin']);
    $doctorRole = Role::factory()->create(['slug' => 'doctor']);

    $admin = User::factory()->create(['role_id' => $adminRole->id]);
    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);

    $verification = DoctorVerification::create([
        'user_id' => $doctor->id,
        'prc_number' => '1234567',
        'id_photo_path' => 'licenses/test.jpg',
        'status' => 'pending',
    ]);

    Sanctum::actingAs($admin);

    $response = $this->putJson('/api/verifications/'.$verification->uuid, [
        'status' => 'verified',
    ]);

    $response->assertSuccessful();

    Event::assertDispatched(DoctorVerificationStatusUpdated::class, function ($event) use ($doctor, $verification) {
        return $event->doctorUuid === $doctor->uuid &&
               $event->status === 'verified' &&
               $event->verificationUuid === $verification->uuid;
    });
});

test('admin cannot verify doctor if prc number or id photo is missing', function () {
    $adminRole = Role::factory()->create(['slug' => 'admin']);
    $doctorRole = Role::factory()->create(['slug' => 'doctor']);

    $admin = User::factory()->create(['role_id' => $adminRole->id]);
    $doctor = User::factory()->create(['role_id' => $doctorRole->id]);

    $verificationWithoutPhoto = DoctorVerification::create([
        'user_id' => $doctor->id,
        'prc_number' => '1234567',
        'id_photo_path' => null,
        'status' => 'pending',
    ]);

    Sanctum::actingAs($admin);

    $response = $this->putJson('/api/verifications/'.$verificationWithoutPhoto->uuid, [
        'status' => 'verified',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    $verificationWithoutPrc = DoctorVerification::create([
        'user_id' => $doctor->id,
        'prc_number' => null,
        'id_photo_path' => 'licenses/doc.jpg',
        'status' => 'pending',
    ]);

    $response2 = $this->putJson('/api/verifications/'.$verificationWithoutPrc->uuid, [
        'status' => 'verified',
    ]);

    $response2->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});
