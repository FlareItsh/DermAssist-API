<?php

namespace App\Service;

use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Repository\ConversationRepository;
use Illuminate\Validation\ValidationException;

class ConversationService
{
    private ConversationRepository $conversationRepository;

    public function __construct(ConversationRepository $conversationRepository)
    {
        $this->conversationRepository = $conversationRepository;
    }

    public function listConversations(User $user, int $perPage = 15)
    {
        if ($user->is_doctor_registered && $user->registered_by_doctor_id) {
            $conversation = Conversation::firstOrCreate([
                'doctor_id' => $user->registered_by_doctor_id,
                'patient_id' => $user->id,
            ]);

            if ($conversation->messages()->count() === 0) {
                Message::create([
                    'conversation_id' => $conversation->id,
                    'sender_id' => $user->registered_by_doctor_id,
                    'message' => 'Welcome! Your account has been registered. You can send messages and scan findings directly here.',
                    'is_read' => false,
                ]);
            }
        }

        if ($user->role?->slug === 'doctor') {
            $registeredPatients = User::where('registered_by_doctor_id', $user->id)->get();
            foreach ($registeredPatients as $patient) {
                $conversation = Conversation::firstOrCreate([
                    'doctor_id' => $user->id,
                    'patient_id' => $patient->id,
                ]);

                if ($conversation->messages()->count() === 0) {
                    Message::create([
                        'conversation_id' => $conversation->id,
                        'sender_id' => $user->id,
                        'message' => 'Welcome! Your account has been registered. You can send messages and scan findings directly here.',
                        'is_read' => false,
                    ]);
                }
            }
        }

        $effectiveUserId = ($user->role?->slug === 'secretary' && $user->doctor_id) ? $user->doctor_id : $user->id;
        $collection = $this->conversationRepository->paginateForUser($effectiveUserId, $perPage);

        return ConversationResource::collection($collection);
    }

    public function startConversation(User $user, array $payload)
    {
        $roleSlug = tap($user->role, fn ($role) => null)?->slug;

        if (! in_array($roleSlug, ['doctor', 'patient', 'secretary'])) {
            abort(403, 'Only doctors, secretaries, and patients can start conversations.');
        }

        $doctorId = null;
        $patientId = null;

        if ($roleSlug === 'doctor' || $roleSlug === 'secretary') {
            $doctorId = ($roleSlug === 'secretary' && $user->doctor_id) ? $user->doctor_id : $user->id;
            $patientId = $payload['patient_id'] ?? null;

            if (! $patientId) {
                throw ValidationException::withMessages(['patient_id' => 'Patient ID is required.']);
            }

            $targetUser = User::with('role')->findOrFail($patientId);
            if ($targetUser->role->slug !== 'patient') {
                throw ValidationException::withMessages(['patient_id' => 'Target user is not a patient.']);
            }
        } else {
            $patientId = $user->id;
            $doctorId = $payload['doctor_id'] ?? null;

            if (! $doctorId) {
                throw ValidationException::withMessages(['doctor_id' => 'Doctor ID is required.']);
            }

            $targetUser = User::with('role')->findOrFail($doctorId);
            if ($targetUser->role->slug !== 'doctor') {
                throw ValidationException::withMessages(['doctor_id' => 'Target user is not a doctor.']);
            }
        }

        $existing = $this->conversationRepository->findExisting($doctorId, $patientId);
        if ($existing) {
            $existing->load(['doctor', 'patient']);

            return new ConversationResource($existing);
        }

        $model = $this->conversationRepository->create([
            'doctor_id' => $doctorId,
            'patient_id' => $patientId,
        ]);

        $model->load(['doctor', 'patient']);

        return new ConversationResource($model);
    }

    public function getConversation(User $user, string $uuid)
    {
        $model = $this->conversationRepository->findByUuid($uuid);

        $isDoctor = $model->doctor_id === $user->id;
        $isPatient = $model->patient_id === $user->id;
        $isSecretary = $user->role->slug === 'secretary' && $model->doctor_id === $user->doctor_id;

        if (! $isDoctor && ! $isPatient && ! $isSecretary) {
            abort(403, 'Unauthorized access to this conversation.');
        }

        return new ConversationResource($model);
    }

    public function deleteConversation(User $user, string $uuid)
    {
        $model = $this->conversationRepository->findByUuid($uuid);

        $isDoctor = $model->doctor_id === $user->id;
        $isPatient = $model->patient_id === $user->id;
        $isSecretary = $user->role->slug === 'secretary' && $model->doctor_id === $user->doctor_id;

        if (! $isDoctor && ! $isPatient && ! $isSecretary) {
            abort(403, 'Unauthorized access to this conversation.');
        }

        $this->conversationRepository->delete($uuid);

        return true;
    }
}
