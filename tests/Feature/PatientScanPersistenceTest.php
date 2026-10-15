<?php

use App\Models\Diagnosis;
use App\Models\Role;
use App\Models\User;
use App\Repository\DiagnosisRepository;
use App\Repository\RecordRepository;
use App\Service\ClinicalReportPdfService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->patientRole = Role::firstOrCreate(['slug' => 'patient'], ['name' => 'Patient']);
    $this->doctorRole = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);

    $this->patient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'first_name' => 'Test',
        'last_name' => 'Patient',
    ]);
});

test('draft diagnoses are excluded from patient records list', function () {
    $recordRepo = app(RecordRepository::class);

    // Create a draft diagnosis
    $draftDiagnosis = Diagnosis::create([
        'uuid' => (string) Str::uuid(),
        'user_uuid' => $this->patient->uuid,
        'patient_uuid' => $this->patient->uuid,
        'label' => 'Acne',
        'confidence' => 0.88,
        'probabilities' => ['Acne' => 0.88],
        'image_path' => 'diagnoses/test-draft.jpg',
        'status' => 'draft',
    ]);

    // Create a completed diagnosis
    $completedDiagnosis = Diagnosis::create([
        'uuid' => (string) Str::uuid(),
        'user_uuid' => $this->patient->uuid,
        'patient_uuid' => $this->patient->uuid,
        'label' => 'Eczema',
        'confidence' => 0.92,
        'probabilities' => ['Eczema' => 0.92],
        'image_path' => 'diagnoses/test-completed.jpg',
        'status' => 'completed',
    ]);

    $records = $recordRepo->getRecordsForPatient($this->patient->uuid);

    $recordUuids = $records->pluck('uuid')->all();
    expect($recordUuids)->toContain($completedDiagnosis->uuid);
    expect($recordUuids)->not->toContain($draftDiagnosis->uuid);
});

test('updating diagnosis status to completed makes it visible in patient records', function () {
    $recordRepo = app(RecordRepository::class);

    $diagnosis = Diagnosis::create([
        'uuid' => (string) Str::uuid(),
        'user_uuid' => $this->patient->uuid,
        'patient_uuid' => $this->patient->uuid,
        'label' => 'Herpes',
        'confidence' => 0.95,
        'probabilities' => ['Herpes' => 0.95],
        'image_path' => 'diagnoses/test.jpg',
        'status' => 'draft',
    ]);

    expect($recordRepo->getRecordsForPatient($this->patient->uuid)->pluck('uuid')->all())
        ->not->toContain($diagnosis->uuid);

    $diagnosis->update(['status' => 'completed']);

    expect($recordRepo->getRecordsForPatient($this->patient->uuid)->pluck('uuid')->all())
        ->toContain($diagnosis->uuid);
});

test('diagnosis repository delete cleans up stored image from storage', function () {
    Storage::fake('public');

    $fakeImagePath = 'diagnoses/fake-scan.jpg';
    Storage::disk('public')->put($fakeImagePath, 'image-content');
    expect(Storage::disk('public')->exists($fakeImagePath))->toBeTrue();

    $diagnosis = Diagnosis::create([
        'uuid' => (string) Str::uuid(),
        'user_uuid' => $this->patient->uuid,
        'patient_uuid' => $this->patient->uuid,
        'label' => 'Clear',
        'confidence' => 0.99,
        'probabilities' => ['Clear' => 0.99],
        'image_path' => $fakeImagePath,
        'status' => 'draft',
    ]);

    $diagnosisRepo = app(DiagnosisRepository::class);
    $diagnosisRepo->delete($diagnosis->uuid);

    expect(Storage::disk('public')->exists($fakeImagePath))->toBeFalse();
    expect(Diagnosis::where('uuid', $diagnosis->uuid)->exists())->toBeFalse();
});

test('patient can export pdf for their diagnosis without mysql integer coercion bug', function () {
    // Create an earlier diagnosis belonging to another user
    $otherUser = User::factory()->create([
        'role_id' => $this->patientRole->id,
    ]);
    $earlierDiagnosis = Diagnosis::create([
        'uuid' => '00000000-0000-0000-0000-000000000001',
        'user_uuid' => $otherUser->uuid,
        'patient_uuid' => $otherUser->uuid,
        'label' => 'Acne',
        'confidence' => 0.85,
        'probabilities' => ['Acne' => 0.85],
        'image_path' => 'diagnoses/other.jpg',
        'status' => 'completed',
    ]);

    // Create target diagnosis starting with a number (e.g. 01a...)
    $targetDiagnosis = Diagnosis::create([
        'uuid' => '01a'.Str::random(33),
        'user_uuid' => $this->patient->uuid,
        'patient_uuid' => $this->patient->uuid,
        'label' => 'Inconclusive',
        'confidence' => 0.69,
        'probabilities' => ['Inconclusive' => 0.69],
        'image_path' => 'diagnoses/target.jpg',
        'status' => 'completed',
    ]);

    $pdfService = app(ClinicalReportPdfService::class);
    $response = $pdfService->generatePdf($targetDiagnosis->uuid, $this->patient);

    expect($response->getStatusCode())->toBe(200);
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});
