<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecordResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->is_technical_failure) {
            $type = 'technical_failure';
            $title = 'Technical Failure: '.($this->technical_failure_reason ?: 'Scan Incomplete');
            $displayLabel = 'Technical Failure';
        } else {
            $type = $this->clinicalNote ? 'doctor_diagnosis' : 'scan';
            $title = $type === 'doctor_diagnosis'
                ? 'Doctor\'s Diagnosis'
                : 'AI Scan Result';

            if ($this->is_out_of_scope || ! empty($this->out_of_scope_category) || strtolower($this->label) === 'outofscope') {
                $possible = $this->clinicalNote?->final_diagnosis
                    ?: ($this->out_of_scope_category ? ucfirst($this->out_of_scope_category) : null);

                $displayLabel = $possible
                    ? "Out of Scope (Possible {$possible})"
                    : 'Out of Scope';
            } else {
                $displayLabel = $this->clinicalNote?->final_diagnosis ?: $this->label;
            }

            if ($displayLabel && $displayLabel !== 'None') {
                $title .= ': '.$displayLabel;
            }
        }

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'type' => $type,
            'title' => $title,
            'label' => $displayLabel,
            'raw_label' => $this->label,
            'original_ai_label' => $this->original_ai_label,
            'scan_type' => $this->scan_type,
            'is_technical_failure' => (bool) $this->is_technical_failure,
            'technical_failure_reason' => $this->technical_failure_reason,
            'is_out_of_scope' => (bool) $this->is_out_of_scope,
            'out_of_scope_category' => $this->out_of_scope_category,
            'confidence' => $this->confidence,
            'probabilities' => $this->probabilities,
            'image_path' => $this->image_path,
            'created_at' => $this->created_at,
            'status' => $this->status,
            'patient_uuid' => $this->patient_uuid ?? $this->user_uuid,
            'doctor_uuid' => $this->doctor_uuid,
            'patient' => $this->patient ?? $this->user,
            'doctor' => $this->doctor ?? ($this->clinicalNote ? $this->clinicalNote->doctor : null),
            'clinical_note' => $this->clinicalNote,
            'is_appealed' => (bool) ($this->relationLoaded('appeal') ? $this->appeal : $this->appeal()->exists()),
            'appeal' => $this->whenLoaded('appeal', function () {
                return $this->appeal ? new AppealResource($this->appeal) : null;
            }),
        ];
    }
}
