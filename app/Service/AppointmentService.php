<?php

namespace App\Service;

use App\Http\Resources\UserResource;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Diagnosis;
use App\Models\Message;
use App\Models\User;
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
            ->whereIn('status', ['pending', 'scheduled'])
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

    public function checkAppointmentConflict(int $doctorId, $start, $end = null, ?int $excludeId = null): void
    {
        if (! $start) {
            return;
        }

        $startTime = Carbon::parse($start);
        $endTime = $end ? Carbon::parse($end) : (clone $startTime)->addHour();

        $existingAppointments = Appointment::where('doctor_id', $doctorId)
            ->whereIn('status', ['scheduled', 'reschedule_proposed', 'reschedule_requested'])
            ->whereNotNull('scheduled_at')
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->get();

        foreach ($existingAppointments as $appt) {
            $existingStart = Carbon::parse($appt->scheduled_at);
            $existingEnd = $appt->scheduled_end_at ? Carbon::parse($appt->scheduled_end_at) : (clone $existingStart)->addHour();

            if ($startTime->lt($existingEnd) && $endTime->gt($existingStart)) {
                $formattedStart = $existingStart->format('g:i A');
                $formattedEnd = $existingEnd->format('g:i A');
                $formattedDate = $existingStart->format('M d, Y');
                abort(422, "Conflict detected: The doctor already has an appointment scheduled on {$formattedDate} from {$formattedStart} to {$formattedEnd}. Please select a different time slot.");
            }
        }
    }

    public function updateAppointmentStatus(Appointment $appointment, array $data, $user)
    {
        if ($user->role->name !== 'admin' && $user->id !== $appointment->doctor_id && $user->id !== $appointment->patient_id) {
            abort(403, 'Unauthorized action.');
        }

        $newStatus = $data['status'] ?? $appointment->status;
        $newStart = $data['scheduled_at'] ?? $appointment->scheduled_at;
        $newEnd = $data['scheduled_end_at'] ?? $appointment->scheduled_end_at;

        if ($newStatus === 'scheduled' && $newStart) {
            $this->checkAppointmentConflict($appointment->doctor_id, $newStart, $newEnd, $appointment->id);
        }

        $this->appointmentRepository->updateAppointment($appointment, $data);

        // Optional: send a system message in the chat
        $conversation = Conversation::where([
            'doctor_id' => $appointment->doctor_id,
            'patient_id' => $appointment->patient_id,
        ])->first();

        if ($conversation && isset($data['status'])) {
            $senderId = $user->id;

            if ($data['status'] === 'scheduled') {
                $dateStr = Carbon::parse($appointment->scheduled_at)->format('M d, Y h:i A');
                if ($appointment->scheduled_end_at) {
                    $dateStr .= ' - '.Carbon::parse($appointment->scheduled_end_at)->format('h:i A');
                }
                Message::create([
                    'uuid' => (string) Str::uuid(),
                    'conversation_id' => $conversation->id,
                    'sender_id' => $senderId,
                    'message' => "Appointment scheduled on <b>{$dateStr}</b> at <b>{$appointment->location}</b>.\n[APPOINTMENT_SCHEDULED:{$appointment->uuid}]",
                ]);
            } elseif ($data['status'] === 'declined') {
                $wasScheduled = $appointment->status === 'scheduled'
                    || $appointment->status === 'reschedule_proposed'
                    || $appointment->status === 'reschedule_requested'
                    || ! empty($appointment->scheduled_at);

                if ($wasScheduled) {
                    Message::create([
                        'uuid' => (string) Str::uuid(),
                        'conversation_id' => $conversation->id,
                        'sender_id' => $senderId,
                        'message' => "The appointment has been cancelled.\n[APPOINTMENT_CANCELLED:{$appointment->uuid}]",
                    ]);
                } else {
                    Message::create([
                        'uuid' => (string) Str::uuid(),
                        'conversation_id' => $conversation->id,
                        'sender_id' => $senderId,
                        'message' => "The appointment request has been declined.\n[APPOINTMENT_DECLINED:{$appointment->uuid}]",
                    ]);
                }
            } elseif ($data['status'] === 'reschedule_requested') {
                Message::create([
                    'uuid' => (string) Str::uuid(),
                    'conversation_id' => $conversation->id,
                    'sender_id' => $senderId,
                    'message' => "A request has been made to choose another date for the appointment.\n[APPOINTMENT_RESCHEDULE_REQUESTED:{$appointment->uuid}]",
                ]);
            }
        }

        return $appointment;
    }

    public function scheduleAppointmentForPatient(User $doctor, array $data)
    {
        if ($doctor->role->slug !== 'doctor') {
            abort(403, 'Only doctors can schedule appointments for patients.');
        }

        $this->checkAppointmentConflict($doctor->id, $data['scheduled_at'], $data['scheduled_end_at'] ?? null);

        $conversation = Conversation::firstOrCreate([
            'doctor_id' => $doctor->id,
            'patient_id' => $data['patient_id'],
        ], [
            'uuid' => (string) Str::uuid(),
        ]);

        $appointment = $this->appointmentRepository->createAppointment([
            'doctor_id' => $doctor->id,
            'patient_id' => $data['patient_id'],
            'scheduled_at' => $data['scheduled_at'],
            'scheduled_end_at' => $data['scheduled_end_at'] ?? null,
            'location' => $data['location'],
            'purpose' => $data['purpose'],
            'status' => 'scheduled',
        ]);

        $dateStr = Carbon::parse($appointment->scheduled_at)->format('M d, Y h:i A');
        if ($appointment->scheduled_end_at) {
            $dateStr .= ' - '.Carbon::parse($appointment->scheduled_end_at)->format('h:i A');
        }

        Message::create([
            'uuid' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'sender_id' => $doctor->id,
            'message' => "A new follow-up appointment has been scheduled on <b>{$dateStr}</b> at <b>{$appointment->location}</b>.\nPurpose: {$data['purpose']}\n[APPOINTMENT_SCHEDULED:{$appointment->uuid}]",
        ]);

        return [
            'message' => 'Appointment scheduled successfully.',
            'appointment' => $appointment,
            'conversation_uuid' => $conversation->uuid,
        ];
    }

    public function proposeReschedule(Appointment $appointment, array $data, $user)
    {
        $conversation = Conversation::where([
            'doctor_id' => $appointment->doctor_id,
            'patient_id' => $appointment->patient_id,
        ])->first();

        if (! $conversation) {
            abort(404, 'Conversation not found.');
        }

        $this->checkAppointmentConflict(
            $appointment->doctor_id,
            $data['scheduled_at'],
            $data['scheduled_end_at'] ?? null,
            $appointment->id
        );

        $updateData = [
            'status' => 'reschedule_proposed',
            'scheduled_at' => $data['scheduled_at'],
            'location' => $data['location'],
        ];

        if (array_key_exists('scheduled_end_at', $data)) {
            $updateData['scheduled_end_at'] = $data['scheduled_end_at'];
        }

        $this->appointmentRepository->updateAppointment($appointment, $updateData);

        $dateStr = Carbon::parse($data['scheduled_at'])->format('Y-m-d H:i:s');
        $displayDateStr = Carbon::parse($data['scheduled_at'])->format('M d, Y h:i A');
        if (! empty($data['scheduled_end_at'])) {
            $displayDateStr .= ' - '.Carbon::parse($data['scheduled_end_at'])->format('h:i A');
        }
        $loc = $data['location'];

        $message = Message::create([
            'uuid' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'message' => "A new schedule has been proposed on <b>{$displayDateStr}</b> at <b>{$loc}</b>.\n[APPOINTMENT_RESCHEDULE_PROPOSED:{$appointment->uuid}:{$dateStr}:{$loc}]",
        ]);

        return [
            'message' => 'Reschedule proposed successfully.',
            'appointment' => $appointment->refresh(),
        ];
    }

    public function acceptReschedule(Appointment $appointment, array $data, $user)
    {
        $conversation = Conversation::where([
            'doctor_id' => $appointment->doctor_id,
            'patient_id' => $appointment->patient_id,
        ])->first();

        if (! $conversation) {
            abort(404, 'Conversation not found.');
        }

        $this->checkAppointmentConflict(
            $appointment->doctor_id,
            $appointment->scheduled_at,
            $appointment->scheduled_end_at,
            $appointment->id
        );

        $this->appointmentRepository->updateAppointment($appointment, [
            'status' => 'scheduled',
        ]);

        $dateStr = Carbon::parse($appointment->scheduled_at)->format('M d, Y h:i A');
        if ($appointment->scheduled_end_at) {
            $dateStr .= ' - '.Carbon::parse($appointment->scheduled_end_at)->format('h:i A');
        }

        Message::create([
            'uuid' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'message' => "The proposed schedule has been accepted. The appointment is now confirmed on <b>{$dateStr}</b> at <b>{$appointment->location}</b>.\n[APPOINTMENT_RESCHEDULE_ACCEPTED:{$appointment->uuid}]",
        ]);

        return [
            'message' => 'Reschedule accepted successfully.',
            'appointment' => $appointment->refresh(),
        ];
    }

    public function deleteAppointment(Appointment $appointment)
    {
        return $this->appointmentRepository->deleteAppointment($appointment);
    }
}
