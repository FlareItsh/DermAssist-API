<?php

use App\Models\Appointment;
use App\Models\ClinicalNote;
use App\Models\Diagnosis;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Repository\PlanRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->doctorRole = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
    $this->patientRole = Role::firstOrCreate(['slug' => 'patient'], ['name' => 'Patient']);

    // Ensure export_pdf_reports feature exists
    Feature::firstOrCreate(
        ['code' => 'export_pdf_reports'],
        [
            'uuid' => (string) Str::uuid(),
            'name' => 'Allow PDF Clinical Report Exports',
            'description' => 'Export full clinical assessment diagnosis reports as downloadable PDFs.',
            'is_active' => true,
            'sort_order' => 3,
        ]
    );

    // Plan with PDF exports enabled
    $planRepo = app(PlanRepository::class);
    $this->planWithPdf = $planRepo->create([
        'name' => 'Pro Doctor Plan '.Str::random(5),
        'slug' => 'pro-doc-'.Str::random(5),
        'tier_type' => 'individual',
        'price_monthly' => 1500.00,
        'price_annual' => 15000.00,
        'max_doctors' => 1,
        'max_clinics' => 1,
        'max_secretaries' => 1,
        'features' => [
            'export_pdf_reports' => true,
        ],
        'is_active' => true,
    ]);

    // Plan without PDF exports
    $this->planWithoutPdf = $planRepo->create([
        'name' => 'Basic Doctor Plan '.Str::random(5),
        'slug' => 'basic-doc-'.Str::random(5),
        'tier_type' => 'individual',
        'price_monthly' => 500.00,
        'price_annual' => 5000.00,
        'max_doctors' => 1,
        'max_clinics' => 1,
        'max_secretaries' => 0,
        'features' => [
            'export_pdf_reports' => false,
        ],
        'is_active' => true,
    ]);

    // Doctors
    $this->subscribedDoctor = User::factory()->create([
        'role_id' => $this->doctorRole->id,
        'first_name' => 'Elena',
        'last_name' => 'Rostova',
        'affiliation' => 'Skin Health Clinic',
    ]);
    Subscription::create([
        'user_id' => $this->subscribedDoctor->id,
        'plan_id' => $this->planWithPdf->id,
        'billing_cycle' => 'monthly',
        'status' => 'active',
        'starts_at' => now(),
        'ends_at' => now()->addMonth(),
    ]);

    $this->basicDoctor = User::factory()->create([
        'role_id' => $this->doctorRole->id,
        'first_name' => 'Marcus',
        'last_name' => 'Vance',
    ]);
    Subscription::create([
        'user_id' => $this->basicDoctor->id,
        'plan_id' => $this->planWithoutPdf->id,
        'billing_cycle' => 'monthly',
        'status' => 'active',
        'starts_at' => now(),
        'ends_at' => now()->addMonth(),
    ]);

    // Patients
    $this->patient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'date_of_birth' => '1995-05-15',
    ]);

    $this->otherPatient = User::factory()->create([
        'role_id' => $this->patientRole->id,
        'first_name' => 'John',
        'last_name' => 'Smith',
    ]);

    // Create a diagnosis with clinical note
    $this->diagnosis = Diagnosis::create([
        'uuid' => (string) Str::uuid(),
        'user_uuid' => $this->patient->uuid,
        'patient_uuid' => $this->patient->uuid,
        'doctor_uuid' => $this->subscribedDoctor->uuid,
        'label' => 'Eczema',
        'confidence' => 0.94,
        'probabilities' => [
            'Eczema' => 0.94,
            'Psoriasis' => 0.04,
            'Contact Dermatitis' => 0.02,
        ],
        'image_path' => 'scans/test-lesion.jpg',
        'status' => 'completed',
    ]);

    $this->appointment = Appointment::create([
        'uuid' => (string) Str::uuid(),
        'doctor_id' => $this->subscribedDoctor->id,
        'patient_id' => $this->patient->id,
        'diagnosis_id' => $this->diagnosis->id,
        'scheduled_at' => now(),
        'scheduled_end_at' => now()->addHour(),
        'status' => 'completed',
    ]);

    $this->clinicalNote = ClinicalNote::create([
        'uuid' => (string) Str::uuid(),
        'appointment_id' => $this->appointment->id,
        'doctor_id' => $this->subscribedDoctor->id,
        'patient_id' => $this->patient->id,
        'diagnosis_id' => $this->diagnosis->id,
        'history_of_present_illness' => 'Patient presents with pruritic erythematous plaques.',
        'physical_exam' => 'Well-demarcated dry scaly lesions on antecubital fossa.',
        'final_diagnosis' => 'Moderate Atopic Dermatitis',
        'prescription' => 'Hydrocortisone 1% cream BID for 7 days. Cetirizine 10mg daily PRN.',
        'patient_education' => 'Keep skin moisturized with bland emollient after showering.',
        'follow_up_date' => now()->addDays(14)->toDateString(),
        'follow_up_instructions' => 'Return if symptoms worsen or no improvement after 7 days.',
    ]);
});

test('doctor with active subscription including export_pdf_reports can download report PDF', function () {
    $response = $this->actingAs($this->subscribedDoctor)
        ->get("/api/records/{$this->diagnosis->uuid}/export-pdf");

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('clinical-assessment-');
});

test('doctor whose subscription lacks export_pdf_reports is blocked with 403 PLAN_FEATURE_RESTRICTED', function () {
    $response = $this->actingAs($this->basicDoctor)
        ->getJson("/api/records/{$this->diagnosis->uuid}/export-pdf");

    $response->assertStatus(403)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('code', 'PLAN_FEATURE_RESTRICTED');
});

test('patient can download their own clinical report PDF', function () {
    $response = $this->actingAs($this->patient)
        ->get("/api/records/{$this->diagnosis->uuid}/export-pdf");

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});

test('patient cannot download another patient clinical report PDF', function () {
    $response = $this->actingAs($this->otherPatient)
        ->getJson("/api/records/{$this->diagnosis->uuid}/export-pdf");

    $response->assertStatus(403);
});
