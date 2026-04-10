<?php

namespace App\Service;

use App\Http\Resources\UserResource;
use App\Models\DoctorVerification;
use App\Models\Role;
use App\Repository\UserRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        return DB::transaction(function () use ($payload) {
            $roleSlug = $payload['role'] ?? 'patient';
            $role = Role::where('slug', $roleSlug)->firstOrFail();

            // Map frontend camelCase to backend snake_case
            // Ensure UUID is generated if trait doesn't pick it up for non-primary keys
            $userData = [
                'first_name' => $payload['firstName'],
                'middle_name' => $payload['middleName'] ?? null,
                'last_name' => $payload['lastName'],
                'email' => $payload['email'],
                'password' => $payload['password'],
                'role_id' => $role->id,
                'uuid' => (string) Str::uuid(),
                'prc_number' => $payload['prcNumber'] ?? null,
                'avatar_path' => null,
            ];

            if (! empty($payload['avatar'])) {
                $path = 'avatars/'.Str::slug($payload['firstName'].'_'.$payload['lastName']).'_'.time().'.png';
                try {
                    $userData['avatar_path'] = $this->saveBase64Image($payload['avatar'], $path);
                } catch (\Exception $e) {
                    throw $e;
                }
            }

            $user = $this->userRepository->create($userData);

            // Handle Doctor Verification
            if ($roleSlug === 'doctor' && ! empty($payload['prcNumber'])) {
                $verificationData = [
                    'user_id' => $user->id,
                    'prc_number' => $payload['prcNumber'],
                    'id_photo_path' => null,
                    'status' => DoctorVerification::STATUS_PENDING,
                ];

                if (! empty($payload['idPhoto'])) {
                    $path = 'verifications/doctor_'.$user->id.'_'.time().'.png';
                    try {
                        $verificationData['id_photo_path'] = $this->saveBase64Image($payload['idPhoto'], $path);
                    } catch (\Exception $e) {
                        // Log the error but don't fail the whole registration if image processing fails?
                        // Actually, better to fail and let user retry.
                        throw $e;
                    }
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
        $user = $this->userRepository->findByUuid($uuid);

        if (! empty($payload['avatar'])) {
            $path = 'avatars/'.Str::slug($user->first_name.'_'.$user->last_name).'_'.time().'.png';

            try {
                $avatarPath = $this->saveBase64Image($payload['avatar'], $path);

                // Delete old avatar if it exists
                if ($user->avatar_path) {
                    Storage::disk('public')->delete($user->avatar_path);
                }

                $payload['avatar_path'] = $avatarPath;
            } catch (\Exception $e) {
                throw $e;
            }
        }

        // Map prcNumber to prc_number
        if (isset($payload['prcNumber'])) {
            $payload['prc_number'] = $payload['prcNumber'];
            unset($payload['prcNumber']);
        }

        // Remove Base64 string from payload before update
        unset($payload['avatar']);

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
