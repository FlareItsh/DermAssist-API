<?php

use App\Models\Appointment;
use App\Models\DoctorVerification;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Service\ScanQuotaService;
use App\Service\UserService;
use Database\Seeders\ScanPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');

    // Create standard roles
    Role::firstOrCreate(['slug' => 'patient'], ['name' => 'Patient']);
    Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);

    // Seed scan policy defaults
    $this->seed(ScanPolicySeeder::class);
});

function fakeAiSuccess(): void
{
    Http::fake([
        '*' => Http::response([
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
}

function fakeSkinFile(): UploadedFile
{
    $jpegBytes = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');

    return UploadedFile::fake()->createWithContent('skin.jpg', $jpegBytes);
}

test('guest cannot access diagnose endpoint or scan quota endpoint', function () {
    $this->postJson('/api/diagnose', [
        'image' => fakeSkinFile(),
    ])->assertStatus(401);

    $this->getJson('/api/scan-quota')->assertStatus(401);
});

test('regular patient gets default 5 scans and 24h countdown starts on first scan', function () {
    fakeAiSuccess();
    $patientRole = Role::where('slug', 'patient')->first();
    $patient = User::factory()->create([
        'role_id' => $patientRole->id,
        'account_status' => 'active',
    ]);
    Sanctum::actingAs($patient);

    // Initial check: 5 remaining, no countdown yet
    $initial = $this->getJson('/api/scan-quota')->assertOk()->json('data');
    expect($initial['is_unlimited'])->toBeFalse();
    expect($initial['limit'])->toBe(5);
    expect($initial['used'])->toBe(0);
    expect($initial['remaining'])->toBe(5);
    expect($initial['resets_at'])->toBeNull();

    // 1st scan
    $res1 = $this->postJson('/api/diagnose', ['image' => fakeSkinFile()])->assertSuccessful();
    $quota1 = $res1->json('scan_quota');
    expect($quota1['used'])->toBe(1);
    expect($quota1['remaining'])->toBe(4);
    expect($quota1['resets_at'])->not->toBeNull();

    // Do 4 more scans (total 5)
    for ($i = 2; $i <= 5; $i++) {
        $this->postJson('/api/diagnose', ['image' => fakeSkinFile()])->assertSuccessful();
    }

    // 6th scan should be rejected with 429 SCAN_LIMIT_REACHED
    $blocked = $this->postJson('/api/diagnose', ['image' => fakeSkinFile()]);
    $blocked->assertStatus(429);
    $blocked->assertJsonPath('code', 'SCAN_LIMIT_REACHED');
    expect($blocked->json('scan_quota.remaining'))->toBe(0);

    // Advance time by 24 hours -> quota resets
    $this->travel(24)->hours();
    $afterReset = $this->getJson('/api/scan-quota')->assertOk()->json('data');
    expect($afterReset['used'])->toBe(0);
    expect($afterReset['remaining'])->toBe(5);

    // Can scan again after reset
    $this->postJson('/api/diagnose', ['image' => fakeSkinFile()])->assertSuccessful();
});

test('unverified doctor cannot scan and receives 403 error', function () {
    fakeAiSuccess();
    $doctorRole = Role::where('slug', 'doctor')->first();
    $doctor = User::factory()->create([
        'role_id' => $doctorRole->id,
        'account_status' => 'pending',
    ]);
    Sanctum::actingAs($doctor);

    // Quota endpoint indicates verification required
    $quota = $this->getJson('/api/scan-quota')->assertOk()->json('data');
    expect($quota['can_scan'])->toBeFalse();
    expect($quota['limit'])->toBe(0);
    expect($quota['source'])->toBe('doctor_unverified');

    // Attempting to diagnose is blocked with 403 DOCTOR_VERIFICATION_REQUIRED
    $res = $this->postJson('/api/diagnose', ['image' => fakeSkinFile()]);
    $res->assertStatus(403);
    $res->assertJsonPath('code', 'DOCTOR_VERIFICATION_REQUIRED');
});

test('verified doctor receives 14-day trial with 10 scans per day', function () {
    fakeAiSuccess();
    $doctorRole = Role::where('slug', 'doctor')->first();
    $doctor = User::factory()->create([
        'role_id' => $doctorRole->id,
        'account_status' => 'verified',
    ]);
    DoctorVerification::create([
        'user_id' => $doctor->id,
        'prc_number' => '1234567',
        'id_photo_path' => 'photos/prc.jpg',
        'status' => DoctorVerification::STATUS_VERIFIED,
    ]);
    Sanctum::actingAs($doctor);

    $quota = $this->getJson('/api/scan-quota')->assertOk()->json('data');
    expect($quota['can_scan'])->toBeTrue();
    expect($quota['limit'])->toBe(10);
    expect($quota['source'])->toBe('doctor_trial');

    $res = $this->postJson('/api/diagnose', ['image' => fakeSkinFile()])->assertSuccessful();
    expect($res->json('scan_quota.used'))->toBe(1);
    expect($res->json('scan_quota.remaining'))->toBe(9);
});

test('expired doctor trial drops to 3 scans per day', function () {
    fakeAiSuccess();
    $doctorRole = Role::where('slug', 'doctor')->first();
    $doctor = User::factory()->create([
        'role_id' => $doctorRole->id,
        'account_status' => 'verified',
    ]);
    DoctorVerification::create([
        'user_id' => $doctor->id,
        'prc_number' => '1234567',
        'id_photo_path' => 'photos/prc.jpg',
        'status' => DoctorVerification::STATUS_VERIFIED,
    ]);
    Sanctum::actingAs($doctor);

    // Fast-forward 15 days (trial is 14 days)
    $this->travel(15)->days();

    $quota = $this->getJson('/api/scan-quota')->assertOk()->json('data');
    expect($quota['can_scan'])->toBeTrue();
    expect($quota['limit'])->toBe(3);
    expect($quota['source'])->toBe('doctor_unsubscribed');
});

test('subscribed doctor receives unlimited scans when plan limit is null or plan quota when set', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $doctor = User::factory()->create([
        'role_id' => $doctorRole->id,
        'account_status' => 'verified',
    ]);

    $scanFeature = Feature::firstOrCreate(['code' => 'can_execute_scan'], ['name' => 'Scan', 'is_active' => true]);

    $plan = Plan::create([
        'name' => 'Individual Doctor Plan',
        'slug' => 'individual-doctor-plan',
        'tier_type' => 'individual',
        'price_monthly' => 999,
        'price_annual' => 9990,
        'daily_scan_limit' => null, // Unlimited
        'is_active' => true,
    ]);
    $plan->planFeatures()->attach($scanFeature->id, ['is_included' => true]);

    Subscription::create([
        'user_id' => $doctor->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'plan_version' => 1,
        'plan_snapshot' => $plan->createSnapshot(),
        'starts_at' => now(),
        'ends_at' => now()->addMonth(),
    ]);

    Sanctum::actingAs($doctor);
    $quota = $this->getJson('/api/scan-quota')->assertOk()->json('data');
    expect($quota['is_unlimited'])->toBeTrue();
    expect($quota['source'])->toBe('subscription');
});

test('doctor-created patient receives 15 scans per day for 30 days and reverts to 5 scans per day after expiration', function () {
    fakeAiSuccess();
    $doctorRole = Role::where('slug', 'doctor')->first();
    $doctor = User::factory()->create([
        'role_id' => $doctorRole->id,
        'account_status' => 'verified',
    ]);

    $userService = app(UserService::class);
    $userService->createDoctorRegisteredPatient([
        'firstName' => 'John',
        'lastName' => 'Doe',
        'email' => 'johndoe@test.com',
        'password' => 'Secret123!',
    ], $doctor);

    $patient = User::where('email', 'johndoe@test.com')->first();
    Sanctum::actingAs($patient);

    // Initial check: 15 scans/day under doctor_care
    $quota = $this->getJson('/api/scan-quota')->assertOk()->json('data');
    expect($quota['limit'])->toBe(15);
    expect($quota['source'])->toBe('doctor_care');

    // Fast-forward 31 days -> reverts to standard 5 scans per day
    $this->travel(31)->days();
    $expiredQuota = $this->getJson('/api/scan-quota')->assertOk()->json('data');
    expect($expiredQuota['limit'])->toBe(5);
    expect($expiredQuota['source'])->toBe('free');
});

test('completed appointment grants patient 15 scans per day for 30 days and does not stack with doctor-created status', function () {
    $patientRole = Role::where('slug', 'patient')->first();
    $doctorRole = Role::where('slug', 'doctor')->first();

    $patient = User::factory()->create([
        'role_id' => $patientRole->id,
        'account_status' => 'active',
        'is_doctor_registered' => true,
        'created_at' => now()->subDays(20), // 10 days remaining of initial 30 days
    ]);
    $doctor = User::factory()->create(['role_id' => $doctorRole->id, 'account_status' => 'verified']);

    $appt = Appointment::create([
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => 'confirmed',
        'scheduled_at' => now(),
    ]);

    // Mark appointment completed
    $appt->update(['status' => 'completed']);

    Sanctum::actingAs($patient);
    $quota = $this->getJson('/api/scan-quota')->assertOk()->json('data');
    // Does NOT stack: limit is still 15
    expect($quota['limit'])->toBe(15);
    expect($quota['source'])->toBe('doctor_care');

    // 25 days later: doctor_created expired (20 + 25 = 45 > 30), but appointment is only 25 days old (25 < 30)
    $this->travel(25)->days();
    $midQuota = $this->getJson('/api/scan-quota')->assertOk()->json('data');
    expect($midQuota['limit'])->toBe(15);
    expect($midQuota['source'])->toBe('doctor_care');

    // 10 more days later (35 days after appointment) -> now completely expired -> reverts to 5
    $this->travel(10)->days();
    $finalQuota = $this->getJson('/api/scan-quota')->assertOk()->json('data');
    expect($finalQuota['limit'])->toBe(5);
    expect($finalQuota['source'])->toBe('free');
});

test('admin can view and update all dynamic scan quota policies', function () {
    $adminRole = Role::where('slug', 'admin')->first();
    $admin = User::factory()->create(['role_id' => $adminRole->id, 'account_status' => 'active']);

    Sanctum::actingAs($admin);

    // Fetch initial policies
    $res = $this->getJson('/api/admin/settings/scan-quota-policies')->assertOk();
    expect($res->json('data.doctor_trial_days'))->toBe(14);
    expect($res->json('data.doctor_trial_daily_scans'))->toBe(10);
    expect($res->json('data.doctor_unsubscribed_daily_scans'))->toBe(3);
    expect($res->json('data.patient_default_daily_scans'))->toBe(5);
    expect($res->json('data.patient_care_daily_scans'))->toBe(15);
    expect($res->json('data.patient_care_duration_days'))->toBe(30);

    // Update policies
    $updateRes = $this->postJson('/api/admin/settings/scan-quota-policies', [
        'doctor_trial_days' => 21,
        'doctor_trial_daily_scans' => 20,
        'doctor_unsubscribed_daily_scans' => 5,
        'patient_default_daily_scans' => 8,
        'patient_care_daily_scans' => 25,
        'patient_care_duration_days' => 60,
    ])->assertOk();

    expect($updateRes->json('data.doctor_trial_days'))->toBe(21);
    expect($updateRes->json('data.doctor_trial_daily_scans'))->toBe(20);
    expect($updateRes->json('data.doctor_unsubscribed_daily_scans'))->toBe(5);
    expect($updateRes->json('data.patient_default_daily_scans'))->toBe(8);
    expect($updateRes->json('data.patient_care_daily_scans'))->toBe(25);
    expect($updateRes->json('data.patient_care_duration_days'))->toBe(60);

    // Non-admin cannot update policies
    $patientRole = Role::where('slug', 'patient')->first();
    $patient = User::factory()->create(['role_id' => $patientRole->id, 'account_status' => 'active']);
    Sanctum::actingAs($patient);
    $this->postJson('/api/admin/settings/scan-quota-policies', [
        'patient_default_daily_scans' => 100,
    ])->assertStatus(403);
});
