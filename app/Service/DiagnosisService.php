<?php

namespace App\Service;

use App\Http\Resources\DiagnosisResource;
use App\Http\Resources\UserResource;
use App\Repository\DiagnosisRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiagnosisService
{
    private DiagnosisRepository $diagnosisRepository;

    private DoctorAvailabilityService $doctorAvailabilityService;

    public function __construct(
        DiagnosisRepository $diagnosisRepository,
        DoctorAvailabilityService $doctorAvailabilityService
    )
    {
        $this->diagnosisRepository = $diagnosisRepository;
        $this->doctorAvailabilityService = $doctorAvailabilityService;
    }

    public function diagnose(array $data)
    {
        $image = $data['image'];
        $userUuid = $data['user_uuid'] ?? null;

        // 1. Call AI Server
        $response = Http::attach(
            'file',
            file_get_contents($image->getRealPath()),
            $image->getClientOriginalName()
        )->post(config('services.ai.url').'/predict');

        if ($response->failed()) {
            Log::error('AI Server Error: '.$response->body());
            throw new \Exception('The AI server is currently unavailable or returned an error.');
        }

        $aiResult = $response->json();

        // 2. Save image
        $path = $image->store('diagnoses', 'public');

        // 3. Create record
        $diagnosis = $this->diagnosisRepository->create([
            'user_uuid' => $userUuid,
            'image_path' => $path,
            'label' => $aiResult['label'],
            'confidence' => $aiResult['confidence'],
            'probabilities' => $aiResult['all_probabilities'],
            'status' => 'completed',
        ]);

        $resource = new DiagnosisResource($diagnosis);

        if (! empty($data['doctor_id'])) {
            $availabilityCheck = $this->doctorAvailabilityService->checkDoctorAvailability(
                $data['doctor_id'],
                now(),
                $data['user'] ?? null
            );

            $resource->additional([
                'doctor_availability' => [
                    'is_available' => $availabilityCheck['is_available'],
                    'next_available' => $availabilityCheck['next_available'],
                    'alternatives' => UserResource::collection($availabilityCheck['alternatives']),
                ],
            ]);
        }

        return $resource;
    }

    public function listDiagnosis(int $perPage = 15)
    {
        $collection = $this->diagnosisRepository->paginate($perPage);

        return DiagnosisResource::collection($collection);
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
