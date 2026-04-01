<?php

namespace App\Service;

use App\Repository\UserRepository;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Hash;

class UserService
{
    private UserRepository $userRepository;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function loginUser(object $payload)
    {
        if (empty($payload->email) || empty($payload->password)) {
            return response()->json(['message' => 'Email and password are required'], 400);
        }

        $user = $this->userRepository->findByField('email', $payload->email);

        if (! $user) {
            return response()->json(['message' => 'User not found'], 401);
        }

        if (! Hash::check($payload->password, $user->password)) {
            return response()->json(['message' => 'Invalid password'], 401);
        }

        $token = $user->createToken($user->email)->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token
        ], 200);
    }

    public function logoutUser(object $user)
    {
        if ($user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        return response()->json(['message' => 'Logged out successfully'], 200);
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
