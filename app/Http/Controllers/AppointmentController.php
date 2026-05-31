<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Service\AppointmentService;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    protected $appointmentService;

    public function __construct(AppointmentService $appointmentService)
    {
        $this->appointmentService = $appointmentService;
    }

    public function index(Request $request)
    {
        $appointments = $this->appointmentService->getAppointmentsForUser($request->user());

        return response()->json($appointments);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'doctor_id' => 'required|exists:users,id',
            'diagnosis_uuid' => 'nullable|string|exists:diagnoses,uuid',
            'message' => 'nullable|string',
            'scheduled_at' => 'nullable|date',
        ]);

        $result = $this->appointmentService->createAppointment(
            $request->user(),
            $validated
        );

        return response()->json($result);
    }

    public function show(Appointment $appointment)
    {
        return $appointment->load(['doctor', 'patient', 'diagnosis']);
    }

    public function update(Request $request, Appointment $appointment)
    {
        $validated = $request->validate([
            'status' => 'sometimes|string|in:pending,accepted,declined,scheduled,completed',
            'scheduled_at' => 'nullable|date',
            'location' => 'nullable|string',
        ]);

        $updatedAppointment = $this->appointmentService->updateAppointmentStatus(
            $appointment,
            $validated,
            $request->user()
        );

        return response()->json($updatedAppointment);
    }

    public function destroy(Appointment $appointment)
    {
        $this->appointmentService->deleteAppointment($appointment);

        return response()->noContent();
    }
}
