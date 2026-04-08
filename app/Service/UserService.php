<?php

namespace App\Service;

use App\Http\Resources\UserResource;
use App\Repository\UserRepository;
use App\Models\DoctorVerification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class UserService
{
    private UserRepository $userRepository;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function login(array $payload)
    {
        $user = $this->userRepository->findFirstByField('email', $payload['email']);

        if (! $user) {
            return response()->json(['message' => 'Invalid Credentials'], 401);
        }

        if (! Hash::check($payload['password'], $user->password)) {
            return response()->json(['message' => 'Invalid password'], 401);
        }

        $token = $user->createToken($user->email)->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ], 200);
    }

    public function logout(object $user)
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
        return \Illuminate\Support\Facades\DB::transaction(function () use ($payload) {
            $roleSlug = $payload['role'] ?? 'patient';
            $role = \App\Models\Role::where('slug', $roleSlug)->firstOrFail();

            // Map frontend camelCase to backend snake_case
            // Ensure UUID is generated if trait doesn't pick it up for non-primary keys
            $userData = [
                'first_name' => $payload['firstName'],
                'middle_name' => $payload['middleName'] ?? null,
                'last_name' => $payload['lastName'],
                'email' => $payload['email'],
                'password' => $payload['password'],
                'role_id' => $role->id,
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
            ];

            $user = $this->userRepository->create($userData);

            // Handle Doctor Verification (Now Optional)
            if ($roleSlug === 'doctor' && ! empty($payload['prcNumber'])) {
                $verificationData = [
                    'user_id' => $user->id,
                    'prc_number' => $payload['prcNumber'],
                    'id_photo_path' => null,
                    'status' => 'pending',
                ];

                if (! empty($payload['idPhoto'])) {
                    $path = 'verifications/doctor_' . $user->id . '_' . time() . '.png';
                    $verificationData['id_photo_path'] = $this->saveBase64Image($payload['idPhoto'], $path);
                }

                DoctorVerification::create($verificationData);
            }

            $token = $user->createToken($user->email)->plainTextToken;

            // Load role relationship for the resource
            $user->load('role');

            return response()->json([
                'user' => new UserResource($user),
                'token' => $token,
            ], 201);
        });
    }

    private function saveBase64Image(string $base64String, string $path): string
    {
        if (preg_match('/^data:image\/(\w+);base64,/', $base64String, $type)) {
            $base64String = substr($base64String, strpos($base64String, ',') + 1);
            $type = strtolower($type[1]);

            if (! in_array($type, ['jpg', 'jpeg', 'gif', 'png'])) {
                throw new \Exception('invalid image type');
            }

            $base64String = base64_decode($base64String);

            if ($base64String === false) {
                throw new \Exception('base64_decode failed');
            }
        } else {
            throw new \Exception('did not match data URI with image data');
        }

        Storage::disk('public')->put($path, $base64String);

        return $path;
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
