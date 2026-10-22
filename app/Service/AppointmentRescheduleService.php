<?php

namespace App\Service;

use App\Events\AppointmentUpdated;
use App\Models\Appointment;
use App\Models\AppointmentRescheduleProposal;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AppointmentRescheduleService
{
    public function __construct(
        protected AppointmentService $appointmentService
    ) {}

    public function proposeReschedule(Appointment $appointment, User $actor, array $data): array
    {
        return DB::transaction(function () use ($appointment, $actor, $data) {
            // Stable doctor scheduling lock
            User::where('id', $appointment->doctor_id)->lockForUpdate()->first();
            $appointment = Appointment::where('id', $appointment->id)->lockForUpdate()->firstOrFail();

            if (! in_array($appointment->status, ['confirmed', 'scheduled', 'reschedule_proposed', 'reschedule_requested', 'pending'])) {
                abort(409, 'Only active appointments can be rescheduled.');
            }

            $isDoctor = $actor->id === $appointment->doctor_id;
            $isPatient = $actor->id === $appointment->patient_id;
            $isSecretary = $actor->role?->slug === 'secretary' && $actor->doctor_id === $appointment->doctor_id;

            if (! $isDoctor && ! $isPatient && ! $isSecretary) {
                abort(403, 'Unauthorized to propose a reschedule for this appointment.');
            }

            // Enforce only one active pending proposal per appointment
            $hasActiveProposal = AppointmentRescheduleProposal::where('appointment_id', $appointment->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->exists();

            if ($hasActiveProposal) {
                abort(409, 'An active reschedule proposal already exists for this appointment.');
            }

            $proposedStart = Carbon::parse($data['scheduled_at']);
            $proposedEnd = ! empty($data['scheduled_end_at'])
                ? Carbon::parse($data['scheduled_end_at'])
                : (clone $proposedStart)->addHour();

            if ($proposedStart->isPast()) {
                abort(422, 'Cannot propose a reschedule to a past date or time.');
            }

            // Preliminary check: ensure proposed slot is currently free & in duty hours
            $this->appointmentService->checkAppointmentConflict(
                $appointment->doctor_id,
                $proposedStart,
                $proposedEnd,
                $appointment->id
            );

            $proposerType = $isPatient ? 'patient' : ($isSecretary ? 'secretary' : 'doctor');

            $proposal = AppointmentRescheduleProposal::create([
                'appointment_id' => $appointment->id,
                'proposer_id' => $actor->id,
                'proposer_type' => $proposerType,
                'proposed_start_at' => $proposedStart,
                'proposed_end_at' => $proposedEnd,
                'reason' => $data['reason'] ?? $data['purpose'] ?? null,
                'status' => 'pending',
                'expires_at' => isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
            ]);

            // Note: Appointment's original scheduled_at remains intact.
            // We update status to 'reschedule_proposed' for UI representation and requested_reschedule fields for backward compatibility
            $appointment->update([
                'status' => 'reschedule_proposed',
                'requested_reschedule_date' => null,
                'requested_reschedule_time' => null,
                'location' => $data['location'] ?? $appointment->location,
            ]);

            $this->appointmentService->recordAppointmentEvent($appointment, $actor, 'reschedule_proposed', [
                'proposal_uuid' => $proposal->uuid,
                'proposed_start_at' => $proposedStart->toIso8601String(),
                'proposed_end_at' => $proposedEnd->toIso8601String(),
                'proposer_type' => $proposerType,
                'reason' => $proposal->reason,
            ]);

            // Notify via Conversation message
            $conversation = Conversation::where([
                'doctor_id' => $appointment->doctor_id,
                'patient_id' => $appointment->patient_id,
            ])->first();

            if ($conversation) {
                $displayDateStr = $proposedStart->format('M d, Y g:i A').' - '.$proposedEnd->format('g:i A');
                $loc = $appointment->location ?? 'Clinic';
                Message::create([
                    'uuid' => (string) Str::uuid(),
                    'conversation_id' => $conversation->id,
                    'sender_id' => $actor->id,
                    'message' => "A new schedule has been proposed on <b>{$displayDateStr}</b> at <b>{$loc}</b>.\n[APPOINTMENT_RESCHEDULE_PROPOSED:{$appointment->uuid}:{$proposedStart->format('Y-m-d H:i:s')}:{$loc}]",
                ]);
            }

            $appointment->loadMissing(['doctor', 'patient']);
            broadcast(new AppointmentUpdated(
                $appointment->patient?->uuid,
                $appointment->doctor?->uuid,
                'reschedule_proposed',
                $appointment->uuid
            ));

            return [
                'message' => 'Reschedule proposed successfully.',
                'proposal' => $proposal,
                'appointment' => $appointment->refresh()->load(['activeRescheduleProposal']),
            ];
        });
    }

    public function acceptRescheduleProposal(Appointment $appointment, AppointmentRescheduleProposal $proposal, User $actor): array
    {
        return DB::transaction(function () use ($appointment, $proposal, $actor) {
            // Strict lock order: Doctor -> Appointment -> Proposal
            User::where('id', $appointment->doctor_id)->lockForUpdate()->first();
            $appointment = Appointment::where('id', $appointment->id)->lockForUpdate()->firstOrFail();
            $proposal = AppointmentRescheduleProposal::where('id', $proposal->id)->lockForUpdate()->firstOrFail();

            if ($proposal->appointment_id !== $appointment->id) {
                abort(404, 'Proposal does not belong to this appointment.');
            }

            if ($proposal->status !== 'pending') {
                abort(409, 'This reschedule proposal is no longer pending (status: '.$proposal->status.').');
            }

            $isPatient = $actor->id === $appointment->patient_id;
            $isDoctor = $actor->id === $appointment->doctor_id;
            $isSecretary = $actor->role?->slug === 'secretary' && $actor->doctor_id === $appointment->doctor_id;

            // Two-way rule: The other party must explicitly accept.
            // Proposer cannot accept their own proposal
            if ($proposal->proposer_type === 'patient') {
                if ($isPatient) {
                    abort(403, 'A patient cannot accept their own reschedule proposal.');
                }
                if (! $isDoctor && ! $isSecretary) {
                    abort(403, 'Only the assigned doctor or authorized secretary can accept this proposal.');
                }
            } else {
                // Proposed by doctor or secretary
                if ($isDoctor || $isSecretary) {
                    abort(403, 'Practice staff cannot accept their own reschedule proposal.');
                }
                if (! $isPatient) {
                    abort(403, 'Only the patient can accept this reschedule proposal.');
                }
            }

            // Authoritative availability check within transaction
            $hasConflict = false;
            $conflictMessage = '';
            try {
                $this->appointmentService->checkAppointmentConflict(
                    $appointment->doctor_id,
                    $proposal->proposed_start_at,
                    $proposal->proposed_end_at,
                    $appointment->id
                );
            } catch (\Exception $e) {
                $hasConflict = true;
                $conflictMessage = $e->getMessage();
            }

            if ($hasConflict) {
                $proposal->update([
                    'status' => 'conflict',
                    'responder_id' => $actor->id,
                    'responded_at' => now(),
                ]);

                $this->appointmentService->recordAppointmentEvent($appointment, $actor, 'reschedule_conflict', [
                    'proposal_uuid' => $proposal->uuid,
                    'error' => $conflictMessage,
                ]);

                return [
                    'conflict' => true,
                    'message' => 'The proposed time is no longer available. Original appointment remains unchanged.',
                ];
            }

            // Update appointment timestamps and confirm
            $origStart = $appointment->scheduled_at;
            $origEnd = $appointment->scheduled_end_at;

            $appointment->update([
                'previous_scheduled_at' => $origStart,
                'previous_scheduled_end_at' => $origEnd,
                'scheduled_at' => $proposal->proposed_start_at,
                'scheduled_end_at' => $proposal->proposed_end_at,
                'status' => 'confirmed',
                'requested_reschedule_date' => null,
                'requested_reschedule_time' => null,
            ]);

            $proposal->update([
                'status' => 'accepted',
                'responder_id' => $actor->id,
                'responded_at' => now(),
            ]);

            $this->appointmentService->recordAppointmentEvent($appointment, $actor, 'reschedule_accepted', [
                'proposal_uuid' => $proposal->uuid,
                'previous_scheduled_at' => $origStart?->toIso8601String(),
                'new_scheduled_at' => $proposal->proposed_start_at->toIso8601String(),
                'new_scheduled_end_at' => $proposal->proposed_end_at?->toIso8601String(),
            ]);

            $conversation = Conversation::where([
                'doctor_id' => $appointment->doctor_id,
                'patient_id' => $appointment->patient_id,
            ])->first();

            if ($conversation) {
                $dateStr = $proposal->proposed_start_at->format('M d, Y g:i A');
                if ($proposal->proposed_end_at) {
                    $dateStr .= ' - '.$proposal->proposed_end_at->format('g:i A');
                }
                $loc = $appointment->location ?? 'Clinic';
                Message::create([
                    'uuid' => (string) Str::uuid(),
                    'conversation_id' => $conversation->id,
                    'sender_id' => $actor->id,
                    'message' => "The reschedule proposal has been accepted. The appointment is now confirmed on <b>{$dateStr}</b> at <b>{$loc}</b>.\n[APPOINTMENT_RESCHEDULE_ACCEPTED:{$appointment->uuid}]",
                ]);
            }

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
                'proposal' => $proposal->refresh(),
            ];
        });
    }

    public function declineRescheduleProposal(Appointment $appointment, AppointmentRescheduleProposal $proposal, User $actor, ?string $reason = null): array
    {
        return DB::transaction(function () use ($appointment, $proposal, $actor, $reason) {
            User::where('id', $appointment->doctor_id)->lockForUpdate()->first();
            $appointment = Appointment::where('id', $appointment->id)->lockForUpdate()->firstOrFail();
            $proposal = AppointmentRescheduleProposal::where('id', $proposal->id)->lockForUpdate()->firstOrFail();

            if ($proposal->status !== 'pending') {
                abort(409, 'This reschedule proposal is no longer pending.');
            }

            $isPatient = $actor->id === $appointment->patient_id;
            $isDoctor = $actor->id === $appointment->doctor_id;
            $isSecretary = $actor->role?->slug === 'secretary' && $actor->doctor_id === $appointment->doctor_id;

            if ($proposal->proposer_type === 'patient' && ! $isDoctor && ! $isSecretary) {
                abort(403, 'Only practice staff can decline this proposal.');
            }
            if ($proposal->proposer_type !== 'patient' && ! $isPatient) {
                abort(403, 'Only the patient can decline this proposal.');
            }

            $proposal->update([
                'status' => 'declined',
                'responder_id' => $actor->id,
                'responded_at' => now(),
            ]);

            // Revert appointment status to confirmed if it was previously confirmed
            $appointment->update([
                'status' => $appointment->scheduled_at ? 'confirmed' : 'pending',
                'requested_reschedule_date' => null,
                'requested_reschedule_time' => null,
            ]);

            $this->appointmentService->recordAppointmentEvent($appointment, $actor, 'reschedule_declined', [
                'proposal_uuid' => $proposal->uuid,
                'reason' => $reason,
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
                    'message' => "The reschedule proposal was declined. The original appointment remains in effect.\n[APPOINTMENT_RESCHEDULE_DECLINED:{$appointment->uuid}]",
                ]);
            }

            $appointment->loadMissing(['doctor', 'patient']);
            broadcast(new AppointmentUpdated(
                $appointment->patient?->uuid,
                $appointment->doctor?->uuid,
                'reschedule_declined',
                $appointment->uuid
            ));

            return [
                'message' => 'Reschedule proposal declined.',
                'appointment' => $appointment->refresh(),
                'proposal' => $proposal->refresh(),
            ];
        });
    }

    public function withdrawRescheduleProposal(Appointment $appointment, AppointmentRescheduleProposal $proposal, User $actor): array
    {
        return DB::transaction(function () use ($appointment, $proposal, $actor) {
            User::where('id', $appointment->doctor_id)->lockForUpdate()->first();
            $appointment = Appointment::where('id', $appointment->id)->lockForUpdate()->firstOrFail();
            $proposal = AppointmentRescheduleProposal::where('id', $proposal->id)->lockForUpdate()->firstOrFail();

            if ($proposal->status !== 'pending') {
                abort(409, 'This reschedule proposal is no longer pending.');
            }

            // Only proposer or practice staff if proposer was practice staff can withdraw
            $canWithdraw = $actor->id === $proposal->proposer_id
                || ($proposal->proposer_type === 'doctor' && $actor->role?->slug === 'secretary' && $actor->doctor_id === $appointment->doctor_id)
                || ($proposal->proposer_type === 'secretary' && $actor->id === $appointment->doctor_id);

            if (! $canWithdraw) {
                abort(403, 'Only the party that proposed the reschedule can withdraw it.');
            }

            $proposal->update([
                'status' => 'withdrawn',
                'responder_id' => $actor->id,
                'responded_at' => now(),
            ]);

            $appointment->update([
                'status' => $appointment->scheduled_at ? 'confirmed' : 'pending',
                'requested_reschedule_date' => null,
                'requested_reschedule_time' => null,
            ]);

            $this->appointmentService->recordAppointmentEvent($appointment, $actor, 'reschedule_withdrawn', [
                'proposal_uuid' => $proposal->uuid,
            ]);

            $appointment->loadMissing(['doctor', 'patient']);
            broadcast(new AppointmentUpdated(
                $appointment->patient?->uuid,
                $appointment->doctor?->uuid,
                'reschedule_withdrawn',
                $appointment->uuid
            ));

            return [
                'message' => 'Reschedule proposal withdrawn.',
                'appointment' => $appointment->refresh(),
                'proposal' => $proposal->refresh(),
            ];
        });
    }
}
