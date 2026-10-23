<?php

use App\Models\Appointment;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Service\ScanQuotaService;
use App\Service\UserService;
use Carbon\Carbon;
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

    // Standard roles created
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

test('quota is NOT deducted if AI server fails or rejects non-skin photo', function () {
    $patientRole = Role::where('slug', 'patient')->first();
    $patient = User::factory()->create([
        'role_id' => $patientRole->id,
        'account_status' => 'active',
    ]);
    Sanctum::actingAs($patient);

    // Mock AI failure with 422 NOT_SKIN_IMAGE
    Http::fake([
        '*' => Http::response([
            'detail' => [
                'code' => 'NOT_SKIN_IMAGE',
                'message' => 'The uploaded image does not appear to be a skin photo.',
            ],
        ], 422),
    ]);

    $this->postJson('/api/diagnose', ['image' => fakeSkinFile()])->assertStatus(500);

    // Quota remains 0 used, 5 remaining, no window started
    $quota = $this->getJson('/api/scan-quota')->assertOk()->json('data');
    expect($quota['used'])->toBe(0);
    expect($quota['remaining'])->toBe(5);
    expect($quota['resets_at'])->toBeNull();
});

test('unsubscribed doctor has the same daily quota as normal patients and can scan', function () {
    fakeAiSuccess();
    $doctorRole = Role::where('slug', 'doctor')->first();
    $doctor = User::factory()->create([
        'role_id' => $doctorRole->id,
        'account_status' => 'active',
    ]);
    Sanctum::actingAs($doctor);

    // Doctor without subscription can scan (previously was 403)
    $res = $this->postJson('/api/diagnose', ['image' => fakeSkinFile()])->assertSuccessful();
    expect($res->json('scan_quota.used'))->toBe(1);
    expect($res->json('scan_quota.remaining'))->toBe(4);
});

test('doctor-created patient receives 7 days of unlimited scans and returns to default quota when expired', function () {
    fakeAiSuccess();
    $doctorRole = Role::where('slug', 'doctor')->first();
    $doctor = User::factory()->create([
        'role_id' => $doctorRole->id,
        'account_status' => 'active',
    ]);

    $userService = app(UserService::class);
    $res = $userService->createDoctorRegisteredPatient([
        'firstName' => 'John',
        'lastName' => 'Doe',
        'email' => 'johndoe@test.com',
        'password' => 'Secret123!',
    ], $doctor);

    $patient = User::where('email', 'johndoe@test.com')->first();
    expect($patient->hasUnlimitedScans())->toBeTrue();

    Sanctum::actingAs($patient);

    // Should report unlimited scans
    $quota = $this->getJson('/api/scan-quota')->assertOk()->json('data');
    expect($quota['is_unlimited'])->toBeTrue();
    expect($quota['source'])->toBe('doctor_care');

    // Perform 10 scans without getting blocked
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/diagnose', ['image' => fakeSkinFile()])->assertSuccessful();
    }

    // Fast-forward past 7 days (e.g. 8 days) -> expires and returns to normal default quota
    $this->travel(8)->days();
    $expiredQuota = $this->getJson('/api/scan-quota')->assertOk()->json('data');
    expect($expiredQuota['is_unlimited'])->toBeFalse();
    expect($expiredQuota['limit'])->toBe(5);
    expect($expiredQuota['remaining'])->toBe(5);
});

test('completed appointment grants patient 7 days of unlimited scans', function () {
    $patientRole = Role::where('slug', 'patient')->first();
    $doctorRole = Role::where('slug', 'doctor')->first();

    $patient = User::factory()->create(['role_id' => $patientRole->id, 'account_status' => 'active']);
    $doctor = User::factory()->create(['role_id' => $doctorRole->id, 'account_status' => 'active']);

    expect($patient->hasUnlimitedScans())->toBeFalse();

    $appt = Appointment::create([
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => 'confirmed',
        'scheduled_at' => now(),
    ]);

    // Mark appointment completed
    $appt->update(['status' => 'completed']);

    $patient->refresh();
    expect($patient->hasUnlimitedScans())->toBeTrue();

    Sanctum::actingAs($patient);
    $quota = $this->getJson('/api/scan-quota')->assertOk()->json('data');
    expect($quota['is_unlimited'])->toBeTrue();
    expect($quota['source'])->toBe('doctor_care');
});

test('subscribed doctor receives custom daily scan limit from plan or unlimited if null', function () {
    $doctorRole = Role::where('slug', 'doctor')->first();
    $doctor = User::factory()->create(['role_id' => $doctorRole->id, 'account_status' => 'active']);

    $scanFeature = Feature::firstOrCreate(['code' => 'can_execute_scan'], ['name' => 'Scan', 'is_active' => true]);

    $plan = Plan::create([
        'name' => 'Clinic Pro',
        'slug' => 'clinic-pro',
        'tier_type' => 'individual',
        'price_monthly' => 1999,
        'price_annual' => 19990,
        'daily_scan_limit' => 20,
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
    expect($quota['is_unlimited'])->toBeFalse();
    expect($quota['limit'])->toBe(20);
    expect($quota['source'])->toBe('subscription');
});

test('admin can view and update system-wide default daily scan quota dynamically', function () {
    $adminRole = Role::where('slug', 'admin')->first();
    $admin = User::factory()->create(['role_id' => $adminRole->id, 'account_status' => 'active']);

    $patientRole = Role::where('slug', 'patient')->first();
    $patient = User::factory()->create(['role_id' => $patientRole->id, 'account_status' => 'active']);

    // Default is 5
    Sanctum::actingAs($admin);
    $getRes = $this->getJson('/api/admin/settings/default-scan-quota')->assertOk();
    expect($getRes->json('data.default_daily_quota'))->toBe(5);

    // Update to 10
    $updateRes = $this->postJson('/api/admin/settings/default-scan-quota', [
        'default_daily_quota' => 10,
    ])->assertOk();
    expect($updateRes->json('data.default_daily_quota'))->toBe(10);

    // Patient now sees new default limit of 10
    Sanctum::actingAs($patient);
    $patientQuota = $this->getJson('/api/scan-quota')->assertOk()->json('data');
    expect($patientQuota['limit'])->toBe(10);
    expect($patientQuota['remaining'])->toBe(10);

    // Non-admin cannot change default quota
    $this->postJson('/api/admin/settings/default-scan-quota', [
        'default_daily_quota' => 25,
    ])->assertStatus(403);
});

test('admin can view and update separate dynamic grace periods for appointments and doctor created accounts', function () {
    $adminRole = Role::where('slug', 'admin')->first();
    $admin = User::factory()->create(['role_id' => $adminRole->id, 'account_status' => 'active']);

    $doctorRole = Role::where('slug', 'doctor')->first();
    $doctor = User::factory()->create(['role_id' => $doctorRole->id, 'account_status' => 'active']);

    $patientRole = Role::where('slug', 'patient')->first();
    $patient = User::factory()->create(['role_id' => $patientRole->id, 'account_status' => 'active']);

    // Check defaults (7 days each)
    Sanctum::actingAs($admin);
    $getRes = $this->getJson('/api/admin/settings/care-grace-periods')->assertOk();
    expect($getRes->json('data.appointment_unlimited_days'))->toBe(7);
    expect($getRes->json('data.doctor_created_unlimited_days'))->toBe(7);

    // Update appointment to 14 days, doctor-created to 30 days
    $updateRes = $this->postJson('/api/admin/settings/care-grace-periods', [
        'appointment_unlimited_days' => 14,
        'doctor_created_unlimited_days' => 30,
    ])->assertOk();
    expect($updateRes->json('data.appointment_unlimited_days'))->toBe(14);
    expect($updateRes->json('data.doctor_created_unlimited_days'))->toBe(30);

    // Appointment completion grants 14 days
    $appt = Appointment::create([
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => 'confirmed',
        'scheduled_at' => now(),
    ]);
    $appt->update(['status' => 'completed']);
    $patient->refresh();
    expect((int) round(now()->diffInDays($patient->unlimited_scans_until)))->toBe(14);

    // Doctor registered patient gets 30 days
    $userService = app(UserService::class);
    $userService->createDoctorRegisteredPatient([
        'firstName' => 'Grace',
        'lastName' => 'Patient',
        'email' => 'grace.patient@example.com',
        'password' => 'Pass123!Secure',
        'contactNumber' => '+639123456789',
    ], $doctor);
    $docPatient = User::where('email', 'grace.patient@example.com')->firstOrFail();
    expect((int) round(now()->diffInDays($docPatient->unlimited_scans_until)))->toBe(30);

    // Dynamic resolution on existing patient when setting changes
    Sanctum::actingAs($admin);
    $this->postJson('/api/admin/settings/care-grace-periods', [
        'doctor_created_unlimited_days' => 5,
    ])->assertOk();

    $quotaService = app(ScanQuotaService::class);
    $resolved = $quotaService->getQuota($docPatient);
    expect($resolved['is_unlimited'])->toBeTrue();
    expect((int) round(now()->diffInDays(Carbon::parse($resolved['unlimited_until']))))->toBe(5);

    // Non-admin cannot change settings
    Sanctum::actingAs($patient);
    $this->postJson('/api/admin/settings/care-grace-periods', [
        'appointment_unlimited_days' => 3,
    ])->assertStatus(403);
});
