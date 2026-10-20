<?php

use App\Events\ScanAppealUpdated;
use App\Models\Appeal;
use App\Models\Diagnosis;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
});

test('doctor submitting an appeal immediately updates clinical diagnosis record', function () {
    $doctor = User::factory()->create();
    $patient = User::factory()->create();

    $diagnosis = Diagnosis::create([
        'user_uuid' => $doctor->uuid,
        'doctor_uuid' => $doctor->uuid,
        'patient_uuid' => $patient->uuid,
        'image_path' => 'diagnoses/sample_lesion.jpg',
        'label' => 'Acne',
        'confidence' => 0.85,
        'probabilities' => ['Acne' => 0.85, 'Herpes' => 0.10, 'Eczema' => 0.05],
        'status' => 'completed',
    ]);

    $response = $this->actingAs($doctor)->postJson('/api/appeals', [
        'user_uuid' => $doctor->uuid,
        'diagnosis_uuid' => $diagnosis->uuid,
        'diagnosis_label' => 'Acne',
        'suggested_label' => 'Herpes',
        'description' => 'Lesions present as clustered vesicles characteristic of HSV.',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.diagnosis_label', 'Acne')
        ->assertJsonPath('data.suggested_label', 'Herpes');

    // Clinical diagnosis record must immediately reflect doctor's verified diagnosis
    $diagnosis->refresh();
    expect($diagnosis->label)->toBe('Herpes')
        ->and($diagnosis->is_out_of_scope)->toBeFalse();

    $this->assertDatabaseHas('appeals', [
        'diagnosis_id' => $diagnosis->id,
        'suggested_label' => 'Herpes',
        'status' => 'pending',
    ]);
});

test('submitting an appeal with candidate disease updates out_of_scope flag immediately', function () {
    $doctor = User::factory()->create();

    $diagnosis = Diagnosis::create([
        'user_uuid' => $doctor->uuid,
        'doctor_uuid' => $doctor->uuid,
        'image_path' => 'diagnoses/sample_psoriasis.jpg',
        'label' => 'Eczema',
        'confidence' => 0.72,
        'probabilities' => ['Eczema' => 0.72, 'Acne' => 0.18, 'Herpes' => 0.10],
        'status' => 'completed',
    ]);

    $response = $this->actingAs($doctor)->postJson('/api/appeals', [
        'user_uuid' => $doctor->uuid,
        'diagnosis_uuid' => $diagnosis->uuid,
        'diagnosis_label' => 'Eczema',
        'suggested_label' => 'Psoriasis',
        'description' => 'Silvery scaly plaques with clear demarcation.',
    ]);

    $response->assertStatus(201);

    $diagnosis->refresh();
    expect($diagnosis->label)->toBe('Psoriasis')
        ->and($diagnosis->is_out_of_scope)->toBeTrue()
        ->and($diagnosis->out_of_scope_category)->toBe('psoriasis');
});

test('listing appeals returns diagnosis details and status filtering', function () {
    $doctor = User::factory()->create();
    $diagnosis = Diagnosis::create([
        'user_uuid' => $doctor->uuid,
        'doctor_uuid' => $doctor->uuid,
        'image_path' => 'diagnoses/test_img.jpg',
        'label' => 'Herpes',
        'confidence' => 0.90,
        'probabilities' => ['Herpes' => 0.90, 'Acne' => 0.10],
        'status' => 'completed',
    ]);

    $appeal = Appeal::create([
        'user_id' => $doctor->id,
        'diagnosis_id' => $diagnosis->id,
        'diagnosis_label' => 'Acne',
        'suggested_label' => 'Herpes',
        'description' => 'Disagreed with initial model output.',
        'status' => 'pending',
    ]);

    $response = $this->actingAs($doctor)->getJson('/api/appeals?status=pending');

    $response->assertStatus(200)
        ->assertJsonFragment(['suggested_label' => 'Herpes']);

    $firstItem = collect($response->json('data'))->firstWhere('uuid', $appeal->uuid);
    expect($firstItem)->not->toBeNull()
        ->and($firstItem['diagnosis'])->not->toBeNull()
        ->and($firstItem['diagnosis']['label'])->toBe('Herpes')
        ->and($firstItem['diagnosis']['image_url'])->toContain('diagnoses/test_img.jpg');
});

test('admin can resolve an appeal to ingest scan for AI retraining', function () {
    Storage::fake('public');
    $admin = User::factory()->create();
    $doctor = User::factory()->create();

    Storage::disk('public')->put('diagnoses/scan_to_retrain.jpg', 'fake-image-bytes');

    $diagnosis = Diagnosis::create([
        'user_uuid' => $doctor->uuid,
        'doctor_uuid' => $doctor->uuid,
        'image_path' => 'diagnoses/scan_to_retrain.jpg',
        'label' => 'Herpes',
        'confidence' => 0.88,
        'probabilities' => ['Herpes' => 0.88, 'Acne' => 0.12],
        'status' => 'completed',
    ]);

    $appeal = Appeal::create([
        'user_id' => $doctor->id,
        'diagnosis_id' => $diagnosis->id,
        'diagnosis_label' => 'Acne',
        'suggested_label' => 'Herpes',
        'status' => 'pending',
    ]);

    $response = $this->actingAs($admin)->patchJson("/api/appeals/{$appeal->uuid}/resolve", [
        'resolution_notes' => 'Confirmed by specialist. Adding to Herpes training images.',
        'update_dataset' => true,
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'resolved');

    $appeal->refresh();
    expect($appeal->status)->toBe('resolved')
        ->and($appeal->resolved_at)->not->toBeNull();

    $diagnosis->refresh();
    expect($diagnosis->contributed_to_dataset)->toBeTrue();
});

test('admin can restore a resolved appeal to pending', function () {
    $admin = User::factory()->create();
    $doctor = User::factory()->create();

    $appeal = Appeal::create([
        'user_id' => $doctor->id,
        'diagnosis_label' => 'Acne',
        'suggested_label' => 'Eczema',
        'status' => 'resolved',
        'resolved_at' => now(),
    ]);

    $response = $this->actingAs($admin)->patchJson("/api/appeals/{$appeal->uuid}/restore");

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'pending');

    $appeal->refresh();
    expect($appeal->status)->toBe('pending')
        ->and($appeal->resolved_at)->toBeNull();
});

test('admin can reject an appeal with rejection notes', function () {
    $admin = User::factory()->create();
    $doctor = User::factory()->create();

    $appeal = Appeal::create([
        'user_id' => $doctor->id,
        'diagnosis_label' => 'Acne',
        'suggested_label' => 'Herpes',
        'status' => 'pending',
    ]);

    $response = $this->actingAs($admin)->patchJson("/api/appeals/{$appeal->uuid}/reject", [
        'resolution_notes' => 'Image resolution too low to definitively establish HSV vesicle patterns.',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.resolution_notes', 'Image resolution too low to definitively establish HSV vesicle patterns.');

    $appeal->refresh();
    expect($appeal->status)->toBe('rejected')
        ->and($appeal->resolved_at)->not->toBeNull()
        ->and($appeal->resolution_notes)->toBe('Image resolution too low to definitively establish HSV vesicle patterns.');
});

test('appeals list filters by rejected status', function () {
    $doctor = User::factory()->create();

    $pending = Appeal::create([
        'user_id' => $doctor->id,
        'diagnosis_label' => 'Acne',
        'suggested_label' => 'Herpes',
        'status' => 'pending',
    ]);

    $rejected = Appeal::create([
        'user_id' => $doctor->id,
        'diagnosis_label' => 'Acne',
        'suggested_label' => 'Eczema',
        'status' => 'rejected',
        'resolved_at' => now(),
        'resolution_notes' => 'Clinical description conflicts with visual scan.',
    ]);

    $response = $this->actingAs($doctor)->getJson('/api/appeals?status=rejected');

    $response->assertStatus(200);
    $uuids = collect($response->json('data'))->pluck('uuid')->all();
    expect($uuids)->toContain($rejected->uuid)
        ->and($uuids)->not->toContain($pending->uuid);
});

test('doctor can submit a re-appeal after previous appeal was rejected', function () {
    $doctor = User::factory()->create();
    $diagnosis = Diagnosis::create([
        'user_uuid' => $doctor->uuid,
        'doctor_uuid' => $doctor->uuid,
        'image_path' => 'diagnoses/test_reappeal.jpg',
        'label' => 'Acne',
        'confidence' => 0.80,
        'probabilities' => ['Acne' => 0.80],
        'status' => 'completed',
    ]);

    // First appeal rejected
    $firstAppeal = Appeal::create([
        'user_id' => $doctor->id,
        'diagnosis_id' => $diagnosis->id,
        'diagnosis_label' => 'Acne',
        'suggested_label' => 'Herpes',
        'description' => 'First attempt',
        'status' => 'rejected',
        'resolved_at' => now(),
        'resolution_notes' => 'Please provide more details on vesicle groupings.',
    ]);

    // Doctor submits second appeal (re-appeal)
    $response = $this->actingAs($doctor)->postJson('/api/appeals', [
        'user_uuid' => $doctor->uuid,
        'diagnosis_uuid' => $diagnosis->uuid,
        'diagnosis_label' => 'Herpes',
        'suggested_label' => 'Herpes Simplex',
        'description' => 'Close-up analysis shows umbilicated vesicles with erythematous base.',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.suggested_label', 'Herpes Simplex');

    expect(Appeal::where('diagnosis_id', $diagnosis->id)->count())->toBe(2);
});

test('appeal lifecycle actions broadcast ScanAppealUpdated to admin.moderation', function () {
    Event::fake([ScanAppealUpdated::class]);

    $adminRole = Role::where('slug', 'admin')->first();
    $admin = User::factory()->create(['role_id' => $adminRole->id]);
    $doctor = User::factory()->create();

    $diagnosis = Diagnosis::create([
        'user_uuid' => $doctor->uuid,
        'doctor_uuid' => $doctor->uuid,
        'image_path' => 'diagnoses/test_ws.jpg',
        'label' => 'Acne',
        'confidence' => 0.85,
        'probabilities' => ['Acne' => 0.85],
        'status' => 'completed',
    ]);

    // 1. Submit appeal
    $response = $this->actingAs($doctor)->postJson('/api/appeals', [
        'user_uuid' => $doctor->uuid,
        'diagnosis_uuid' => $diagnosis->uuid,
        'diagnosis_label' => 'Acne',
        'suggested_label' => 'Herpes',
        'description' => 'Disputed label',
    ]);
    $response->assertStatus(201);
    $appealUuid = $response->json('data.uuid');

    Event::assertDispatched(ScanAppealUpdated::class, function ($event) use ($doctor) {
        $channels = collect($event->broadcastOn())->map->name;

        return $event->action === 'submitted'
            && $channels->contains('private-admin.moderation')
            && $channels->contains('private-user.'.$doctor->uuid);
    });

    // 2. Resolve appeal
    $this->actingAs($admin)->patchJson("/api/appeals/{$appealUuid}/resolve", [
        'update_dataset' => false,
    ])->assertStatus(200);

    Event::assertDispatched(ScanAppealUpdated::class, function ($event) use ($doctor) {
        $channels = collect($event->broadcastOn())->map->name;

        return $event->action === 'resolved'
            && $channels->contains('private-admin.moderation')
            && $channels->contains('private-user.'.$doctor->uuid);
    });

    // 3. Restore appeal
    $this->actingAs($admin)->patchJson("/api/appeals/{$appealUuid}/restore")
        ->assertStatus(200);

    Event::assertDispatched(ScanAppealUpdated::class, function ($event) {
        return $event->action === 'restored';
    });

    // 4. Reject appeal
    $this->actingAs($admin)->patchJson("/api/appeals/{$appealUuid}/reject", [
        'resolution_notes' => 'Rejected test',
    ])->assertStatus(200);

    Event::assertDispatched(ScanAppealUpdated::class, function ($event) {
        return $event->action === 'rejected';
    });
});
