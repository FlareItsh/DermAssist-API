<?php

namespace App\Service;

use App\Repository\AppealRepository;
use App\Repository\UserRepository;

class AppealService
{
    private AppealRepository $appealRepository;

    private UserRepository $userRepository;

    public function __construct(
        AppealRepository $appealRepository,
        UserRepository $userRepository
    ) {
        $this->appealRepository = $appealRepository;
        $this->userRepository = $userRepository;
    }

    public function listPendingAppeals()
    {
        return $this->appealRepository->pending();
    }

    public function createAppeal(array $payload)
    {
        $user = $this->userRepository->findByUuid($payload['user_uuid']);

        return $this->appealRepository->create([
            'user_id' => $user->id,
            'diagnosis_label' => $payload['diagnosis_label'],
            'suggested_label' => $payload['suggested_label'],
            'description' => $payload['description'] ?? null,
            'status' => 'pending',
        ]);
    }
}
