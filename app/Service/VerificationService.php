<?php

namespace App\Service;

use App\Repository\VerificationRepository;
use App\Http\Resources\VerificationResource;

class VerificationService
{
    private VerificationRepository $verificationRepository;

    public function __construct(VerificationRepository $verificationRepository) 
    {
        $this->verificationRepository = $verificationRepository;
    }

    public function listVerification(int $perPage = 15)
    {
        $collection = $this->verificationRepository->paginate($perPage);
        return VerificationResource::collection($collection);
    }

    public function createVerification(array $payload)
    {
        $model = $this->verificationRepository->create($payload);
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
        $model = $this->verificationRepository->update($uuid, $payload);
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