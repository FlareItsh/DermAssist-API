<?php

use App\Models\Diagnosis;
use App\Models\Role;
use App\Models\User;
use App\Service\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('purges contributed dataset scans and resets diagnosis state when patient revokes consent', function () {
    Storage::fake('public');

    $patientRole = Role::firstOrCreate(['slug' => 'patient'], ['name' => 'Patient']);
    $patient = User::factory()->create([
        'role_id' => $patientRole->id,
        'consent_dataset' => true,
    ]);

    // Setup an initial image file in public disk
    $imagePath = 'diagnoses/sample_lesion.jpg';
    Storage::disk('public')->put($imagePath, 'fake-binary-content');

    // Create a diagnosis marked as contributed to dataset
    $datasetPath = 'dataset/acne/sample_lesion.jpg';
    Storage::disk('public')->put($datasetPath, 'fake-binary-content');

    $diagnosis = Diagnosis::create([
        'uuid' => (string) Str::uuid(),
        'patient_uuid' => $patient->uuid,
        'user_uuid' => $patient->uuid,
        'image_path' => $imagePath,
        'label' => 'Acne',
        'confidence' => 0.92,
        'probabilities' => ['Acne' => 0.92],
        'status' => 'completed',
        'patient_consented_dataset' => true,
        'contributed_to_dataset' => true,
        'contributed_at' => now(),
    ]);

    Storage::disk('public')->assertExists($datasetPath);

    // Patient updates profile and sets consent_dataset = false
    $userService = app(UserService::class);
    $userService->updateUser($patient->uuid, [
        'consent_dataset' => false,
    ]);

    // Assert dataset file was pruned from dataset directory
    Storage::disk('public')->assertMissing($datasetPath);

    // Assert diagnosis record status was updated to reflect revocation
    $diagnosis->refresh();
    expect($diagnosis->contributed_to_dataset)->toBeFalse();
    expect($diagnosis->patient_consented_dataset)->toBeFalse();
    expect($diagnosis->contributed_at)->toBeNull();
});
