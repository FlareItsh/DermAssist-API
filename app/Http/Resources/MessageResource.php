<?php

namespace App\Http\Resources;

use App\Models\Appointment;
use App\Models\Diagnosis;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->uuid,
            'conversation_id' => $this->relationLoaded('conversation') && $this->conversation ? $this->conversation->uuid : null,
            'sender' => $this->relationLoaded('sender') && $this->sender ? [
                'id' => $this->sender->uuid,
                'name' => trim($this->sender->first_name.' '.$this->sender->last_name),
                'avatar' => $this->sender->avatar_url,
                'avatar_path' => $this->sender->avatar_path,
            ] : null,
            'message' => $this->message,
            'is_read' => $this->is_read,
            'read_at' => $this->read_at,
            'is_edited' => ! is_null($this->edited_at),
            'edited_at' => $this->edited_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
        ];

        // Enrich with appointment data if it's a request or diagnosis-only tag
        if (preg_match('/\[(APPOINTMENT_REQUEST|DIAGNOSIS_ONLY):([a-f0-9-]+)(?::([a-f0-9-]+))?\]/', $this->message, $matches)) {
            $appointmentUuid = $matches[2];
            $diagnosisUuid = $matches[3] ?? null;

            $appointment = Appointment::where('uuid', $appointmentUuid)->with(['patient', 'doctor', 'diagnosis'])->first();
            $diagnosis = $diagnosisUuid ? Diagnosis::where('uuid', $diagnosisUuid)->first() : $appointment?->diagnosis;

            if ($appointment) {
                $patientName = $appointment->patient ? trim($appointment->patient->first_name.' '.$appointment->patient->last_name) : 'Patient';
                $data['appointment_data'] = [
                    'status' => $appointment->status,
                    'appointment_uuid' => $appointment->uuid,
                    'diagnosis_uuid' => $diagnosis?->uuid,
                    'doctor_id' => $appointment->doctor?->id,
                    'doctor_uuid' => $appointment->doctor?->uuid,
                    'doctor_name' => $appointment->doctor ? trim($appointment->doctor->first_name.' '.$appointment->doctor->last_name) : null,
                    'scheduled_at' => $appointment->scheduled_at?->toIso8601String(),
                    'scheduled_end_at' => $appointment->scheduled_end_at?->toIso8601String(),
                    'scheduled_at_formatted' => $appointment->scheduled_at ? $appointment->scheduled_at->format('M d, Y \a\t g:i A') : null,
                    'location' => $appointment->location,
                    'purpose' => $appointment->purpose,
                    'patient_name' => $patientName,
                    'patient_uuid' => $appointment->patient?->uuid,
                    'patient_avatar' => $appointment->patient?->avatar_url,
                    'patient_age' => $appointment->patient?->age,
                    'patient_gender' => $appointment->patient?->gender,
                    'diagnosis' => $diagnosis ? [
                        'label' => $diagnosis->label,
                        'confidence' => $diagnosis->confidence,
                        'image_path' => $diagnosis->image_path,
                        'patient_name' => $patientName,
                        'patient_age' => $diagnosis->patient_age ?? $appointment->patient?->age,
                        'date' => $diagnosis->created_at->format('M d, Y'),
                    ] : null,
                ];
            }
        } elseif (preg_match('/\[APPOINTMENT_DECLINED:([a-f0-9-]+)\]/', $this->message, $matches)) {
            $appointmentUuid = $matches[1];

            $appointment = Appointment::where('uuid', $appointmentUuid)->with(['patient', 'diagnosis', 'doctor'])->first();

            if ($appointment) {
                $diagnosis = $appointment->diagnosis;
                $patientName = $appointment->patient ? trim($appointment->patient->first_name.' '.$appointment->patient->last_name) : 'Patient';
                $data['appointment_data'] = [
                    'status' => 'declined',
                    'appointment_uuid' => $appointment->uuid,
                    'diagnosis_uuid' => $diagnosis?->uuid,
                    'doctor_id' => $appointment->doctor?->id,
                    'doctor_uuid' => $appointment->doctor?->uuid,
                    'doctor_name' => $appointment->doctor ? trim($appointment->doctor->first_name.' '.$appointment->doctor->last_name) : null,
                    'scheduled_at' => $appointment->scheduled_at?->toIso8601String(),
                    'scheduled_end_at' => $appointment->scheduled_end_at?->toIso8601String(),
                    'scheduled_at_formatted' => $appointment->scheduled_at ? $appointment->scheduled_at->format('M d, Y \a\t g:i A') : null,
                    'location' => $appointment->location,
                    'purpose' => $appointment->purpose,
                    'patient_name' => $patientName,
                    'diagnosis' => $diagnosis ? [
                        'label' => $diagnosis->label,
                        'confidence' => $diagnosis->confidence,
                        'image_path' => $diagnosis->image_path,
                        'patient_name' => $patientName,
                        'patient_age' => $diagnosis->patient_age ?? $appointment->patient?->age,
                        'date' => $diagnosis->created_at->format('M d, Y'),
                    ] : null,
                ];
            }
        }

        // Enrich with direct scan result data (no appointment required)
        if (preg_match('/\[SCAN_RESULT:([a-f0-9-]+)\]/', $this->message, $matches)) {
            $diagnosisUuid = $matches[1];
            $diagnosis = Diagnosis::where('uuid', $diagnosisUuid)->with('patient')->first();

            if ($diagnosis) {
                $patientName = $diagnosis->patient
                    ? trim($diagnosis->patient->first_name.' '.$diagnosis->patient->last_name)
                    : 'Patient';

                $data['appointment_data'] = [
                    'status' => 'scan_result',
                    'diagnosis' => [
                        'label' => $diagnosis->label,
                        'confidence' => $diagnosis->confidence,
                        'image_path' => $diagnosis->image_path,
                        'patient_name' => $patientName,
                        'patient_age' => $diagnosis->patient_age ?? ($diagnosis->patient?->age ?? 'N/A'),
                        'date' => $diagnosis->created_at->format('M d, Y'),
                    ],
                ];
            }
        }

        return $data;
    }
}
