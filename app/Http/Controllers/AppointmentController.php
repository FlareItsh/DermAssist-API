<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Service\AppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AppointmentController extends Controller
{
    public function __construct(public AppointmentService $appointmentService) {}

    public function index(Request $request): JsonResponse
    {
        $appointments = $this->appointmentService->getAppointmentsForUser($request->user());

        return response()->json($appointments);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'doctor_id' => 'required|exists:users,id',
            'diagnosis_uuid' => 'nullable|string|exists:diagnoses,uuid',
            'message' => 'nullable|string',
            'scheduled_at' => 'nullable|date|after_or_equal:today',
            'scheduled_end_at' => 'nullable|date|after:scheduled_at',
        ]);

        $result = $this->appointmentService->createAppointment(
            $request->user(),
            $validated
        );

        return response()->json($result);
    }

    public function show(Appointment $appointment): Appointment
    {
        return $appointment->load(['doctor', 'patient', 'diagnosis']);
    }

    public function update(Request $request, Appointment $appointment): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'sometimes|string|in:pending,accepted,declined,scheduled,completed,reschedule_proposed,reschedule_requested',
            'scheduled_at' => 'nullable|date|after_or_equal:today',
            'scheduled_end_at' => 'nullable|date|after:scheduled_at',
            'location' => 'nullable|string',
        ]);

        $updatedAppointment = $this->appointmentService->updateAppointmentStatus(
            $appointment,
            $validated,
            $request->user()
        );

        return response()->json($updatedAppointment);
    }

    public function scheduleForPatient(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_id' => 'required|integer|exists:users,id',
            'scheduled_at' => 'required|date|after_or_equal:today',
            'scheduled_end_at' => 'nullable|date|after:scheduled_at',
            'location' => 'required|string|max:255',
            'purpose' => 'required|string|max:500',
        ]);

        $result = $this->appointmentService->scheduleAppointmentForPatient(
            $request->user(),
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
        ]);

        $result = $this->appointmentService->proposeReschedule(
            $appointment,
            $validated,
            $request->user()
        );

        return response()->json($result);
    }

    public function acceptReschedule(Request $request, Appointment $appointment): JsonResponse
    {
        $result = $this->appointmentService->acceptReschedule(
            $appointment,
            [],
            $request->user()
        );

        return response()->json($result);
    }

    public function destroy(Appointment $appointment): Response
    {
        $this->appointmentService->deleteAppointment($appointment);

        return response()->noContent();
    }
}
