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
            ] : null,
            'message' => $this->message,
            'is_read' => $this->is_read,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];

        // Enrich with appointment data if it's a request or diagnosis-only tag
        if (preg_match('/\[(APPOINTMENT_REQUEST|DIAGNOSIS_ONLY):([a-f0-9-]+):([a-f0-9-]+)\]/', $this->message, $matches)) {
            $appointmentUuid = $matches[2];
            $diagnosisUuid = $matches[3];

            $appointment = Appointment::where('uuid', $appointmentUuid)->with(['patient'])->first();
            $diagnosis = Diagnosis::where('uuid', $diagnosisUuid)->first();

            if ($appointment && $diagnosis) {
                $data['appointment_data'] = [
                    'status' => $appointment->status,
                    'diagnosis' => [
                        'label' => $diagnosis->label,
                        'confidence' => $diagnosis->confidence,
                        'image_path' => $diagnosis->image_path,
                        'patient_name' => $appointment->patient?->first_name.' '.$appointment->patient?->last_name,
                        'patient_age' => $diagnosis->patient_age,
                        'date' => $diagnosis->created_at->format('M d, Y'),
                    ],
                ];
            }
        }

        return $data;
    }
}
