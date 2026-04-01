<?php

namespace App\Service;

use App\Repository\UserRepository;
use App\Http\Resources\UserResource;

class UserService
{
    private UserRepository $userRepository;

    public function __construct(UserRepository $userRepository) 
    {
        $this->userRepository = $userRepository;
    }

    public function listUser(int $perPage = 15)
    {
        $collection = $this->userRepository->paginate($perPage);
        return UserResource::collection($collection);
    }

    public function createUser(array $payload)
    {
        $model = $this->userRepository->create($payload);
        return new UserResource($model);
    }

    public function getUser(string $uuid)
    {
        $model = $this->userRepository->findByUuid($uuid);
        return new UserResource($model);
    }

    public function getUserByField(string $field, $value)
    {
        $model = $this->userRepository->findByField($field, $value);
        return new UserResource($model);
    }

    public function updateUser(string $uuid, array $payload)
    {
        $model = $this->userRepository->update($uuid, $payload);
        return new UserResource($model);
    }

    public function deleteUser(string $uuid)
    {
        $this->userRepository->delete($uuid);
        return true;
    }

    public function restoreUser(string $uuid)
    {
        $model = $this->userRepository->restore($uuid);
        return new UserResource($model);
    }
}