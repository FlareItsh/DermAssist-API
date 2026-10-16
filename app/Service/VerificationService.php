<?php

namespace App\Service;

use App\Events\DoctorVerificationStatusUpdated;
use App\Http\Resources\VerificationResource;
use App\Repository\VerificationRepository;
use Illuminate\Validation\ValidationException;

class VerificationService
{
    private VerificationRepository $verificationRepository;

    public function __construct(VerificationRepository $verificationRepository)
    {
        $this->verificationRepository = $verificationRepository;
    }

    public function listVerification(int $perPage = 15, ?string $status = null, ?string $search = null)
    {
        $collection = $this->verificationRepository->paginate($perPage, $status, $search);

        return VerificationResource::collection($collection);
    }

    public function createVerification(array $payload)
    {
        $model = $this->verificationRepository->create($payload);
        $model->loadMissing('user');
        $doctorUuid = $model->user?->uuid;
        broadcast(new DoctorVerificationStatusUpdated($doctorUuid, $model->status, $model->uuid));

        return new VerificationResource($model);
    }

    public function getVerification(string $uuid)
    {
        $model = $this->verificationRepository->findByUuid($uuid);

        return new VerificationResource($model);
    }

    public function getVerificationByField(string $field, $value)
    {
        $model = $this->verificationRepository->findByField($field, $value);

        return new VerificationResource($model);
    }

    public function updateVerification(string $uuid, array $payload)
    {
        if (isset($payload['status']) && $payload['status'] === 'verified') {
            $verification = $this->verificationRepository->findByUuid($uuid);
            $verification->loadMissing('user');
            $hasPrcNumber = ! empty($verification->prc_number) || ! empty($verification->user?->prc_number);
            $hasIdPhoto = ! empty($verification->id_photo_path);

            if (! $hasPrcNumber || ! $hasIdPhoto) {
                throw ValidationException::withMessages([
                    'status' => ['Cannot verify doctor without a valid PRC license number and uploaded PRC ID document.'],
                ]);
            }
        }

        $model = $this->verificationRepository->update($uuid, $payload);
        $model->loadMissing('user');
        $doctorUuid = $model->user?->uuid;
        broadcast(new DoctorVerificationStatusUpdated($doctorUuid, $model->status, $model->uuid));

        return new VerificationResource($model);
    }

    public function deleteVerification(string $uuid)
    {
        $this->verificationRepository->delete($uuid);

        return true;
    }

    public function restoreVerification(string $uuid)
    {
        $model = $this->verificationRepository->restore($uuid);

        return new VerificationResource($model);
    }
}
