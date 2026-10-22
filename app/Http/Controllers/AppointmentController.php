<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AppointmentRescheduleProposal;
use App\Models\User;
use App\Service\AppointmentRescheduleService;
use App\Service\AppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AppointmentController extends Controller
{
    public function __construct(
        public AppointmentService $appointmentService,
        public AppointmentRescheduleService $rescheduleService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $appointments = $this->appointmentService->getAppointmentsForUser(
            $request->user(),
            $request->query('doctor_id'),
            $request->query('doctor_uuid')
        );

        return response()->json($appointments);
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->has('doctor_id') && ! is_numeric($request->input('doctor_id'))) {
            $doc = User::where('uuid', $request->input('doctor_id'))->first();
            if ($doc) {
                $request->merge(['doctor_id' => $doc->id]);
            }
        }

        $validated = $request->validate([
            'doctor_id' => 'required|exists:users,id',
            'diagnosis_uuid' => 'nullable|string|exists:diagnoses,uuid',
            'message' => 'nullable|string',
            'purpose' => 'nullable|string|max:500',
            'location' => 'nullable|string|max:255',
            'source' => 'nullable|string|in:direct,scan,walk_in',
            'idempotency_key' => 'nullable|string|max:255',
            'scheduled_at' => 'nullable|date|after_or_equal:today',
            'scheduled_end_at' => 'nullable|date|after:scheduled_at',
        ]);

        try {
            $result = $this->appointmentService->createAppointment(
                $request->user(),
                $validated
            );

            return response()->json($result, 200);
        } catch (HttpException $e) {
            // Translate slot conflicts into standard 409 Conflict
            if ($e->getStatusCode() === 422 && str_contains($e->getMessage(), 'already has an appointment')) {
                abort(409, 'SLOT_UNAVAILABLE: '.$e->getMessage());
            }
            throw $e;
        }
    }

    public function show(Appointment $appointment): Appointment
    {
        return $appointment->load([
            'doctor',
            'patient',
            'diagnosis',
            'inClinicDiagnosis',
            'scanExceptionDoctor',
            'clinicalNote.diagnosis',
            'acceptedBy',
            'activeRescheduleProposal',
            'events',
        ]);
    }

    public function update(Request $request, Appointment $appointment): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'sometimes|string|in:pending,confirmed,accepted,declined,rejected,scheduled,checked_in,in_consultation,completed,cancelled,no_show,reschedule_proposed,reschedule_requested',
            'scheduled_at' => 'nullable|date|after_or_equal:today',
            'scheduled_end_at' => 'nullable|date|after:scheduled_at',
            'location' => 'nullable|string',
            'requested_reschedule_date' => 'nullable|date|after_or_equal:today',
            'requested_reschedule_time' => 'nullable|string',
        ]);

        $updatedAppointment = $this->appointmentService->updateAppointmentStatus(
            $appointment,
            $validated,
            $request->user()
        );

        return response()->json($updatedAppointment);
    }

    public function accept(Request $request, Appointment $appointment): JsonResponse
    {
        $result = $this->appointmentService->acceptAppointment($appointment, $request->user());

        return response()->json($result);
    }

    public function reject(Request $request, Appointment $appointment): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $result = $this->appointmentService->rejectAppointment(
            $appointment,
            $request->user(),
            $validated['reason'] ?? null
        );

        return response()->json($result);
    }

    public function decline(Request $request, Appointment $appointment): JsonResponse
    {
        return $this->reject($request, $appointment);
    }

    public function cancel(Request $request, Appointment $appointment): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $result = $this->appointmentService->cancelAppointment(
            $appointment,
            $request->user(),
            $validated['reason'] ?? null
        );

        return response()->json($result);
    }

    public function checkIn(Request $request, Appointment $appointment): JsonResponse
    {
        $result = $this->appointmentService->checkInAppointment($appointment, $request->user());

        return response()->json($result);
    }

    public function markNoShow(Request $request, Appointment $appointment): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $result = $this->appointmentService->markNoShow(
            $appointment,
            $request->user(),
            $validated['reason'] ?? 'patient_not_present'
        );

        return response()->json($result);
    }

    public function events(Appointment $appointment): JsonResponse
    {
        $events = $appointment->events()->with('actor')->get();

        return response()->json($events);
    }

    public function scheduleForPatient(Request $request): JsonResponse
    {
        if ($request->filled('patient_uuid')) {
            $patient = User::where('uuid', $request->input('patient_uuid'))->first();
            if ($patient) {
                $request->merge(['patient_id' => $patient->id]);
            }
        } elseif ($request->filled('patient_id') && ! is_numeric($request->input('patient_id'))) {
            $patient = User::where('uuid', $request->input('patient_id'))->first();
            if ($patient) {
                $request->merge(['patient_id' => $patient->id]);
            }
        }

        $validated = $request->validate([
            'patient_id' => 'required|integer|exists:users,id',
            'scheduled_at' => 'required|date|after_or_equal:today',
            'scheduled_end_at' => 'nullable|date|after:scheduled_at',
            'location' => 'required|string|max:255',
            'purpose' => 'required|string|max:500',
        ]);

        $user = $request->user();
        $doctor = ($user->role?->slug === 'secretary' && $user->doctor) ? $user->doctor : $user;

        $result = $this->appointmentService->scheduleAppointmentForPatient(
            $doctor,
            $validated
        );

        return response()->json($result);
    }

    public function proposeReschedule(Request $request, Appointment $appointment): JsonResponse
    {
        $validated = $request->validate([
            'scheduled_at' => 'required|date|after_or_equal:today',
            'scheduled_end_at' => 'nullable|date|after:scheduled_at',
            'location' => 'required|string',
            'reason' => 'nullable|string',
        ]);

        $result = $this->rescheduleService->proposeReschedule(
            $appointment,
            $request->user(),
            $validated
        );

        return response()->json($result);
    }

    public function acceptReschedule(Request $request, Appointment $appointment): JsonResponse
    {
        // If there's an active proposal, accept it via rescheduleService
        $proposal = $appointment->activeRescheduleProposal;
        if ($proposal) {
            $result = $this->rescheduleService->acceptRescheduleProposal($appointment, $proposal, $request->user());
            if (! empty($result['conflict'])) {
                return response()->json(['message' => $result['message']], 409);
            }

            return response()->json($result);
        }

        // Otherwise handle legacy patient requested reschedule date/time
        $result = $this->appointmentService->acceptReschedule(
            $appointment,
            [],
            $request->user()
        );

        return response()->json($result);
    }

    public function createRescheduleProposal(Request $request, Appointment $appointment): JsonResponse
    {
        $validated = $request->validate([
            'scheduled_at' => 'required|date|after_or_equal:today',
            'scheduled_end_at' => 'nullable|date|after:scheduled_at',
            'location' => 'nullable|string',
            'reason' => 'nullable|string',
            'expires_at' => 'nullable|date|after:now',
        ]);

        $result = $this->rescheduleService->proposeReschedule($appointment, $request->user(), $validated);

        return response()->json($result, 201);
    }

    public function acceptRescheduleProposal(Request $request, Appointment $appointment, AppointmentRescheduleProposal $proposal): JsonResponse
    {
        $result = $this->rescheduleService->acceptRescheduleProposal($appointment, $proposal, $request->user());
        if (! empty($result['conflict'])) {
            return response()->json(['message' => $result['message']], 409);
        }

        return response()->json($result);
    }

    public function declineRescheduleProposal(Request $request, Appointment $appointment, AppointmentRescheduleProposal $proposal): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string',
        ]);

        $result = $this->rescheduleService->declineRescheduleProposal(
            $appointment,
            $proposal,
            $request->user(),
            $validated['reason'] ?? null
        );

        return response()->json($result);
    }

    public function withdrawRescheduleProposal(Request $request, Appointment $appointment, AppointmentRescheduleProposal $proposal): JsonResponse
    {
        $result = $this->rescheduleService->withdrawRescheduleProposal($appointment, $proposal, $request->user());

        return response()->json($result);
    }

    public function attachInClinicScan(Request $request, Appointment $appointment): JsonResponse
    {
        $validated = $request->validate([
            'diagnosis_uuid' => 'required|string|exists:diagnoses,uuid',
        ]);

        $updated = $this->appointmentService->attachInClinicScan(
            $appointment,
            $validated['diagnosis_uuid'],
            $request->user()
        );

        return response()->json($updated);
    }

    public function recordScanException(Request $request, Appointment $appointment): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:10|max:1000',
            'category' => 'nullable|string|in:patient_physical_intolerance,lesion_inaccessible,camera_hardware_failure_unresolvable,patient_declined_examination,other',
        ]);

        $updated = $this->appointmentService->recordScanException(
            $appointment,
            $validated,
            $request->user()
        );

        return response()->json($updated);
    }

    public function destroy(Appointment $appointment): Response
    {
        $this->appointmentService->deleteAppointment($appointment);

        return response()->noContent();
    }
}
