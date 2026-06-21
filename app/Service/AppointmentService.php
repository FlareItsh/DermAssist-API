<?php

namespace App\Service;

use App\Http\Resources\UserResource;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Diagnosis;
use App\Models\Message;
use App\Repository\AppointmentRepository;
use Carbon\Carbon;
use Illuminate\Support\Str;

class AppointmentService
{
    protected $appointmentRepository;

    protected $availabilityService;

    public function __construct(
        AppointmentRepository $appointmentRepository,
        DoctorAvailabilityService $availabilityService
    ) {
        $this->appointmentRepository = $appointmentRepository;
        $this->availabilityService = $availabilityService;
    }

    public function getAppointmentsForUser($user)
    {
        $appointments = $this->appointmentRepository->getAppointmentsForUser($user);

        $appointments->each(function ($appointment) {
            $conversation = Conversation::where('doctor_id', $appointment->doctor_id)
                ->where('patient_id', $appointment->patient_id)
                ->first();
            if ($conversation) {
                $appointment->conversation_uuid = $conversation->uuid;
            }
        });

        return $appointments;
    }

    public function createAppointment($user, array $data)
    {
        // Check availability on current time or a requested time
        $checkDate = isset($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : now();
        $availabilityCheck = $this->availabilityService->checkDoctorAvailability($data['doctor_id'], $checkDate, $user);

        $activeAppointment = Appointment::where('patient_id', $user->id)
            ->where('doctor_id', $data['doctor_id'])
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->first();

        $diagnosisId = null;

        if (isset($data['diagnosis_uuid'])) {
            $diagnosis = Diagnosis::where('uuid', $data['diagnosis_uuid'])->first();
            if ($diagnosis) {
                $diagnosisId = $diagnosis->id;
            }
        }

        // Get or Create Conversation
        $conversation = Conversation::firstOrCreate([
            'doctor_id' => $data['doctor_id'],
            'patient_id' => $user->id,
        ], [
            'uuid' => (string) Str::uuid(),
        ]);

        if ($activeAppointment) {
            // If an active appointment exists, just send the diagnosis as a follow-up
            $tag = 'DIAGNOSIS_ONLY';
            $appointmentUuid = $activeAppointment->uuid;
            $responseMessage = 'Additional clinical findings shared.';
        } else {
            // Otherwise create a new appointment request
            $activeAppointment = $this->appointmentRepository->createAppointment([
                'doctor_id' => $data['doctor_id'],
                'patient_id' => $user->id,
                'diagnosis_id' => $diagnosisId,
                'status' => 'pending',
            ]);
            $tag = 'APPOINTMENT_REQUEST';
            $appointmentUuid = $activeAppointment->uuid;
            $responseMessage = 'Appointment request sent successfully.';
        }

        // Create a Message representing this referral
        $messageContent = $data['message'] ?? '';
        if ($diagnosisId) {
            $messageContent .= "\n[{$tag}:{$appointmentUuid}:{$data['diagnosis_uuid']}]";
        }

        Message::create([
            'uuid' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'message' => $messageContent,
        ]);

        $result = [
            'message' => $responseMessage,
            'appointment' => $activeAppointment,
            'conversation_uuid' => $conversation->uuid,
        ];

        if (! $availabilityCheck['is_available']) {
            $result['doctor_availability'] = [
                'is_available' => false,
                'next_available' => $availabilityCheck['next_available'],
                'alternatives' => UserResource::collection($availabilityCheck['alternatives']),
            ];
        } else {
            $result['doctor_availability'] = [
                'is_available' => true,
            ];
        }

        return $result;
    }

    public function updateAppointmentStatus(Appointment $appointment, array $data, $user)
    {
        if ($user->role->name !== 'admin' && $user->id !== $appointment->doctor_id) {
            abort(403, 'Unauthorized action.');
        }

        $this->appointmentRepository->updateAppointment($appointment, $data);

        // Optional: send a system message in the chat
        $conversation = Conversation::where([
            'doctor_id' => $appointment->doctor_id,
            'patient_id' => $appointment->patient_id,
        ])->first();

        if ($conversation && isset($data['status'])) {
            $doctorId = $user->id;

            if ($data['status'] === 'scheduled') {
                $dateStr = Carbon::parse($appointment->scheduled_at)->format('M d, Y h:i A');
                Message::create([
                    'uuid' => (string) Str::uuid(),
                    'conversation_id' => $conversation->id,
                    'sender_id' => $doctorId,
                    'message' => "Appointment scheduled on <b>{$dateStr}</b> at <b>{$appointment->location}</b>.\n[APPOINTMENT_SCHEDULED:{$appointment->uuid}]",
                ]);
            } elseif ($data['status'] === 'declined') {
                Message::create([
                    'uuid' => (string) Str::uuid(),
                    'conversation_id' => $conversation->id,
                    'sender_id' => $doctorId,
                    'message' => "Your appointment request has been reviewed and unfortunately declined.\n[APPOINTMENT_DECLINED:{$appointment->uuid}]",
                ]);
            }
        }

        return $appointment;
    }

    public function deleteAppointment(Appointment $appointment)
    {
        return $this->appointmentRepository->deleteAppointment($appointment);
    }
}
