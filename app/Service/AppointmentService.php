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

    public function getAppointmentsForUser($user, $doctorId = null, $doctorUuid = null)
    {
        $appointments = $this->appointmentRepository->getAppointmentsForUser($user, $doctorId, $doctorUuid);

        $isStaff = $user->role?->slug === 'admin'
            || ($user->role?->slug === 'doctor' && (! $doctorId || $doctorId == $user->id))
            || ($user->role?->slug === 'secretary' && (! $doctorId || $doctorId == $user->doctor_id));

        $appointments->each(function ($appointment) use ($user, $isStaff, $doctorId, $doctorUuid) {
            $conversation = Conversation::where('doctor_id', $appointment->doctor_id)
                ->where('patient_id', $appointment->patient_id)
                ->first();
            if ($conversation) {
                $appointment->conversation_uuid = $conversation->uuid;
            }

            // If queried specifically for a doctor by a non-staff user (e.g. another patient), sanitize private patient details
            if (! $isStaff && ($doctorId || $doctorUuid) && $appointment->patient_id !== $user->id) {
                $appointment->unsetRelation('diagnosis');
                $appointment->unsetRelation('clinicalNote');
                $appointment->setRelation('patient', new User([
                    'id' => 0,
                    'first_name' => 'Booked',
                    'last_name' => 'Patient',
                ]));
            }
        });

        return $appointments;
    }

    public function createAppointment($user, array $data)
    {
        $doctor = User::find($data['doctor_id']);
        if (! $doctor || $doctor->role?->slug !== 'doctor') {
            abort(404, 'Doctor not found.');
        }

        if (! empty($data['scheduled_at'])) {
            $this->checkAppointmentConflict($data['doctor_id'], $data['scheduled_at'], $data['scheduled_end_at'] ?? null);
        }

        // Verify if doctor can receive new appointments from this patient
        $isExistingPatient = ($user->is_doctor_registered && $user->registered_by_doctor_id == $doctor->id)
            || Appointment::where('patient_id', $user->id)->where('doctor_id', $doctor->id)->exists()
            || Conversation::where('patient_id', $user->id)->where('doctor_id', $doctor->id)->exists();

        if (! $isExistingPatient && ! $doctor->canBeRecommended()) {
            abort(403, 'This doctor is currently not accepting new patient scan referrals. Existing registered patients only.');
        }

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

        $startTimeStr = $startTime->format('H:i:s');
        $endTimeStr = $endTime->format('H:i:s');
        $formattedDate = $startTime->format('M d, Y');
        $formattedStart = $startTime->format('g:i A');
        $formattedEnd = $endTime->format('g:i A');

        // 1. Verify that appointment is strictly within doctor's active duty hours
        $isOnDuty = $this->availabilityService->isDoctorOnDuty($doctorId, $startTime, $startTimeStr, $endTimeStr);
        if (! $isOnDuty) {
            $dutySlots = $this->availabilityService->getDutySlotsForDate($doctorId, $startTime);

            if ($dutySlots->isEmpty()) {
                abort(422, "Conflict detected: The doctor has no scheduled duty hours on {$formattedDate}. Please select an available date.");
            }

            $dutyRanges = $dutySlots->map(function ($slot) {
                return Carbon::parse($slot->start_time)->format('g:i A').' – '.Carbon::parse($slot->end_time)->format('g:i A');
            })->join(', ');

            abort(422, "Conflict detected: The selected time ({$formattedStart} to {$formattedEnd}) is outside the doctor's duty hours on {$formattedDate}. Active duty hours: {$dutyRanges}.");
        }

        // 2. Verify that appointment does not overlap with any blocked hours (lunch, transit, breaks, etc.)
        $blockedSlot = $this->availabilityService->hasBlockedOverlap($doctorId, $startTime, $startTimeStr, $endTimeStr);
        if ($blockedSlot) {
            $blockedStart = Carbon::parse($blockedSlot->start_time)->format('g:i A');
            $blockedEnd = Carbon::parse($blockedSlot->end_time)->format('g:i A');
            $reason = $blockedSlot->location_name ? " ({$blockedSlot->location_name})" : '';
            abort(422, "Conflict detected: The selected time ({$formattedStart} to {$formattedEnd}) overlaps with doctor's blocked hours{$reason} from {$blockedStart} to {$blockedEnd}. Please select a different time slot.");
        }

        // 3. Verify that appointment does not overlap with existing scheduled appointments
        $existingAppointments = Appointment::where('doctor_id', $doctorId)
            ->whereIn('status', ['scheduled', 'reschedule_proposed', 'reschedule_requested'])
            ->whereNotNull('scheduled_at')
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->get();

        foreach ($existingAppointments as $appt) {
            $existingStart = Carbon::parse($appt->scheduled_at);
            $existingEnd = $appt->scheduled_end_at ? Carbon::parse($appt->scheduled_end_at) : (clone $existingStart)->addHour();

            if ($startTime->lt($existingEnd) && $endTime->gt($existingStart)) {
                $existingStartFmt = $existingStart->format('g:i A');
                $existingEndFmt = $existingEnd->format('g:i A');
                abort(422, "Conflict detected: The doctor already has an appointment scheduled on {$formattedDate} from {$existingStartFmt} to {$existingEndFmt}. Please select a different time slot.");
            }
        }
    }

    public function updateAppointmentStatus(Appointment $appointment, array $data, $user)
    {
        $isDoctor = $user->id === $appointment->doctor_id;
        $isPatient = $user->id === $appointment->patient_id;
        $isSecretary = $user->role->slug === 'secretary' && $user->doctor_id === $appointment->doctor_id;
        $isAdmin = $user->role->slug === 'admin' || $user->role->name === 'admin';

        if (! $isAdmin && ! $isDoctor && ! $isPatient && ! $isSecretary) {
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

    public function scheduleAppointmentForPatient(User $actor, array $data)
    {
        $doctorId = null;
        if ($actor->role->slug === 'doctor') {
            $doctorId = $actor->id;
        } elseif ($actor->role->slug === 'secretary' && $actor->doctor_id) {
            $doctorId = $actor->doctor_id;
        } else {
            abort(403, 'Only doctors or their secretaries can schedule appointments for patients.');
        }

        $this->checkAppointmentConflict($doctorId, $data['scheduled_at'], $data['scheduled_end_at'] ?? null);

        $conversation = Conversation::firstOrCreate([
            'doctor_id' => $doctorId,
            'patient_id' => $data['patient_id'],
        ], [
            'uuid' => (string) Str::uuid(),
        ]);

        $appointment = $this->appointmentRepository->createAppointment([
            'doctor_id' => $doctorId,
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
            'sender_id' => $actor->id,
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
