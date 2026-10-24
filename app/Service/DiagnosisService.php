<?php

namespace App\Service;

use App\Http\Resources\DiagnosisResource;
use App\Http\Resources\UserResource;
use App\Models\Appointment;
use App\Models\User;
use App\Repository\DiagnosisRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiagnosisService
{
    private DiagnosisRepository $diagnosisRepository;

    private DoctorAvailabilityService $doctorAvailabilityService;

    private ScanQuotaService $scanQuotaService;

    public function __construct(
        DiagnosisRepository $diagnosisRepository,
        DoctorAvailabilityService $doctorAvailabilityService,
        ScanQuotaService $scanQuotaService
    ) {
        $this->diagnosisRepository = $diagnosisRepository;
        $this->doctorAvailabilityService = $doctorAvailabilityService;
        $this->scanQuotaService = $scanQuotaService;
    }

    public function diagnose(array $data)
    {
        $image = $data['image'];
        $userUuid = $data['user_uuid'] ?? null;

        // 1. Call AI Server with connection and response timeouts
        try {
            $response = Http::timeout(12)
                ->connectTimeout(3)
                ->attach(
                    'file',
                    file_get_contents($image->getRealPath()),
                    $image->getClientOriginalName()
                )->post(config('services.ai.url').'/predict');
        } catch (ConnectionException $e) {
            Log::error('AI Server Connection Error: '.$e->getMessage());
            throw new \Exception('The AI analysis service is experiencing high traffic and timed out. Please try again shortly.');
        }

        if ($response->failed()) {
            $body = $response->json();

            // The skin validator returns 422 when the image is not a skin photo
            if ($response->status() === 422 && isset($body['detail']['code']) && $body['detail']['code'] === 'NOT_SKIN_IMAGE') {
                throw new \InvalidArgumentException($body['detail']['message'] ?? 'The uploaded image does not appear to be a skin photo. Please upload a clear, close-up photo of the affected skin area.');
            }

            Log::error('AI Server Error: '.$response->body());
            throw new \Exception('The AI server is currently unavailable or returned an error.');
        }

        $aiResult = $response->json();
        $scanQuota = null;
        if (! empty($data['user']) && $data['user'] instanceof User) {
            $scanQuota = $this->scanQuotaService->recordScan($data['user']);
        }

        // 2. Save image
        $path = $image->store('diagnoses', 'public');

        $patientConsented = false;
        if (! empty($data['patient_uuid'])) {
            $patient = User::where('uuid', $data['patient_uuid'])->first();
            if ($patient) {
                $patientConsented = (bool) $patient->consent_dataset;
            }
        }

        $isDoctor = ! empty($data['doctor_uuid']) || ($data['user'] && $data['user']->role && $data['user']->role->slug === 'doctor');
        $initialStatus = 'draft';

        // 3. Create record
        $diagnosis = $this->diagnosisRepository->create([
            'user_uuid' => $userUuid,
            'patient_uuid' => $data['patient_uuid'] ?? null,
            'doctor_uuid' => $data['doctor_uuid'] ?? null,
            'image_path' => $path,
            'label' => $aiResult['label'],
            'original_ai_label' => $aiResult['label'],
            'scan_type' => $isDoctor ? 'in_clinic' : 'home',
            'is_technical_failure' => false,
            'confidence' => $aiResult['confidence'],
            'probabilities' => $aiResult['all_probabilities'],
            'status' => $initialStatus,
            'patient_consented_dataset' => $patientConsented,
            'out_of_scope_category' => $aiResult['out_of_scope_category'] ?? null,
        ]);

        if (! empty($data['appointment_uuid']) && $diagnosis->status === 'completed') {
            $appointment = Appointment::where('uuid', $data['appointment_uuid'])->first();
            if ($appointment) {
                $apptUpdate = [];
                if ($isDoctor || $diagnosis->scan_type !== 'home') {
                    $apptUpdate['in_clinic_diagnosis_id'] = $diagnosis->id;
                }
                if (! $appointment->diagnosis_id) {
                    $apptUpdate['diagnosis_id'] = $diagnosis->id;
                }
                if (! empty($apptUpdate)) {
                    $appointment->update($apptUpdate);
                }
            }
        }

        // Set transient properties directly on the model instance to keep response flat (no additional() wrapper)
        $diagnosis->image_quality = $aiResult['image_quality'] ?? null;
        $diagnosis->is_inconclusive = $aiResult['is_inconclusive'] ?? ($aiResult['label'] === 'Inconclusive');
        $diagnosis->clinical_feedback = $aiResult['clinical_feedback'] ?? null;
        $diagnosis->scan_quota = $scanQuota;

        if (! empty($data['doctor_id'])) {
            $availabilityCheck = $this->doctorAvailabilityService->checkDoctorAvailability(
                $data['doctor_id'],
                now(),
                $data['user'] ?? null
            );

            $diagnosis->doctor_availability = [
                'is_available' => $availabilityCheck['is_available'],
                'next_available' => $availabilityCheck['next_available'],
                'alternatives' => UserResource::collection($availabilityCheck['alternatives']),
            ];
        }

        return new DiagnosisResource($diagnosis);
    }

    public function listDiagnosis(int $perPage = 15)
    {
        $collection = $this->diagnosisRepository->paginate($perPage);

        return DiagnosisResource::collection($collection);
    }

    public function recordTechnicalFailure(array $data)
    {
        $path = null;
        if (! empty($data['image']) && $data['image'] instanceof UploadedFile) {
            $path = $data['image']->store('diagnoses', 'public');
        }

        $diagnosis = $this->diagnosisRepository->create([
            'user_uuid' => $data['user_uuid'] ?? null,
            'patient_uuid' => $data['patient_uuid'] ?? null,
            'doctor_uuid' => $data['doctor_uuid'] ?? null,
            'image_path' => $path,
            'label' => null,
            'original_ai_label' => null,
            'scan_type' => 'in_clinic',
            'is_technical_failure' => true,
            'technical_failure_reason' => $data['reason'] ?? 'Camera blur or technical failure during capture.',
            'confidence' => null,
            'probabilities' => null,
            'status' => 'failed',
            'patient_consented_dataset' => false,
            'doctor_authorized_dataset' => false,
            'admin_approved_dataset' => false,
        ]);

        return new DiagnosisResource($diagnosis);
    }

    public function createDiagnosis(array $payload)
    {
        $model = $this->diagnosisRepository->create($payload);

        return new DiagnosisResource($model);
    }

    public function getDiagnosis(string $uuid)
    {
        $model = $this->diagnosisRepository->findByUuid($uuid);

        return new DiagnosisResource($model);
    }

    public function getDiagnosisByField(string $field, $value)
    {
        $model = $this->diagnosisRepository->findByField($field, $value);

        return new DiagnosisResource($model);
    }

    public function updateDiagnosis(string $uuid, array $payload)
    {
        if (array_key_exists('patient_uuid', $payload)) {
            $patient = ! empty($payload['patient_uuid']) ? User::where('uuid', $payload['patient_uuid'])->first() : null;
            $payload['patient_consented_dataset'] = $patient ? (bool) $patient->consent_dataset : false;
        }

        $model = $this->diagnosisRepository->update($uuid, $payload);

        return new DiagnosisResource($model);
    }

    public function deleteDiagnosis(string $uuid)
    {
        $this->diagnosisRepository->delete($uuid);

        return true;
    }

    public function restoreDiagnosis(string $uuid)
    {
        $model = $this->diagnosisRepository->restore($uuid);

        return new DiagnosisResource($model);
    }
}
