<?php

namespace App\Service;

use App\Http\Resources\AppealResource;
use App\Models\User;
use App\Repository\AppealRepository;

class AppealService
{
    private AppealRepository $appealRepository;

    public function __construct(AppealRepository $appealRepository)
    {
        $this->appealRepository = $appealRepository;
    }

    public function listAppeal(int $perPage = 15)
    {
        $collection = $this->appealRepository->paginate($perPage);

        return AppealResource::collection($collection);
    }

    public function listPendingAppeals()
    {
        $appeals = $this->appealRepository->getPendingWithUser();

        return AppealResource::collection($appeals);
    }

    public function submitAppeal(array $payload)
    {
        $user = User::where('uuid', $payload['user_uuid'])->firstOrFail();

        $appeal = $this->appealRepository->create([
            'user_id' => $user->id,
            'diagnosis_label' => $payload['diagnosis_label'],
            'suggested_label' => $payload['suggested_label'],
            'description' => $payload['description'] ?? null,
            'status' => 'pending',
        ]);

        return new AppealResource($appeal->load('user'));
    }

    public function getAppeal(string $uuid)
    {
        $model = $this->appealRepository->findByUuid($uuid);

        return new AppealResource($model);
    }

    public function getAppealByField(string $field, $value)
    {
        $model = $this->appealRepository->findByField($field, $value);

        return new AppealResource($model);
    }

    public function updateAppeal(string $uuid, array $payload)
    {
        $model = $this->appealRepository->update($uuid, $payload);

        return new AppealResource($model);
    }

    public function deleteAppeal(string $uuid)
    {
        $this->appealRepository->delete($uuid);

        return true;
    }

    public function restoreAppeal(string $uuid)
    {
        $model = $this->appealRepository->restore($uuid);

        return new AppealResource($model);
    }
}
