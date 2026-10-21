<?php

namespace App\Service;

use App\Events\AppointmentUpdated;
use App\Http\Resources\UserResource;
use App\Models\Appointment;
use App\Models\AppointmentEvent;
use App\Models\AppointmentRescheduleProposal;
use App\Models\Conversation;
use App\Models\Diagnosis;
use App\Models\DoctorAvailability;
use App\Models\Message;
use App\Models\User;
use App\Repository\AppointmentRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
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
        return DB::transaction(function () use ($user, $data) {
            $doctor = User::find($data['doctor_id']);
            if (! $doctor || $doctor->role?->slug !== 'doctor') {
                abort(404, 'Doctor not found.');
            }

            if (! empty($data['scheduled_at'])) {
                $this->checkAppointmentConflict($data['doctor_id'], $data['scheduled_at'], $data['scheduled_end_at'] ?? null);
            }

            // Strict guard: Doctor-registered patients can only book appointments with their registering doctor
            if ($user->is_doctor_registered && $user->registered_by_doctor_id && (int) $data['doctor_id'] !== (int) $user->registered_by_doctor_id) {
                abort(403, 'You are registered under an attending doctor and cannot book appointments with other doctors.');
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

            // Idempotency check: if duplicate request submitted with same idempotency_key
            if (! empty($data['idempotency_key'])) {
                $existing = Appointment::where('patient_id', $user->id)
                    ->where('doctor_id', $data['doctor_id'])
                    ->where('status', 'pending')
                    ->where('scheduled_at', $data['scheduled_at'] ?? null)
                    ->where('created_at', '>=', now()->subMinutes(5))
                    ->first();
                if ($existing) {
                    return [
                        'message' => 'Appointment request sent successfully.',
                        'appointment' => $existing,
                        'conversation_uuid' => $conversation->uuid,
                        'doctor_availability' => ['is_available' => true],
                    ];
                }
            }

            if ($activeAppointment && empty($data['scheduled_at'])) {
                // If an active appointment exists and no new scheduled_at requested, just send the diagnosis as a follow-up
                $tag = 'DIAGNOSIS_ONLY';
                $appointmentUuid = $activeAppointment->uuid;
                $responseMessage = 'Additional clinical findings shared.';
            } else {
                // Otherwise create a new appointment request
                $activeAppointment = $this->appointmentRepository->createAppointment([
                    'doctor_id' => $data['doctor_id'],
                    'patient_id' => $user->id,
                    'diagnosis_id' => $diagnosisId,
                    'scheduled_at' => $data['scheduled_at'] ?? null,
                    'scheduled_end_at' => $data['scheduled_end_at'] ?? null,
                    'location' => $data['location'] ?? null,
                    'purpose' => $data['purpose'] ?? $data['message'] ?? 'Consultation',
                    'appointment_source' => $data['source'] ?? ($diagnosisId ? 'scan' : 'direct'),
                    'status' => 'pending',
                ]);
                $tag = 'APPOINTMENT_REQUEST';
                $appointmentUuid = $activeAppointment->uuid;
                $responseMessage = 'Appointment request sent successfully.';

                $this->recordAppointmentEvent($activeAppointment, $user, 'booking_requested', [
                    'scheduled_at' => $activeAppointment->scheduled_at?->toIso8601String(),
                    'scheduled_end_at' => $activeAppointment->scheduled_end_at?->toIso8601String(),
                    'purpose' => $activeAppointment->purpose,
                ]);
            }

            // Create a Message representing this referral
            $messageContent = $data['message'] ?? '';
            if ($diagnosisId) {
                $messageContent .= "\n[{$tag}:{$appointmentUuid}:{$data['diagnosis_uuid']}]";
            } else {
                $messageContent = trim(($messageContent ?: 'I would like to request an appointment for a clinical consultation.')."\n[{$tag}:{$appointmentUuid}]");
            }

            Message::create([
                'uuid' => (string) Str::uuid(),
                'conversation_id' => $conversation->id,
                'sender_id' => $user->id,
                'message' => $messageContent,
            ]);

            $activeAppointment->loadMissing(['doctor', 'patient']);
            broadcast(new AppointmentUpdated(
                $activeAppointment->patient?->uuid,
                $activeAppointment->doctor?->uuid,
                'created',
                $activeAppointment->uuid
            ));

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
        });
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
        $conflictQuery = Appointment::where('doctor_id', $doctorId)
            ->whereIn('status', ['confirmed', 'scheduled', 'checked_in', 'in_consultation', 'reschedule_proposed'])
            ->whereNotNull('scheduled_at')
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId));

        if (DB::transactionLevel() > 0) {
            $conflictQuery->lockForUpdate();
        }

        $existingAppointments = $conflictQuery->get();

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

        if ($newStatus === 'reschedule_requested') {
            if (! empty($data['requested_reschedule_date'])) {
                $reqDate = Carbon::parse($data['requested_reschedule_date'])->startOfDay();
                if ($reqDate->lt(Carbon::today())) {
                    abort(422, 'Cannot request a reschedule to a past date.');
                }

                $currentApptDate = $appointment->scheduled_at ? Carbon::parse($appointment->scheduled_at)->toDateString() : null;
                $hasAnyDuty = DoctorAvailability::where('doctor_id', $appointment->doctor_id)
                    ->where('is_available', 1)
                    ->exists();

                if ($currentApptDate !== $reqDate->toDateString() && $hasAnyDuty) {
                    $hasDutyOnDate = DoctorAvailability::where('doctor_id', $appointment->doctor_id)
                        ->where('is_available', 1)
                        ->whereDate('available_date', $reqDate->toDateString())
                        ->exists();

                    if (! $hasDutyOnDate) {
                        abort(422, 'The doctor is not scheduled for clinic duty on the selected date.');
                    }
                }

                if (! empty($data['requested_reschedule_time'])) {
                    $reqStart = Carbon::parse($data['requested_reschedule_date'].' '.$data['requested_reschedule_time']);
                    $duration = ($appointment->scheduled_at && $appointment->scheduled_end_at)
                        ? Carbon::parse($appointment->scheduled_at)->diffInMinutes(Carbon::parse($appointment->scheduled_end_at))
                        : (($appointment->previous_scheduled_at && $appointment->previous_scheduled_end_at)
                            ? Carbon::parse($appointment->previous_scheduled_at)->diffInMinutes(Carbon::parse($appointment->previous_scheduled_end_at))
                            : 60);
                    $reqEnd = (clone $reqStart)->addMinutes($duration);
                    $this->checkAppointmentConflict($appointment->doctor_id, $reqStart, $reqEnd, $appointment->id);
                }
            }

            // Delete previous set time and preserve in previous_scheduled_at
            if ($appointment->scheduled_at) {
                $data['previous_scheduled_at'] = $appointment->scheduled_at;
                $data['previous_scheduled_end_at'] = $appointment->scheduled_end_at;
            }
            $data['scheduled_at'] = null;
            $data['scheduled_end_at'] = null;
        }

        if ($newStatus === 'declined') {
            $data['requested_reschedule_date'] = null;
            $data['requested_reschedule_time'] = null;
            if ($appointment->scheduled_at) {
                $data['previous_scheduled_at'] = $appointment->scheduled_at;
                $data['previous_scheduled_end_at'] = $appointment->scheduled_end_at;
            }
            $data['scheduled_at'] = null;
            $data['scheduled_end_at'] = null;
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
                $preferredDateStr = '';
                if (! empty($data['requested_reschedule_date'])) {
                    $formattedDate = Carbon::parse($data['requested_reschedule_date'])->format('M d, Y');
                    if (! empty($data['requested_reschedule_time'])) {
                        $timeStr = Carbon::parse($data['requested_reschedule_time'])->format('g:i A');
                        $preferredDateStr = " (Preferred: <b>{$formattedDate}</b> at <b>{$timeStr}</b>)";
                    } else {
                        $preferredDateStr = " (Preferred: <b>{$formattedDate}</b>)";
                    }
                }
                Message::create([
                    'uuid' => (string) Str::uuid(),
                    'conversation_id' => $conversation->id,
                    'sender_id' => $senderId,
                    'message' => "A request has been made to reschedule the appointment{$preferredDateStr}.\n[APPOINTMENT_RESCHEDULE_REQUESTED:{$appointment->uuid}]",
                ]);
            }
        }

        $appointment->loadMissing(['doctor', 'patient']);
        broadcast(new AppointmentUpdated(
            $appointment->patient?->uuid,
            $appointment->doctor?->uuid,
            $data['status'] ?? 'updated',
            $appointment->uuid
        ));

        return $appointment;
    }

    public function scheduleAppointmentForPatient(User $actor, array $data)
    {
        return DB::transaction(function () use ($actor, $data) {
            $doctorId = null;
            if ($actor->role->slug === 'doctor') {
                $doctorId = $actor->id;
            } elseif ($actor->role->slug === 'secretary' && $actor->doctor_id) {
                $doctorId = $actor->doctor_id;
            } else {
                abort(403, 'Only doctors or their secretaries can schedule appointments for patients.');
            }

            // Stable doctor scheduling lock
            User::where('id', $doctorId)->lockForUpdate()->first();

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
                'accepted_by_id' => $actor->id,
                'confirmed_at' => now(),
                'appointment_source' => $data['source'] ?? 'walk_in',
            ]);

            $this->recordAppointmentEvent($appointment, $actor, 'booking_confirmed', [
                'source' => $appointment->appointment_source,
                'scheduled_at' => $appointment->scheduled_at?->toIso8601String(),
                'purpose' => $appointment->purpose,
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

            $appointment->loadMissing(['doctor', 'patient']);
            broadcast(new AppointmentUpdated(
                $appointment->patient?->uuid,
                $appointment->doctor?->uuid,
                'scheduled',
                $appointment->uuid
            ));

            return [
                'message' => 'Appointment scheduled successfully.',
                'appointment' => $appointment,
                'conversation_uuid' => $conversation->uuid,
            ];
        });
    }

    public function proposeReschedule(Appointment $appointment, array $data, $user)
    {
        return DB::transaction(function () use ($appointment, $data, $user) {
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
                'previous_scheduled_at' => $appointment->scheduled_at ?? $appointment->previous_scheduled_at,
                'previous_scheduled_end_at' => $appointment->scheduled_end_at ?? $appointment->previous_scheduled_end_at,
                'scheduled_at' => $data['scheduled_at'],
                'location' => $data['location'],
                'requested_reschedule_date' => null,
                'requested_reschedule_time' => null,
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

            $appointment->loadMissing(['doctor', 'patient']);
            broadcast(new AppointmentUpdated(
                $appointment->patient?->uuid,
                $appointment->doctor?->uuid,
                'reschedule_proposed',
                $appointment->uuid
            ));

            return [
                'message' => 'Reschedule proposed successfully.',
                'appointment' => $appointment->refresh(),
            ];
        });
    }

    public function acceptReschedule(Appointment $appointment, array $data, $user)
    {
        return DB::transaction(function () use ($appointment, $user) {
            $conversation = Conversation::where([
                'doctor_id' => $appointment->doctor_id,
                'patient_id' => $appointment->patient_id,
            ])->first();

            if (! $conversation) {
                abort(404, 'Conversation not found.');
            }

            // If the patient requested a specific date/time, apply it to scheduled_at before confirming.
            $updates = ['status' => 'scheduled'];
            $wasRescheduleRequested = $appointment->status === 'reschedule_requested';

            if ($wasRescheduleRequested && $appointment->requested_reschedule_date) {
                $timeStr = $appointment->requested_reschedule_time ?? '09:00';
                $dateOnly = Carbon::parse($appointment->requested_reschedule_date)->format('Y-m-d');
                $newScheduledAt = Carbon::parse("{$dateOnly} {$timeStr}");

                $durationMinutes = 60;
                $origStart = $appointment->scheduled_at ?? $appointment->previous_scheduled_at;
                $origEnd = $appointment->scheduled_end_at ?? $appointment->previous_scheduled_end_at;
                if ($origStart && $origEnd) {
                    $durationMinutes = Carbon::parse($origStart)->diffInMinutes(Carbon::parse($origEnd));
                }
                $newScheduledEndAt = (clone $newScheduledAt)->addMinutes($durationMinutes);

                $this->checkAppointmentConflict(
                    $appointment->doctor_id,
                    $newScheduledAt,
                    $newScheduledEndAt,
                    $appointment->id
                );

                $updates['scheduled_at'] = $newScheduledAt;
                $updates['scheduled_end_at'] = $newScheduledEndAt;
                $updates['requested_reschedule_date'] = null;
                $updates['requested_reschedule_time'] = null;
            } else {
                $this->checkAppointmentConflict(
                    $appointment->doctor_id,
                    $appointment->scheduled_at,
                    $appointment->scheduled_end_at,
                    $appointment->id
                );
            }

            $this->appointmentRepository->updateAppointment($appointment, $updates);

            $appointment->refresh();
            $dateStr = Carbon::parse($appointment->scheduled_at)->format('M d, Y h:i A');
            if ($appointment->scheduled_end_at) {
                $dateStr .= ' - '.Carbon::parse($appointment->scheduled_end_at)->format('h:i A');
            }

            $messageText = $wasRescheduleRequested
                ? "The reschedule request has been accepted. The appointment is now confirmed on <b>{$dateStr}</b> at <b>{$appointment->location}</b>.\n[APPOINTMENT_RESCHEDULE_ACCEPTED:{$appointment->uuid}]"
                : "The proposed schedule has been accepted. The appointment is now confirmed on <b>{$dateStr}</b> at <b>{$appointment->location}</b>.\n[APPOINTMENT_RESCHEDULE_ACCEPTED:{$appointment->uuid}]";

            Message::create([
                'uuid' => (string) Str::uuid(),
                'conversation_id' => $conversation->id,
                'sender_id' => $user->id,
                'message' => $messageText,
            ]);

            $appointment->loadMissing(['doctor', 'patient']);
            broadcast(new AppointmentUpdated(
                $appointment->patient?->uuid,
                $appointment->doctor?->uuid,
                'reschedule_accepted',
                $appointment->uuid
            ));

            return [
                'message' => 'Reschedule accepted successfully.',
                'appointment' => $appointment->refresh(),
            ];
        });
    }

    public function acceptAppointment(Appointment $appointment, User $actor): array
    {
        return DB::transaction(function () use ($appointment, $actor) {
            // 1. Stable doctor scheduling lock
            User::where('id', $appointment->doctor_id)->lockForUpdate()->first();

            // 2. Lock and retrieve appointment
            $appointment = Appointment::where('id', $appointment->id)->lockForUpdate()->firstOrFail();

            // 3. Confirm appointment is still pending
            if ($appointment->status !== 'pending') {
                abort(409, 'Appointment is not in pending status (current status: '.$appointment->status.').');
            }

            // 4. Authorization check
            $isDoctor = $actor->id === $appointment->doctor_id;
            $isSecretary = $actor->role?->slug === 'secretary' && $actor->doctor_id === $appointment->doctor_id;
            if (! $isDoctor && ! $isSecretary) {
                abort(403, 'Only the assigned doctor or an affiliated secretary can accept this appointment request.');
            }

            // 5. Authoritative availability re-check within the transaction
            if ($appointment->scheduled_at) {
                try {
                    $this->checkAppointmentConflict(
                        $appointment->doctor_id,
                        $appointment->scheduled_at,
                        $appointment->scheduled_end_at,
                        $appointment->id
                    );
                } catch (\Exception $e) {
                    abort(409, 'SLOT_NO_LONGER_AVAILABLE: '.$e->getMessage());
                }
            }

            // 6. Set status to confirmed, save actor and timestamp
            $appointment->update([
                'status' => 'confirmed',
                'accepted_by_id' => $actor->id,
                'confirmed_at' => now(),
            ]);

            // 7. Record event
            $this->recordAppointmentEvent($appointment, $actor, 'booking_confirmed', [
                'accepted_by' => $actor->id,
                'confirmed_at' => now()->toIso8601String(),
                'scheduled_at' => $appointment->scheduled_at?->toIso8601String(),
            ]);

            // 8. Send chat message
            $conversation = Conversation::firstOrCreate([
                'doctor_id' => $appointment->doctor_id,
                'patient_id' => $appointment->patient_id,
            ], [
                'uuid' => (string) Str::uuid(),
            ]);

            $dateStr = $appointment->scheduled_at
                ? Carbon::parse($appointment->scheduled_at)->format('M d, Y g:i A')
                : 'Agreed time';
            $loc = $appointment->location ?? 'Cruz Skin Clinic';

            Message::create([
                'uuid' => (string) Str::uuid(),
                'conversation_id' => $conversation->id,
                'sender_id' => $actor->id,
                'message' => "Appointment request confirmed for <b>{$dateStr}</b> at <b>{$loc}</b>.\n[APPOINTMENT_CONFIRMED:{$appointment->uuid}]",
            ]);

            $appointment->loadMissing(['doctor', 'patient']);
            broadcast(new AppointmentUpdated(
                $appointment->patient?->uuid,
                $appointment->doctor?->uuid,
                'confirmed',
                $appointment->uuid
            ));

            return [
                'message' => 'Appointment confirmed successfully.',
                'appointment' => $appointment->refresh(),
            ];
        });
    }

    public function rejectAppointment(Appointment $appointment, User $actor, ?string $reason = null): array
    {
        return DB::transaction(function () use ($appointment, $actor, $reason) {
            User::where('id', $appointment->doctor_id)->lockForUpdate()->first();
            $appointment = Appointment::where('id', $appointment->id)->lockForUpdate()->firstOrFail();

            if ($appointment->status !== 'pending') {
                abort(409, 'Only pending appointments can be rejected (current status: '.$appointment->status.').');
            }

            $isDoctor = $actor->id === $appointment->doctor_id;
            $isSecretary = $actor->role?->slug === 'secretary' && $actor->doctor_id === $appointment->doctor_id;
            if (! $isDoctor && ! $isSecretary) {
                abort(403, 'Only the assigned doctor or an affiliated secretary can reject this appointment request.');
            }

            $appointment->update([
                'status' => 'rejected',
                'cancellation_reason' => $reason,
                'cancelled_by_id' => $actor->id,
                'cancelled_at' => now(),
            ]);

            $this->recordAppointmentEvent($appointment, $actor, 'booking_rejected', [
                'rejected_by' => $actor->id,
                'reason' => $reason,
            ]);

            $conversation = Conversation::where([
                'doctor_id' => $appointment->doctor_id,
                'patient_id' => $appointment->patient_id,
            ])->first();

            if ($conversation) {
                $reasonText = $reason ? " Reason: {$reason}" : '';
                Message::create([
                    'uuid' => (string) Str::uuid(),
                    'conversation_id' => $conversation->id,
                    'sender_id' => $actor->id,
                    'message' => "The appointment booking request was declined.{$reasonText}\n[APPOINTMENT_DECLINED:{$appointment->uuid}]",
                ]);
            }

            $appointment->loadMissing(['doctor', 'patient']);
            broadcast(new AppointmentUpdated(
                $appointment->patient?->uuid,
                $appointment->doctor?->uuid,
                'rejected',
                $appointment->uuid
            ));

            return [
                'message' => 'Appointment request rejected.',
                'appointment' => $appointment->refresh(),
            ];
        });
    }

    public function cancelAppointment(Appointment $appointment, User $actor, ?string $reason = null): array
    {
        return DB::transaction(function () use ($appointment, $actor, $reason) {
            User::where('id', $appointment->doctor_id)->lockForUpdate()->first();
            $appointment = Appointment::where('id', $appointment->id)->lockForUpdate()->firstOrFail();

            if (in_array($appointment->status, ['in_consultation', 'completed', 'cancelled', 'rejected', 'no_show'])) {
                abort(409, 'Cannot cancel appointment in its current status ('.$appointment->status.').');
            }

            $isPatient = $actor->id === $appointment->patient_id;
            $isDoctor = $actor->id === $appointment->doctor_id;
            $isSecretary = $actor->role?->slug === 'secretary' && $actor->doctor_id === $appointment->doctor_id;

            if (! $isPatient && ! $isDoctor && ! $isSecretary) {
                abort(403, 'Unauthorized to cancel this appointment.');
            }

            // Invalidate any active reschedule proposals
            AppointmentRescheduleProposal::where('appointment_id', $appointment->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'withdrawn',
                    'responder_id' => $actor->id,
                    'responded_at' => now(),
                ]);

            $appointment->update([
                'status' => 'cancelled',
                'cancelled_by_id' => $actor->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            $this->recordAppointmentEvent($appointment, $actor, 'appointment_cancelled', [
                'cancelled_by' => $actor->id,
                'reason' => $reason,
            ]);

            $conversation = Conversation::where([
                'doctor_id' => $appointment->doctor_id,
                'patient_id' => $appointment->patient_id,
            ])->first();

            if ($conversation) {
                $reasonText = $reason ? " Reason: {$reason}" : '';
                Message::create([
                    'uuid' => (string) Str::uuid(),
                    'conversation_id' => $conversation->id,
                    'sender_id' => $actor->id,
                    'message' => "The appointment has been cancelled.{$reasonText}\n[APPOINTMENT_CANCELLED:{$appointment->uuid}]",
                ]);
            }

            $appointment->loadMissing(['doctor', 'patient']);
            broadcast(new AppointmentUpdated(
                $appointment->patient?->uuid,
                $appointment->doctor?->uuid,
                'cancelled',
                $appointment->uuid
            ));

            return [
                'message' => 'Appointment cancelled successfully.',
                'appointment' => $appointment->refresh(),
            ];
        });
    }

    public function checkInAppointment(Appointment $appointment, User $actor): array
    {
        return DB::transaction(function () use ($appointment, $actor) {
            User::where('id', $appointment->doctor_id)->lockForUpdate()->first();
            $appointment = Appointment::where('id', $appointment->id)->lockForUpdate()->firstOrFail();

            if (! in_array($appointment->status, ['confirmed', 'scheduled'])) {
                abort(409, 'Only confirmed appointments can be checked in (current status: '.$appointment->status.').');
            }

            $isDoctor = $actor->id === $appointment->doctor_id;
            $isSecretary = $actor->role?->slug === 'secretary' && $actor->doctor_id === $appointment->doctor_id;
            if (! $isDoctor && ! $isSecretary) {
                abort(403, 'Only practice staff can record patient check-in.');
            }

            $appointment->update([
                'status' => 'checked_in',
                'checked_in_at' => now(),
            ]);

            $this->recordAppointmentEvent($appointment, $actor, 'checked_in', [
                'checked_in_by' => $actor->id,
                'checked_in_at' => now()->toIso8601String(),
            ]);

            $appointment->loadMissing(['doctor', 'patient']);
            broadcast(new AppointmentUpdated(
                $appointment->patient?->uuid,
                $appointment->doctor?->uuid,
                'checked_in',
                $appointment->uuid
            ));

            return [
                'message' => 'Patient checked in successfully.',
                'appointment' => $appointment->refresh(),
            ];
        });
    }

    public function markNoShow(Appointment $appointment, User $actor, ?string $reason = 'patient_not_present'): array
    {
        return DB::transaction(function () use ($appointment, $actor, $reason) {
            User::where('id', $appointment->doctor_id)->lockForUpdate()->first();
            $appointment = Appointment::where('id', $appointment->id)->lockForUpdate()->firstOrFail();

            $isDoctor = $actor->id === $appointment->doctor_id;
            $isSecretary = $actor->role?->slug === 'secretary' && $actor->doctor_id === $appointment->doctor_id;
            if (! $isDoctor && ! $isSecretary) {
                abort(403, 'Only authorized doctors or secretaries can mark an appointment as a no-show.');
            }

            if (! in_array($appointment->status, ['confirmed', 'scheduled'])) {
                abort(409, 'Cannot mark as no-show: appointment must be in confirmed status (current: '.$appointment->status.').');
            }

            if (! empty($appointment->checked_in_at) || $appointment->status === 'checked_in' || $appointment->status === 'in_consultation' || $appointment->status === 'completed') {
                abort(409, 'Cannot mark as no-show: patient has already checked in or consultation has taken place.');
            }

            if (! $appointment->scheduled_at) {
                abort(422, 'Cannot mark as no-show: appointment has no scheduled time.');
            }

            // Configurable grace period (default 15 mins)
            $graceMinutes = (int) config('scheduling.no_show_grace_minutes', 15);
            $threshold = Carbon::parse($appointment->scheduled_at)->addMinutes($graceMinutes);

            if (now()->lt($threshold)) {
                $remMinutes = ceil(now()->diffInSeconds($threshold) / 60);
                abort(422, "Cannot mark as no-show before the attendance grace period has elapsed ({$graceMinutes} minutes). Please wait {$remMinutes} more minute(s).");
            }

            $appointment->update([
                'status' => 'no_show',
                'no_show_by_id' => $actor->id,
                'no_show_at' => now(),
                'no_show_reason' => $reason ?: 'patient_not_present',
            ]);

            $this->recordAppointmentEvent($appointment, $actor, 'no_show_confirmed', [
                'no_show_by' => $actor->id,
                'no_show_at' => now()->toIso8601String(),
                'reason' => $appointment->no_show_reason,
            ]);

            $conversation = Conversation::where([
                'doctor_id' => $appointment->doctor_id,
                'patient_id' => $appointment->patient_id,
            ])->first();

            if ($conversation) {
                Message::create([
                    'uuid' => (string) Str::uuid(),
                    'conversation_id' => $conversation->id,
                    'sender_id' => $actor->id,
                    'message' => "The appointment was marked as No-Show.\n[APPOINTMENT_NO_SHOW:{$appointment->uuid}]",
                ]);
            }

            $appointment->loadMissing(['doctor', 'patient']);
            broadcast(new AppointmentUpdated(
                $appointment->patient?->uuid,
                $appointment->doctor?->uuid,
                'no_show',
                $appointment->uuid
            ));

            return [
                'message' => 'Appointment confirmed as no-show.',
                'appointment' => $appointment->refresh(),
            ];
        });
    }

    public function recordAppointmentEvent(Appointment $appointment, ?User $actor, string $eventType, array $metadata = []): AppointmentEvent
    {
        return AppointmentEvent::create([
            'appointment_id' => $appointment->id,
            'actor_id' => $actor?->id,
            'event_type' => $eventType,
            'metadata' => $metadata,
        ]);
    }

    public function deleteAppointment(Appointment $appointment)
    {
        $appointment->loadMissing(['doctor', 'patient']);
        $patientUuid = $appointment->patient?->uuid;
        $doctorUuid = $appointment->doctor?->uuid;
        $appointmentUuid = $appointment->uuid;

        $result = $this->appointmentRepository->deleteAppointment($appointment);

        broadcast(new AppointmentUpdated(
            $patientUuid,
            $doctorUuid,
            'deleted',
            $appointmentUuid
        ));

        return $result;
    }
}
