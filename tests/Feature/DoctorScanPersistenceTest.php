<?php

use App\Models\Appointment;
use App\Models\ClinicalNote;
use App\Models\Diagnosis;
use App\Models\Role;
use App\Models\User;
use App\Repository\DiagnosisRepository;
use App\Repository\RecordRepository;
use App\Service\ClinicalNoteService;
use App\Service\DiagnosisService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->doctorRole = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    $this->patientRole = Role::firstOrCreate(['slug' => 'patient'], ['name' => 'Patient']);

    $this->doctor = User::factory()->create([
        'role_id' => $this->doctorRole->id,
        'first_name' => 'Doctor',
        'last_name' => 'Smith',
    ]);

    $this->patient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'first_name' => 'Jane',
        'last_name' => 'Doe',
    ]);
});

test('draft diagnoses are excluded from doctor records list', function () {
    $recordRepo = app(RecordRepository::class);

    // Create a draft diagnosis for doctor
    $draftDiagnosis = Diagnosis::create([
        'uuid' => (string) Str::uuid(),
        'user_uuid' => $this->doctor->uuid,
        'doctor_uuid' => $this->doctor->uuid,
        'patient_uuid' => $this->patient->uuid,
        'label' => 'Melanoma',
        'confidence' => 0.88,
        'probabilities' => ['Melanoma' => 0.88],
        'image_path' => 'diagnoses/doctor-draft.jpg',
        'scan_type' => 'in_clinic',
        'status' => 'draft',
    ]);

    // Create a completed diagnosis for doctor
    $completedDiagnosis = Diagnosis::create([
        'uuid' => (string) Str::uuid(),
        'user_uuid' => $this->doctor->uuid,
        'doctor_uuid' => $this->doctor->uuid,
        'patient_uuid' => $this->patient->uuid,
        'label' => 'Eczema',
        'confidence' => 0.95,
        'probabilities' => ['Eczema' => 0.95],
        'image_path' => 'diagnoses/doctor-completed.jpg',
        'scan_type' => 'in_clinic',
        'status' => 'completed',
    ]);

    $records = $recordRepo->getRecordsForDoctor($this->doctor->id, $this->doctor->uuid);

    $recordUuids = $records->pluck('uuid')->all();
    expect($recordUuids)->toContain($completedDiagnosis->uuid);
    expect($recordUuids)->not->toContain($draftDiagnosis->uuid);
});

test('updating diagnosis status to completed makes it visible in doctor records', function () {
    $recordRepo = app(RecordRepository::class);

    $diagnosis = Diagnosis::create([
        'uuid' => (string) Str::uuid(),
        'user_uuid' => $this->doctor->uuid,
        'doctor_uuid' => $this->doctor->uuid,
        'patient_uuid' => $this->patient->uuid,
        'label' => 'Herpes',
        'confidence' => 0.91,
        'probabilities' => ['Herpes' => 0.91],
        'image_path' => 'diagnoses/doctor-test.jpg',
        'scan_type' => 'in_clinic',
        'status' => 'draft',
    ]);

    expect($recordRepo->getRecordsForDoctor($this->doctor->id, $this->doctor->uuid)->pluck('uuid')->all())
        ->not->toContain($diagnosis->uuid);

    $diagnosis->update(['status' => 'completed']);

    expect($recordRepo->getRecordsForDoctor($this->doctor->id, $this->doctor->uuid)->pluck('uuid')->all())
        ->toContain($diagnosis->uuid);
});

test('doctor scan initializes with draft status and is not in records', function () {
    Storage::fake('public');

    Http::fake([
        config('services.ai.url').'/predict' => Http::response([
            'label' => 'Psoriasis',
            'confidence' => 0.93,
            'all_probabilities' => ['Psoriasis' => 0.93],
            'device' => 'cpu',
            'architecture' => 'resnet50',
            'image_type' => 'skin',
        ], 200),
    ]);

    $jpegBytes = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
    $file = UploadedFile::fake()->createWithContent('skin.jpg', $jpegBytes);

    $diagService = app(DiagnosisService::class);
    $result = $diagService->diagnose([
        'image' => $file,
        'user_uuid' => $this->doctor->uuid,
        'doctor_uuid' => $this->doctor->uuid,
        'patient_uuid' => $this->patient->uuid,
        'user' => $this->doctor,
    ]);

    expect($result->status)->toBe('draft');

    $recordRepo = app(RecordRepository::class);
    $records = $recordRepo->getRecordsForDoctor($this->doctor->id, $this->doctor->uuid);
    expect($records->pluck('uuid')->all())->not->toContain($result->uuid);
});

test('saving clinical note marks diagnosis completed and makes it visible in doctor records', function () {
    $appointment = Appointment::create([
        'uuid' => (string) Str::uuid(),
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient->id,
        'scheduled_at' => now(),
        'status' => 'in_consultation',
    ]);

    $diagnosis = Diagnosis::create([
        'uuid' => (string) Str::uuid(),
        'user_uuid' => $this->doctor->uuid,
        'doctor_uuid' => $this->doctor->uuid,
        'patient_uuid' => $this->patient->uuid,
        'label' => 'Ringworm',
        'confidence' => 0.90,
        'probabilities' => ['Ringworm' => 0.90],
        'image_path' => 'diagnoses/ringworm.jpg',
        'scan_type' => 'in_clinic',
        'status' => 'draft',
    ]);

    $clinicalNoteService = app(ClinicalNoteService::class);
    $clinicalNoteService->storeNote($appointment->uuid, [
        'diagnosis_uuid' => $diagnosis->uuid,
        'status' => 'completed',
        'final_diagnosis' => 'Tinea Corporis',
        'subjective' => 'Rash on arm',
    ]);

    $diagnosis->refresh();
    expect($diagnosis->status)->toBe('completed');

    $recordRepo = app(RecordRepository::class);
    $records = $recordRepo->getRecordsForDoctor($this->doctor->id, $this->doctor->uuid);
    expect($records->pluck('uuid')->all())->toContain($diagnosis->uuid);
});

test('discarding draft doctor scan deletes diagnosis record and storage image', function () {
    Storage::fake('public');

    $fakeImagePath = 'diagnoses/discard-test.jpg';
    Storage::disk('public')->put($fakeImagePath, 'image-bytes');

    $diagnosis = Diagnosis::create([
        'uuid' => (string) Str::uuid(),
        'user_uuid' => $this->doctor->uuid,
        'doctor_uuid' => $this->doctor->uuid,
        'patient_uuid' => $this->patient->uuid,
        'label' => 'Acne',
        'confidence' => 0.85,
        'probabilities' => ['Acne' => 0.85],
        'image_path' => $fakeImagePath,
        'scan_type' => 'in_clinic',
        'status' => 'draft',
    ]);

    $diagnosisRepo = app(DiagnosisRepository::class);
    $diagnosisRepo->delete($diagnosis->uuid);

    expect(Storage::disk('public')->exists($fakeImagePath))->toBeFalse();
    expect(Diagnosis::where('uuid', $diagnosis->uuid)->exists())->toBeFalse();
});

test('draft in-clinic scan does not satisfy consultation scan requirement', function () {
    $draftScan = Diagnosis::create([
        'uuid' => (string) Str::uuid(),
        'user_uuid' => $this->doctor->uuid,
        'doctor_uuid' => $this->doctor->uuid,
        'patient_uuid' => $this->patient->uuid,
        'label' => 'Eczema',
        'confidence' => 0.91,
        'scan_type' => 'in_clinic',
        'is_technical_failure' => false,
        'status' => 'draft',
    ]);

    $appointment = Appointment::create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient->id,
        'in_clinic_diagnosis_id' => $draftScan->id,
        'scheduled_at' => now(),
        'status' => 'in_consultation',
        'location' => 'Main Clinic',
    ]);

    expect($appointment->hasSatisfiedConsultationScan())->toBeFalse();

    $draftScan->update(['status' => 'completed']);
    $appointment->refresh();

    expect($appointment->hasSatisfiedConsultationScan())->toBeTrue();
});
