<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Service\AppointmentService;
use App\Service\UserService;
use Illuminate\Http\Request;

class DoctorPatientController extends Controller
{
    public function __construct(
        private UserService $userService,
        private AppointmentService $appointmentService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $doctorUuid = ($user->role?->slug === 'secretary' && $user->doctor)
            ? $user->doctor->uuid
            : $user->uuid;

        return $this->userService->listDoctorPatients(
            $doctorUuid,
            $request->input('per_page', 15)
        );
    }

    public function store(Request $request)
    {
        return $this->userService->createDoctorRegisteredPatient(
            $request->all(),
            $request->user()
        );
    }

    public function enable(string $uuid)
    {
        return $this->userService->setAccountStatus($uuid, 'active');
    }

    public function disable(string $uuid)
    {
        return $this->userService->setAccountStatus($uuid, 'disabled');
    }

    public function destroy(string $uuid)
    {
        $this->userService->deleteUser($uuid);

        return response()->json(['message' => 'Deleted successfully'], 200);
    }

    public function scheduleAction(Request $request, string $uuid)
    {
        $request->validate([
            'action' => 'required|in:delete,disable',
            'scheduled_at' => 'required|date',
        ]);

        return $this->userService->scheduleAccountAction(
            $uuid,
            $request->input('action'),
            $request->input('scheduled_at')
        );
    }

    public function cancelSchedule(string $uuid)
    {
        return $this->userService->cancelScheduledAction($uuid);
    }

    public function sendScanResult(Request $request, string $uuid)
    {
        $request->validate([
            'diagnosis_uuid' => 'required|string',
        ]);

        $patient = User::where('uuid', $uuid)->firstOrFail();

        $conversation = Conversation::where('doctor_id', $request->user()->id)
            ->where('patient_id', $patient->id)
            ->firstOrFail();

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $request->user()->id,
            'message' => '[SCAN_RESULT:'.$request->input('diagnosis_uuid').']',
            'is_read' => false,
        ]);

        $conversation->touch();

        return response()->json(['message' => 'Scan result sent.'], 201);
    }

    public function scheduleAppointment(Request $request, string $uuid)
    {
        $request->validate([
            'scheduled_at' => 'required|date|after_or_equal:today',
            'scheduled_end_at' => 'nullable|date|after:scheduled_at',
            'location' => 'required|string|max:255',
            'purpose' => 'required|string|max:500',
        ]);

        $patient = User::where('uuid', $uuid)->firstOrFail();

        $result = $this->appointmentService->scheduleAppointmentForPatient(
            $request->user(),
            [
                'patient_id' => $patient->id,
                'scheduled_at' => $request->input('scheduled_at'),
                'scheduled_end_at' => $request->input('scheduled_end_at'),
                'location' => $request->input('location'),
                'purpose' => $request->input('purpose'),
            ]
        );

        return response()->json($result, 201);
    }
}
