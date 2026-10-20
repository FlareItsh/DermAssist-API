<?php

namespace App\Service;

use App\Console\Commands\ProcessScheduledAccountActions;
use App\Http\Resources\UserResource;
use App\Mail\PatientResetPasswordMail;
use App\Mail\ResetPasswordMail;
use App\Mail\VerifyAccountMail;
use App\Models\Appointment;
use App\Models\BlockedDevice;
use App\Models\Conversation;
use App\Models\Diagnosis;
use App\Models\DoctorVerification;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use App\Repository\UserRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserService
{
    private UserRepository $userRepository;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function login(array $payload)
    {
        ProcessScheduledAccountActions::processDueActions();

        $email = trim($payload['email'] ?? '');
        $user = $this->userRepository->findFirstByField('email', $email);
        if (! $user) {
            $user = User::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
        }

        if (! $user) {
            return response()->json(['message' => 'Invalid Credentials'], 401);
        }

        if (! Hash::check($payload['password'], $user->password)) {
            return response()->json(['message' => 'Invalid password'], 401);
        }

        if ($user->account_status === 'disabled') {
            return response()->json(['message' => 'Account has been disabled by your doctor.'], 403);
        }

        $deviceId = $payload['device_token'] ?? $payload['deviceId'] ?? request()?->header('X-Device-Id') ?? request()?->cookie('da_device_id');
        if ($deviceId) {
            $isDeviceBlocked = BlockedDevice::where('device_id', $deviceId)->exists();
            if ($isDeviceBlocked) {
                return response()->json(['message' => 'Access denied: This device has been restricted.'], 403);
            }
            if (! $user->device_token) {
                $user->update(['device_token' => $deviceId]);
            }
        }

        if (! empty($payload['cookies_accepted']) && ! $user->cookies_accepted_at) {
            $user->update(['cookies_accepted_at' => now()]);
        }

        if ($user->hasTwoFactorEnabled()) {
            $challenge = (string) Str::uuid();
            Cache::put("2fa_challenge_{$challenge}", [
                'user_id' => $user->id,
                'device_id' => $deviceId,
            ], now()->addMinutes(5));

            return response()->json([
                'two_factor_required' => true,
                'challenge' => $challenge,
            ], 200);
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

    public function listUser(int $perPage = 15, ?string $role = null, ?string $status = null, bool $recommendedOnly = false)
    {
        $collection = $this->userRepository->paginate($perPage, $role, $status, $recommendedOnly);

        return UserResource::collection($collection);
    }

    public function createUser(array $payload)
    {
        return DB::transaction(function () use ($payload) {
            $roleSlug = $payload['role'] ?? 'patient';
            $role = Role::where('slug', $roleSlug)->firstOrFail();

            $deviceToken = $payload['device_token'] ?? $payload['deviceId'] ?? request()?->header('X-Device-Id') ?? request()?->cookie('da_device_id');
            $cookiesAccepted = ! empty($payload['cookies_accepted']) || ! empty($payload['cookiesAccepted']);
            $isDoctorRegistered = ! empty($payload['is_doctor_registered']) || ! empty($payload['registered_by_doctor_id']);

            $accountStatus = 'active';
            $verificationToken = null;
            $verificationDeadline = null;

            if (! $isDoctorRegistered && $roleSlug === 'patient') {
                $accountStatus = 'pending_verification';
                $verificationToken = Str::random(40);
                $verificationDeadline = now()->addHours(48);
            }

            // Map frontend camelCase to backend snake_case
            // Ensure UUID is generated if trait doesn't pick it up for non-primary keys
            $prcSanitized = ! empty($payload['prcNumber']) ? preg_replace('/\D/', '', (string) $payload['prcNumber']) : null;

            $userData = [
                'first_name' => $payload['firstName'],
                'middle_name' => $payload['middleName'] ?? null,
                'last_name' => $payload['lastName'],
                'email' => $payload['email'],
                'contact_number' => ! empty($payload['contact_number']) ? trim((string) $payload['contact_number']) : (! empty($payload['phone']) ? trim((string) $payload['phone']) : null),
                'password' => $payload['password'],
                'affiliation' => $payload['affiliation'] ?? null,
                'role_id' => $role->id,
                'uuid' => (string) Str::uuid(),
                'prc_number' => $prcSanitized,
                'avatar_path' => null,
                'consent_dataset' => ! empty($payload['consent_dataset']),
                'terms_accepted_at' => ! empty($payload['agree_to_terms']) ? now() : null,
                'account_status' => $accountStatus,
                'device_token' => $deviceToken,
                'cookies_accepted_at' => $cookiesAccepted ? now() : null,
                'verification_token' => $verificationToken,
                'verification_deadline' => $verificationDeadline,
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
            if ($roleSlug === 'doctor') {
                $verificationData = [
                    'user_id' => $user->id,
                    'prc_number' => $prcSanitized ?: null,
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

            // Send account verification email for unverified public patients
            if ($accountStatus === 'pending_verification' && $verificationToken) {
                try {
                    Mail::to($user->email)->send(new VerifyAccountMail($user, $verificationToken));
                } catch (\Throwable $e) {
                    Log::error("Failed to send verification email to {$user->email}: ".$e->getMessage());
                }
            }

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

            if (! in_array($type, ['jpg', 'jpeg', 'gif', 'png', 'webp'])) {
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
        $user->load(['role', 'latestDoctorVerification']);

        if (! empty($payload['avatar'])) {
            $ext = 'png';
            if (preg_match('/^data:image\/(\w+);base64,/', $payload['avatar'], $match)) {
                $matchedType = strtolower($match[1]);
                $ext = $matchedType === 'jpeg' ? 'jpg' : $matchedType;
            }
            $path = 'avatars/'.Str::slug($user->first_name.'_'.$user->last_name).'_'.time().'_'.Str::random(6).'.'.$ext;

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

        // Handle doctor PRC verification details if provided
        if ($user->role?->slug === 'doctor') {
            $isVerified = $user->latestDoctorVerification?->status === DoctorVerification::STATUS_VERIFIED;
            $prcSanitized = ! empty($payload['prcNumber']) ? preg_replace('/\D/', '', (string) $payload['prcNumber']) : ($user->prc_number ?? null);
            $idPhotoPath = null;
            if (! empty($payload['idPhoto'])) {
                try {
                    $path = 'verifications/doctor_'.$user->id.'_'.time().'.png';
                    $idPhotoPath = $this->saveBase64Image($payload['idPhoto'], $path);
                } catch (\Throwable $e) {
                    Log::warning("Failed to save ID photo for doctor {$user->id}: ".$e->getMessage());
                }
            }

            if (! $isVerified && (! empty($idPhotoPath) || ! empty($prcSanitized))) {
                $verification = $user->latestDoctorVerification;
                if ($verification) {
                    $updateFields = [
                        'status' => DoctorVerification::STATUS_PENDING,
                        'rejection_reason' => null,
                    ];
                    if ($prcSanitized) {
                        $updateFields['prc_number'] = $prcSanitized;
                    }
                    if ($idPhotoPath) {
                        $updateFields['id_photo_path'] = $idPhotoPath;
                    }
                    $verification->update($updateFields);
                } else {
                    DoctorVerification::create([
                        'user_id' => $user->id,
                        'prc_number' => $prcSanitized ?? '',
                        'id_photo_path' => $idPhotoPath,
                        'status' => DoctorVerification::STATUS_PENDING,
                    ]);
                }
            }
            unset($payload['idPhoto']);
        }

        // Do not allow overwriting an existing PRC number if already verified
        if ($user->role?->slug === 'doctor' && $user->latestDoctorVerification?->status === DoctorVerification::STATUS_VERIFIED) {
            unset($payload['prcNumber'], $payload['prc_number']);
        } elseif (isset($payload['prcNumber'])) {
            $payload['prc_number'] = preg_replace('/\D/', '', (string) $payload['prcNumber']);
            unset($payload['prcNumber']);
        }

        // Remove Base64 string from payload before update
        unset($payload['avatar']);

        // Handle secure password update if requested
        if (! empty($payload['new_password']) || ! empty($payload['current_password']) || ! empty($payload['new_password_confirmation'])) {
            $validator = Validator::make($payload, [
                'current_password' => ['required', 'string'],
                'new_password' => [
                    'required',
                    'string',
                    Password::min(8)
                        ->letters()
                        ->mixedCase()
                        ->numbers()
                        ->symbols(),
                    'confirmed',
                ],
                'new_password_confirmation' => ['required', 'string'],
            ], [
                'current_password.required' => 'Please enter your current password.',
                'new_password.required' => 'New password is required.',
                'new_password.confirmed' => 'The new password confirmation does not match.',
                'new_password_confirmation.required' => 'Please confirm your new password.',
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            if (! Hash::check($payload['current_password'], $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['The current password provided is incorrect.'],
                ]);
            }

            $payload['password'] = $payload['new_password'];
            $payload['must_change_password'] = false;
            unset($payload['current_password'], $payload['new_password'], $payload['new_password_confirmation']);

            // Security Hardening: Revoke other tokens on password change
            if ($user->tokens()->exists()) {
                $currentTokenId = auth()->user()?->currentAccessToken()?->id ?? request()->user()?->currentAccessToken()?->id;
                if ($currentTokenId) {
                    $user->tokens()->where('id', '!=', $currentTokenId)->delete();
                }
            }
        }

        // Support camelCase payload keys if provided
        if (isset($payload['firstName']) && ! isset($payload['first_name'])) {
            $payload['first_name'] = $payload['firstName'];
            unset($payload['firstName']);
        }
        if (isset($payload['middleName']) && ! isset($payload['middle_name'])) {
            $payload['middle_name'] = $payload['middleName'];
            unset($payload['middleName']);
        }
        if (isset($payload['lastName']) && ! isset($payload['last_name'])) {
            $payload['last_name'] = $payload['lastName'];
            unset($payload['lastName']);
        }

        // Sanitize and validate name fields
        if (array_key_exists('first_name', $payload)) {
            $fn = is_string($payload['first_name']) ? trim(strip_tags($payload['first_name'])) : '';
            if ($fn !== '') {
                if (preg_match('/[0-9]/', $fn)) {
                    throw ValidationException::withMessages([
                        'first_name' => ['First name cannot contain numbers.'],
                    ]);
                }
                if (mb_strlen($fn) < 2 || mb_strlen($fn) > 50 || ! preg_match('/^[\pL\s\-\'.]+$/u', $fn)) {
                    throw ValidationException::withMessages([
                        'first_name' => ['First name may only contain letters, spaces, hyphens, and apostrophes (min 2, max 50 characters).'],
                    ]);
                }
                $payload['first_name'] = $fn;
            }
        }

        if (array_key_exists('middle_name', $payload)) {
            $mn = is_string($payload['middle_name']) ? trim(strip_tags($payload['middle_name'])) : '';
            if ($mn !== '') {
                if (preg_match('/[0-9]/', $mn)) {
                    throw ValidationException::withMessages([
                        'middle_name' => ['Middle name cannot contain numbers.'],
                    ]);
                }
                if (mb_strlen($mn) > 50 || ! preg_match('/^[\pL\s\-\'.]+$/u', $mn)) {
                    throw ValidationException::withMessages([
                        'middle_name' => ['Middle name may only contain letters, spaces, hyphens, and apostrophes (max 50 characters).'],
                    ]);
                }
                $payload['middle_name'] = $mn;
            } else {
                $payload['middle_name'] = null;
            }
        }

        if (array_key_exists('last_name', $payload)) {
            $ln = is_string($payload['last_name']) ? trim(strip_tags($payload['last_name'])) : '';
            if ($ln !== '') {
                if (preg_match('/[0-9]/', $ln)) {
                    throw ValidationException::withMessages([
                        'last_name' => ['Last name cannot contain numbers.'],
                    ]);
                }
                if (mb_strlen($ln) < 2 || mb_strlen($ln) > 50 || ! preg_match('/^[\pL\s\-\'.]+$/u', $ln)) {
                    throw ValidationException::withMessages([
                        'last_name' => ['Last name may only contain letters, spaces, hyphens, and apostrophes (min 2, max 50 characters).'],
                    ]);
                }
                $payload['last_name'] = $ln;
            }
        }

        // Sanitize and validate date_of_birth (and age)
        if (array_key_exists('date_of_birth', $payload) || array_key_exists('dateOfBirth', $payload)) {
            $rawDob = $payload['date_of_birth'] ?? $payload['dateOfBirth'] ?? null;
            if ($rawDob === null || trim((string) $rawDob) === '') {
                $payload['date_of_birth'] = null;
            } else {
                try {
                    $dobCarbon = Carbon::parse($rawDob);
                    if ($dobCarbon->isFuture()) {
                        throw ValidationException::withMessages([
                            'date_of_birth' => ['Date of birth cannot be in the future.'],
                        ]);
                    }
                    if ($dobCarbon->diffInYears(now()) > 130) {
                        throw ValidationException::withMessages([
                            'date_of_birth' => ['Date of birth cannot exceed 130 years ago.'],
                        ]);
                    }
                    $payload['date_of_birth'] = $dobCarbon->format('Y-m-d');
                    $payload['age'] = $dobCarbon->age;
                } catch (\Exception $e) {
                    if ($e instanceof ValidationException) {
                        throw $e;
                    }
                    throw ValidationException::withMessages([
                        'date_of_birth' => ['Date of birth must be a valid date.'],
                    ]);
                }
            }
        } elseif (array_key_exists('age', $payload)) {
            if ($payload['age'] === null || $payload['age'] === '') {
                $payload['age'] = null;
                $payload['date_of_birth'] = null;
            } else {
                $rawAge = (string) $payload['age'];
                if (str_contains($rawAge, '-') || (is_numeric($rawAge) && (float) $rawAge < 0)) {
                    throw ValidationException::withMessages([
                        'age' => ['Age must be a valid positive number.'],
                    ]);
                }
                $cleanAge = preg_replace('/\D/', '', $rawAge);
                if ($cleanAge === '') {
                    $payload['age'] = null;
                    $payload['date_of_birth'] = null;
                } else {
                    $ageInt = (int) $cleanAge;
                    if ($ageInt > 130) {
                        throw ValidationException::withMessages([
                            'age' => ['Age may not exceed 130.'],
                        ]);
                    }
                    $payload['age'] = $ageInt;
                    $payload['date_of_birth'] = now()->subYears($ageInt)->startOfYear()->toDateString();
                }
            }
        }

        // Sanitize and normalize gender if provided
        if (array_key_exists('gender', $payload)) {
            if ($payload['gender'] === null || trim((string) $payload['gender']) === '') {
                $payload['gender'] = null;
            } else {
                $rawGender = trim((string) $payload['gender']);
                $lower = strtolower($rawGender);
                if (in_array($lower, ['not set', 'not_set', 'none', 'n/a', 'unset'])) {
                    $payload['gender'] = null;
                } elseif ($lower === 'male') {
                    $payload['gender'] = 'Male';
                } elseif ($lower === 'female') {
                    $payload['gender'] = 'Female';
                } elseif ($lower === 'other') {
                    $payload['gender'] = 'Other';
                } elseif ($lower === 'prefer_not_to_say' || $lower === 'prefer not to say') {
                    $payload['gender'] = 'Prefer not to say';
                } else {
                    $payload['gender'] = ucfirst($rawGender);
                }
            }
        }

        // Validate and sanitize contact number if provided
        $phoneInput = $payload['contact_number'] ?? $payload['contactNumber'] ?? $payload['phone'] ?? null;
        if (array_key_exists('contact_number', $payload) || array_key_exists('contactNumber', $payload) || array_key_exists('phone', $payload)) {
            if ($phoneInput === null || trim((string) $phoneInput) === '') {
                $payload['contact_number'] = null;
            } else {
                $cleanPhone = trim(strip_tags((string) $phoneInput));
                if (! preg_match('/^\+?[0-9\s\-\(\)]{7,25}$/', $cleanPhone)) {
                    throw ValidationException::withMessages([
                        'contact_number' => ['Contact number must be a valid phone number.'],
                    ]);
                }
                $payload['contact_number'] = $cleanPhone;
            }
            unset($payload['contactNumber'], $payload['phone']);
        }

        // Validate and sanitize email if provided (e.g. doctor-registered patient setting their personal email)
        if (array_key_exists('email', $payload) && ! empty($payload['email'])) {
            $cleanEmail = trim(strtolower((string) $payload['email']));
            if (! filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages([
                    'email' => ['Please enter a valid email address.'],
                ]);
            }

            $emailExists = User::where('email', $cleanEmail)
                ->where('id', '!=', $user->id)
                ->exists();

            if ($emailExists) {
                throw ValidationException::withMessages([
                    'email' => ['This email address is already in use by another account.'],
                ]);
            }

            if ($user->email !== $cleanEmail) {
                $payload['email'] = $cleanEmail;
                if ($user->is_doctor_registered || str_starts_with($user->email ?? '', 'walkin.')) {
                    $payload['email_verified_at'] = now();
                    $payload['account_status'] = 'active';
                    $payload['verification_deadline'] = null;
                }
            } else {
                unset($payload['email']);
            }
        }

        // Strip null/empty values for non-nullable columns so that
        // Laravel's ConvertEmptyStringsToNull middleware doesn't cause
        // integrity constraint violations when a field wasn't submitted.
        $nonNullable = ['first_name', 'last_name', 'email'];
        foreach ($nonNullable as $field) {
            if (array_key_exists($field, $payload) && ($payload[$field] === null || $payload[$field] === '')) {
                unset($payload[$field]);
            }
        }

        if (array_key_exists('consent_dataset', $payload)) {
            $payload['consent_dataset'] = (bool) $payload['consent_dataset'];
        }
        if (! empty($payload['agree_to_terms']) && ! $user->terms_accepted_at) {
            $payload['terms_accepted_at'] = now();
        }

        $model = $this->userRepository->update($uuid, $payload);

        // Reset verification status for doctors if they were declined or changed key identification data
        if ($user->role->slug === 'doctor' && $user->latestDoctorVerification) {
            $sensitiveFields = ['first_name', 'last_name', 'prc_number'];
            $hasChangedSensitiveField = false;
            foreach ($sensitiveFields as $field) {
                if (isset($payload[$field]) && $payload[$field] !== $user->$field) {
                    $hasChangedSensitiveField = true;
                    break;
                }
            }

            if ($user->latestDoctorVerification->status === 'declined' || $hasChangedSensitiveField) {
                $user->latestDoctorVerification->update([
                    'status' => 'pending',
                    'rejection_reason' => null,
                ]);
            }
        }

        return new UserResource($model);
    }

    public function deleteUser(string $uuid)
    {
        $user = $this->userRepository->findByUuid($uuid);
        $user->tokens()->delete();
        Conversation::where('patient_id', $user->id)->orWhere('doctor_id', $user->id)->delete();
        Appointment::where('patient_id', $user->id)->orWhere('doctor_id', $user->id)->delete();
        Diagnosis::where('user_uuid', $user->uuid)->delete();
        $user->delete();

        return true;
    }

    public function restoreUser(string $uuid)
    {
        $model = $this->userRepository->restore($uuid);

        return new UserResource($model);
    }

    public function createDoctorRegisteredPatient(array $payload, User $doctor)
    {
        return DB::transaction(function () use ($payload, $doctor) {
            $role = Role::where('slug', 'patient')->firstOrFail();

            $actualDoctorId = ($doctor->role?->slug === 'secretary' && $doctor->doctor_id)
                ? $doctor->doctor_id
                : $doctor->id;

            $isWalkIn = empty($payload['email']) || ! empty($payload['is_walk_in']);

            $email = ! empty($payload['email'])
                ? trim((string) $payload['email'])
                : 'walkin.'.(string) Str::uuid().'@clinic.local';

            // 1. Check if patient already exists by valid UUID
            if (! empty($payload['uuid']) && $payload['uuid'] !== 'pending_new_patient' && $payload['uuid'] !== 'walk_in_guest') {
                $existingByUuid = User::where('uuid', $payload['uuid'])->first();
                if ($existingByUuid) {
                    if (! $existingByUuid->registered_by_doctor_id) {
                        $existingByUuid->update([
                            'is_doctor_registered' => true,
                            'registered_by_doctor_id' => $actualDoctorId,
                        ]);
                    }
                    $existingByUuid->load('role');

                    Conversation::firstOrCreate([
                        'doctor_id' => $actualDoctorId,
                        'patient_id' => $existingByUuid->id,
                    ]);

                    return response()->json([
                        'user' => new UserResource($existingByUuid),
                        'message' => 'Existing patient record linked.',
                    ], 200);
                }
            }

            // 2. Check if patient already exists by email (for non-walkins)
            if (! $isWalkIn && ! empty($payload['email'])) {
                $existingByEmail = User::where('email', $email)->first();
                if ($existingByEmail) {
                    if (! $existingByEmail->registered_by_doctor_id) {
                        $existingByEmail->update([
                            'is_doctor_registered' => true,
                            'registered_by_doctor_id' => $actualDoctorId,
                        ]);
                    }
                    $existingByEmail->load('role');

                    Conversation::firstOrCreate([
                        'doctor_id' => $actualDoctorId,
                        'patient_id' => $existingByEmail->id,
                    ]);

                    return response()->json([
                        'user' => new UserResource($existingByEmail),
                        'message' => 'Existing patient record linked.',
                    ], 200);
                }
            }

            $password = ! empty($payload['password'])
                ? $payload['password']
                : Str::random(32);

            $firstName = ! empty($payload['firstName']) ? trim((string) $payload['firstName']) : (! empty($payload['first_name']) ? trim((string) $payload['first_name']) : '');
            $lastName = ! empty($payload['lastName']) ? trim((string) $payload['lastName']) : (! empty($payload['last_name']) ? trim((string) $payload['last_name']) : '');
            $middleName = ! empty($payload['middleName']) ? trim((string) $payload['middleName']) : (! empty($payload['middle_name']) ? trim((string) $payload['middle_name']) : null);
            $dob = ! empty($payload['date_of_birth']) ? $payload['date_of_birth'] : null;
            $ageVal = ! empty($payload['age']) ? (int) $payload['age'] : null;

            $userData = [
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'last_name' => $lastName,
                'email' => $email,
                'password' => $password,
                'date_of_birth' => $dob,
                'gender' => ! empty($payload['gender']) ? $payload['gender'] : null,
                'contact_number' => ! empty($payload['contact_number']) ? trim((string) $payload['contact_number']) : (! empty($payload['phone']) ? trim((string) $payload['phone']) : null),
                'age' => $ageVal,
                'street' => ! empty($payload['street']) ? $payload['street'] : null,
                'barangay' => ! empty($payload['barangay']) ? $payload['barangay'] : null,
                'city' => ! empty($payload['city']) ? $payload['city'] : null,
                'province' => ! empty($payload['province']) ? $payload['province'] : null,
                'role_id' => $role->id,
                'uuid' => (string) Str::uuid(),
                'is_doctor_registered' => true,
                'registered_by_doctor_id' => $actualDoctorId,
                'account_status' => 'active',
                'verification_token' => null,
                'verification_deadline' => null,
                'email_verified_at' => $isWalkIn ? null : now(),
                'must_change_password' => ! $isWalkIn,
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

            $user->load('role');

            $conversation = Conversation::firstOrCreate([
                'doctor_id' => $actualDoctorId,
                'patient_id' => $user->id,
            ]);

            if ($conversation->messages()->count() === 0) {
                Message::create([
                    'conversation_id' => $conversation->id,
                    'sender_id' => $actualDoctorId,
                    'message' => 'Welcome! Your account has been registered. You can send messages and scan findings directly here.',
                    'is_read' => false,
                ]);
            }

            return response()->json([
                'user' => new UserResource($user),
            ], 201);
        });
    }

    public function listDoctorPatients(string $doctorUuid, int $perPage = 15)
    {
        ProcessScheduledAccountActions::processDueActions();

        $doctor = $this->userRepository->findByUuid($doctorUuid);
        $collection = $this->userRepository->paginateDoctorPatients($doctor->id, $perPage, $doctorUuid);

        return UserResource::collection($collection);
    }

    public function setAccountStatus(string $patientUuid, string $status)
    {
        $user = $this->userRepository->findByUuid($patientUuid);

        $user->update([
            'account_status' => $status,
        ]);

        // If disabling, also revoke tokens so they are immediately logged out
        if ($status === 'disabled') {
            $user->tokens()->delete();
        }

        return new UserResource($user);
    }

    public function scheduleAccountAction(string $patientUuid, string $action, ?string $scheduledAt)
    {
        $user = $this->userRepository->findByUuid($patientUuid);

        $user->update([
            'account_action' => $action,
            'account_action_scheduled_at' => $scheduledAt,
        ]);

        return new UserResource($user);
    }

    public function cancelScheduledAction(string $patientUuid)
    {
        $user = $this->userRepository->findByUuid($patientUuid);

        $user->update([
            'account_action' => null,
            'account_action_scheduled_at' => null,
        ]);

        return new UserResource($user);
    }

    private function resolveDoctorId(User $operator): int
    {
        if ($operator->role?->slug === 'secretary' && $operator->doctor_id) {
            return (int) $operator->doctor_id;
        }

        return (int) $operator->id;
    }

    private function authorizeDoctorPatientAccess(User $operator, User $patient): void
    {
        if ($operator->role?->slug === 'admin') {
            return;
        }

        $doctorId = $this->resolveDoctorId($operator);

        if ($patient->registered_by_doctor_id && (int) $patient->registered_by_doctor_id === $doctorId) {
            return;
        }

        $hasAppointment = Appointment::where('patient_id', $patient->id)
            ->where('doctor_id', $doctorId)
            ->exists();
        if ($hasAppointment) {
            return;
        }

        $hasConversation = Conversation::where('patient_id', $patient->id)
            ->where('doctor_id', $doctorId)
            ->exists();
        if ($hasConversation) {
            return;
        }

        abort(403, 'You are not authorized to manage this patient record.');
    }

    public function updateDoctorPatient(string $patientUuid, array $payload, User $doctorOrSecretary)
    {
        $patient = $this->userRepository->findByUuid($patientUuid);
        if (! $patient || $patient->role?->slug !== 'patient') {
            throw ValidationException::withMessages([
                'patient' => ['Patient record not found.'],
            ]);
        }

        $this->authorizeDoctorPatientAccess($doctorOrSecretary, $patient);

        $validator = Validator::make($payload, [
            'first_name' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[\pL\s\-\'.]+$/u'],
            'middle_name' => ['nullable', 'string', 'max:50', 'regex:/^[\pL\s\-\'.]+$/u'],
            'last_name' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[\pL\s\-\'.]+$/u'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$patient->id],
            'contact_number' => ['nullable', 'string', 'regex:/^\+?[0-9\s\-\(\)]{7,25}$/'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['nullable', 'string', 'max:50'],
            'street' => ['nullable', 'string', 'max:255'],
            'barangay' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
        ], [
            'first_name.regex' => 'First name may only contain letters, spaces, hyphens, and apostrophes.',
            'middle_name.regex' => 'Middle name may only contain letters, spaces, hyphens, and apostrophes.',
            'last_name.regex' => 'Last name may only contain letters, spaces, hyphens, and apostrophes.',
            'email.unique' => 'The email address is already taken by another account.',
            'date_of_birth.before_or_equal' => 'Date of birth cannot be in the future.',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

        if (! empty($validated['date_of_birth'])) {
            $dob = Carbon::parse($validated['date_of_birth']);
            $validated['age'] = $dob->age;
            $validated['date_of_birth'] = $dob->format('Y-m-d');
        } elseif (array_key_exists('age', $payload) && is_numeric($payload['age'])) {
            $validated['age'] = (int) $payload['age'];
        }

        $wasWalkIn = str_starts_with($patient->email ?? '', 'walkin.') && str_ends_with($patient->email ?? '', '@clinic.local');
        if ($wasWalkIn && ! str_starts_with($validated['email'], 'walkin.')) {
            $validated['is_doctor_registered'] = true;
            if (! $patient->registered_by_doctor_id) {
                $validated['registered_by_doctor_id'] = $this->resolveDoctorId($doctorOrSecretary);
            }
        }

        $patient->update($validated);

        return new UserResource($patient->fresh(['role', 'registeredByDoctor']));
    }

    public function sendPatientResetLink(string $patientUuid, User $doctorOrSecretary)
    {
        $patient = $this->userRepository->findByUuid($patientUuid);
        if (! $patient || $patient->role?->slug !== 'patient') {
            throw ValidationException::withMessages([
                'patient' => ['Patient record not found.'],
            ]);
        }

        $this->authorizeDoctorPatientAccess($doctorOrSecretary, $patient);

        $isWalkIn = str_starts_with($patient->email ?? '', 'walkin.') && str_ends_with($patient->email ?? '', '@clinic.local');
        if ($isWalkIn || empty($patient->email)) {
            return response()->json([
                'message' => 'This walk-in patient does not have a real email address. Please update their email first or use a temporary access code.',
            ], 422);
        }

        $token = PasswordBroker::broker()->createToken($patient);

        $requesterName = $doctorOrSecretary->first_name.' '.$doctorOrSecretary->last_name;
        if ($doctorOrSecretary->role?->slug === 'doctor') {
            $requesterName = 'Dr. '.$requesterName;
        }

        try {
            Mail::to($patient->email)->send(new PatientResetPasswordMail($patient, $token, $requesterName));
        } catch (\Throwable $e) {
            Log::error("Failed to send password reset email to {$patient->email}: ".$e->getMessage());

            return response()->json([
                'message' => 'Could not dispatch password reset email. Please verify mail configuration or issue a temporary password.',
            ], 500);
        }

        return response()->json([
            'message' => "Secure password reset link has been dispatched to {$patient->email}.",
        ], 200);
    }

    public function issuePatientTemporaryPassword(string $patientUuid, array $payload, User $doctorOrSecretary)
    {
        $patient = $this->userRepository->findByUuid($patientUuid);
        if (! $patient || $patient->role?->slug !== 'patient') {
            throw ValidationException::withMessages([
                'patient' => ['Patient record not found.'],
            ]);
        }

        $this->authorizeDoctorPatientAccess($doctorOrSecretary, $patient);

        $tempPassword = ! empty($payload['temporary_password']) ? trim((string) $payload['temporary_password']) : null;
        if ($tempPassword) {
            if (strlen($tempPassword) < 8) {
                throw ValidationException::withMessages([
                    'temporary_password' => ['Temporary password must be at least 8 characters long.'],
                ]);
            }
        } else {
            $tempPassword = 'Derm-'.Str::random(4).'-'.Str::random(4);
        }

        $patient->password = $tempPassword;
        $patient->must_change_password = true;
        $patient->save();

        $patient->tokens()->delete();

        return response()->json([
            'message' => 'Temporary password issued successfully. The patient will be required to choose a new private password upon next login.',
            'temporary_password' => $tempPassword,
            'must_change_password' => true,
        ], 200);
    }

    public function sendForgotPasswordLink(string $email)
    {
        $normalizedEmail = strtolower(trim($email));
        $user = User::whereRaw('LOWER(email) = ?', [$normalizedEmail])->first();

        if (! $user) {
            return response()->json([
                'message' => "If an account is associated with {$normalizedEmail}, a password reset link has been dispatched.",
            ], 200);
        }

        $isWalkIn = str_starts_with($user->email ?? '', 'walkin.') && str_ends_with($user->email ?? '', '@clinic.local');
        if ($isWalkIn || empty($user->email)) {
            return response()->json([
                'message' => 'This account does not have a registered external email address. Please contact your attending clinic.',
            ], 422);
        }

        if ($user->account_status === 'disabled') {
            return response()->json([
                'message' => 'This account is currently disabled. Please contact clinic administration or support.',
            ], 403);
        }

        $token = PasswordBroker::broker()->createToken($user);

        try {
            Mail::to($user->email)->send(new ResetPasswordMail($user, $token));
        } catch (\Throwable $e) {
            Log::error("Failed to send password reset email to {$user->email}: ".$e->getMessage());

            return response()->json([
                'message' => 'Could not dispatch password reset email at this time. Please try again shortly.',
            ], 500);
        }

        return response()->json([
            'message' => "A secure password reset link has been dispatched to {$user->email}.",
        ], 200);
    }

    public function resetPasswordWithToken(array $payload)
    {
        $user = User::whereRaw('LOWER(email) = ?', [strtolower(trim($payload['email']))])->first();
        if (! $user) {
            return response()->json([
                'message' => 'We could not find an account with that email address.',
            ], 422);
        }

        if (! PasswordBroker::broker()->tokenExists($user, $payload['token'])) {
            return response()->json([
                'message' => 'The password reset token is invalid or has expired. Please request a new link.',
            ], 422);
        }

        $user->password = $payload['password'];
        $user->must_change_password = false;
        $user->save();

        PasswordBroker::broker()->deleteToken($user);
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Your password has been reset successfully. You can now log in with your new password.',
        ], 200);
    }

    public function listDoctorSecretaries(User $doctor)
    {
        if ($doctor->role?->slug !== 'doctor') {
            abort(403, 'Unauthorized. Doctor role required.');
        }

        $secretaries = $this->userRepository->getDoctorSecretaries($doctor->id);

        return UserResource::collection($secretaries);
    }

    public function createDoctorSecretary(User $doctor, array $validated)
    {
        if ($doctor->role?->slug !== 'doctor') {
            abort(403, 'Unauthorized. Doctor role required.');
        }

        if (! $doctor->canHaveSecretary()) {
            abort(403, 'Your current subscription plan does not support secretary accounts. Please upgrade to a plan that includes secretary management.');
        }

        $maxSecretaries = $doctor->getMaxSecretaries();
        if ($maxSecretaries !== null) {
            $currentSecretariesCount = $this->userRepository->getDoctorSecretaries($doctor->id)->count();
            if ($currentSecretariesCount >= $maxSecretaries) {
                abort(403, "You have reached your plan limit of {$maxSecretaries} secretary account(s). Please upgrade your subscription to add more.");
            }
        }

        return DB::transaction(function () use ($validated, $doctor) {
            if (array_key_exists('affiliation', $validated)) {
                if (is_array($validated['affiliation'])) {
                    $clean = array_values(array_filter(array_map('trim', $validated['affiliation'])));
                    $validated['affiliation'] = ! empty($clean) ? implode(', ', $clean) : null;
                } elseif ($validated['affiliation'] !== null) {
                    $trimmed = trim(strip_tags((string) $validated['affiliation']));
                    $validated['affiliation'] = $trimmed !== '' ? $trimmed : null;
                } else {
                    $validated['affiliation'] = null;
                }
            }

            $secretary = $this->userRepository->createDoctorSecretary($validated, $doctor->id);

            return response()->json([
                'message' => 'Secretary created successfully.',
                'data' => new UserResource($secretary),
            ], 201);
        });
    }

    public function updateDoctorSecretary(User $doctor, string $uuid, array $validated)
    {
        if ($doctor->role?->slug !== 'doctor') {
            abort(403, 'Unauthorized. Doctor role required.');
        }

        $secretary = User::where('uuid', $uuid)
            ->where('doctor_id', $doctor->id)
            ->firstOrFail();

        $updateData = [];

        if (array_key_exists('firstName', $validated) || array_key_exists('first_name', $validated)) {
            $fn = trim(strip_tags((string) ($validated['firstName'] ?? $validated['first_name'] ?? '')));
            if ($fn !== '') {
                if (preg_match('/[0-9]/', $fn)) {
                    throw ValidationException::withMessages([
                        'firstName' => ['First name cannot contain numbers.'],
                    ]);
                }
                if (mb_strlen($fn) < 2 || mb_strlen($fn) > 50 || ! preg_match('/^[\pL\s\-\'.]+$/u', $fn)) {
                    throw ValidationException::withMessages([
                        'firstName' => ['First name may only contain letters, spaces, hyphens, and apostrophes (min 2, max 50 characters).'],
                    ]);
                }
                $updateData['first_name'] = $fn;
            }
        }

        if (array_key_exists('middleName', $validated) || array_key_exists('middle_name', $validated)) {
            $mnRaw = $validated['middleName'] ?? $validated['middle_name'] ?? null;
            if ($mnRaw === null || trim((string) $mnRaw) === '') {
                $updateData['middle_name'] = null;
            } else {
                $mn = trim(strip_tags((string) $mnRaw));
                if (preg_match('/[0-9]/', $mn)) {
                    throw ValidationException::withMessages([
                        'middleName' => ['Middle name cannot contain numbers.'],
                    ]);
                }
                if (mb_strlen($mn) > 50 || ! preg_match('/^[\pL\s\-\'.]+$/u', $mn)) {
                    throw ValidationException::withMessages([
                        'middleName' => ['Middle name may only contain letters, spaces, hyphens, and apostrophes (max 50 characters).'],
                    ]);
                }
                $updateData['middle_name'] = $mn;
            }
        }

        if (array_key_exists('lastName', $validated) || array_key_exists('last_name', $validated)) {
            $ln = trim(strip_tags((string) ($validated['lastName'] ?? $validated['last_name'] ?? '')));
            if ($ln !== '') {
                if (preg_match('/[0-9]/', $ln)) {
                    throw ValidationException::withMessages([
                        'lastName' => ['Last name cannot contain numbers.'],
                    ]);
                }
                if (mb_strlen($ln) < 2 || mb_strlen($ln) > 50 || ! preg_match('/^[\pL\s\-\'.]+$/u', $ln)) {
                    throw ValidationException::withMessages([
                        'lastName' => ['Last name may only contain letters, spaces, hyphens, and apostrophes (min 2, max 50 characters).'],
                    ]);
                }
                $updateData['last_name'] = $ln;
            }
        }

        if (array_key_exists('email', $validated)) {
            $updateData['email'] = trim(strtolower((string) $validated['email']));
        }

        if (array_key_exists('affiliation', $validated)) {
            if (is_array($validated['affiliation'])) {
                $clean = array_values(array_filter(array_map('trim', $validated['affiliation'])));
                $updateData['affiliation'] = ! empty($clean) ? implode(', ', $clean) : null;
            } elseif ($validated['affiliation'] !== null) {
                $trimmed = trim(strip_tags((string) $validated['affiliation']));
                $updateData['affiliation'] = $trimmed !== '' ? $trimmed : null;
            } else {
                $updateData['affiliation'] = null;
            }
        }

        if (array_key_exists('date_of_birth', $validated) || array_key_exists('dateOfBirth', $validated)) {
            $rawDob = $validated['date_of_birth'] ?? $validated['dateOfBirth'] ?? null;
            if ($rawDob === null || trim((string) $rawDob) === '') {
                $updateData['date_of_birth'] = null;
                $updateData['age'] = null;
            } else {
                try {
                    $dobCarbon = Carbon::parse($rawDob);
                    if ($dobCarbon->isFuture()) {
                        throw ValidationException::withMessages([
                            'date_of_birth' => ['Date of birth cannot be in the future.'],
                        ]);
                    }
                    if ($dobCarbon->diffInYears(now()) > 130) {
                        throw ValidationException::withMessages([
                            'date_of_birth' => ['Date of birth cannot exceed 130 years ago.'],
                        ]);
                    }
                    $updateData['date_of_birth'] = $dobCarbon->format('Y-m-d');
                    $updateData['age'] = $dobCarbon->age;
                } catch (\Exception $e) {
                    if ($e instanceof ValidationException) {
                        throw $e;
                    }
                    throw ValidationException::withMessages([
                        'date_of_birth' => ['Date of birth must be a valid date.'],
                    ]);
                }
            }
        } elseif (array_key_exists('age', $validated)) {
            if ($validated['age'] === null || $validated['age'] === '') {
                $updateData['age'] = null;
                $updateData['date_of_birth'] = null;
            } else {
                $rawAge = (string) $validated['age'];
                if (str_contains($rawAge, '-') || (is_numeric($rawAge) && (float) $rawAge < 0)) {
                    throw ValidationException::withMessages([
                        'age' => ['Age must be a valid positive number.'],
                    ]);
                }
                $cleanAge = preg_replace('/\D/', '', $rawAge);
                if ($cleanAge === '') {
                    $updateData['age'] = null;
                    $updateData['date_of_birth'] = null;
                } else {
                    $ageInt = (int) $cleanAge;
                    if ($ageInt > 130) {
                        throw ValidationException::withMessages([
                            'age' => ['Age may not exceed 130.'],
                        ]);
                    }
                    $updateData['age'] = $ageInt;
                    $updateData['date_of_birth'] = now()->subYears($ageInt)->startOfYear()->toDateString();
                }
            }
        }

        if (array_key_exists('gender', $validated)) {
            if ($validated['gender'] === null || trim((string) $validated['gender']) === '') {
                $updateData['gender'] = null;
            } else {
                $rawGender = trim((string) $validated['gender']);
                $lower = strtolower($rawGender);
                if (in_array($lower, ['not set', 'not_set', 'none', 'n/a', 'unset'])) {
                    $updateData['gender'] = null;
                } elseif ($lower === 'male') {
                    $updateData['gender'] = 'Male';
                } elseif ($lower === 'female') {
                    $updateData['gender'] = 'Female';
                } elseif ($lower === 'other') {
                    $updateData['gender'] = 'Other';
                } elseif ($lower === 'prefer_not_to_say' || $lower === 'prefer not to say') {
                    $updateData['gender'] = 'Prefer not to say';
                } else {
                    $updateData['gender'] = ucfirst($rawGender);
                }
            }
        }

        if (array_key_exists('contact_number', $validated) || array_key_exists('phone', $validated)) {
            $rawPhone = $validated['contact_number'] ?? $validated['phone'] ?? null;
            if ($rawPhone === null || trim((string) $rawPhone) === '') {
                $updateData['contact_number'] = null;
            } else {
                $cleanPhone = trim(strip_tags((string) $rawPhone));
                if (! preg_match('/^\+?[0-9\s\-\(\)]{7,25}$/', $cleanPhone)) {
                    throw ValidationException::withMessages([
                        'contact_number' => ['Contact number must be a valid phone number.'],
                    ]);
                }
                $updateData['contact_number'] = $cleanPhone;
            }
        }

        if (! empty($validated['password'])) {
            if (strlen($validated['password']) < 8) {
                throw ValidationException::withMessages([
                    'password' => ['Password must be at least 8 characters long.'],
                ]);
            }
            $updateData['password'] = Hash::make($validated['password']);
            $secretary->tokens()->delete();
        }

        $updated = $this->userRepository->updateDoctorSecretary($uuid, $doctor->id, $updateData);

        return response()->json([
            'message' => 'Secretary updated successfully.',
            'data' => new UserResource($updated),
        ], 200);
    }

    public function deleteDoctorSecretary(User $doctor, string $uuid)
    {
        if ($doctor->role?->slug !== 'doctor') {
            abort(403, 'Unauthorized. Doctor role required.');
        }

        $this->userRepository->deleteDoctorSecretary($uuid, $doctor->id);

        return response()->json([
            'message' => 'Secretary removed successfully.',
        ], 200);
    }

    public function verifyAccount(string $token): array
    {
        $user = User::where('verification_token', $token)->first();

        if (! $user) {
            return [
                'status' => false,
                'message' => 'Invalid or expired verification token.',
            ];
        }

        if ($user->verification_deadline && $user->verification_deadline->isPast()) {
            return [
                'status' => false,
                'message' => 'Verification deadline has passed. Please resend a new verification email.',
            ];
        }

        $user->update([
            'account_status' => 'active',
            'email_verified_at' => now(),
            'verification_token' => null,
            'verification_deadline' => null,
        ]);

        return [
            'status' => true,
            'message' => 'Account successfully verified!',
            'user' => new UserResource($user),
        ];
    }

    public function resendVerification(User $user): array
    {
        if ($user->account_status !== 'pending_verification') {
            return [
                'status' => false,
                'message' => 'Account is already verified or active.',
            ];
        }

        $token = Str::random(40);
        $user->update([
            'verification_token' => $token,
            'verification_deadline' => now()->addHours(48),
        ]);

        try {
            Mail::to($user->email)->send(new VerifyAccountMail($user, $token));
        } catch (\Throwable $e) {
            Log::error("Failed to resend verification email to {$user->email}: ".$e->getMessage());
        }

        return [
            'status' => true,
            'message' => 'A new verification link has been sent to your email.',
            'verification_token' => $token,
            'verification_deadline' => $user->verification_deadline?->toIso8601String(),
        ];
    }

    public function blockDevice(string $deviceId, ?int $userId = null, ?string $reason = null, ?int $blockedBy = null): BlockedDevice
    {
        return BlockedDevice::firstOrCreate(
            ['device_id' => $deviceId],
            [
                'user_id' => $userId,
                'reason' => $reason ?? 'Administrative security restriction',
                'blocked_by' => $blockedBy,
            ]
        );
    }

    public function unblockDevice(string $deviceId): bool
    {
        return (bool) BlockedDevice::where('device_id', $deviceId)->delete();
    }
}
