<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class AppealResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'parent_appeal_id' => $this->parent_appeal_id,
            'parent_appeal_uuid' => $this->parentAppeal?->uuid,
            'reviewer_id' => $this->reviewer_id,
            'resubmission_count' => $this->resubmission_count ?? 0,
            'diagnosis_label' => $this->diagnosis_label,
            'suggested_label' => $this->suggested_label,
            'description' => $this->description,
            'status' => $this->status,
            'resolved_at' => $this->resolved_at,
            'resolution_notes' => $this->resolution_notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'reviewer' => $this->whenLoaded('reviewer', function () {
                if (! $this->reviewer) {
                    return null;
                }

                return [
                    'id' => $this->reviewer->id,
                    'uuid' => $this->reviewer->uuid,
                    'first_name' => $this->reviewer->first_name,
                    'last_name' => $this->reviewer->last_name,
                    'email' => $this->reviewer->email,
                ];
            }),
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'uuid' => $this->user->uuid,
                    'first_name' => $this->user->first_name,
                    'last_name' => $this->user->last_name,
                    'email' => $this->user->email,
                    'affiliation' => $this->user->affiliation,
                    'avatar_path' => $this->user->avatar_path,
                ];
            }),
            'diagnosis' => $this->whenLoaded('diagnosis', function () {
                if (! $this->diagnosis) {
                    return null;
                }

                return [
                    'id' => $this->diagnosis->id,
                    'uuid' => $this->diagnosis->uuid,
                    'label' => $this->diagnosis->label,
                    'original_ai_label' => $this->diagnosis->original_ai_label,
                    'scan_type' => $this->diagnosis->scan_type,
                    'is_technical_failure' => (bool) $this->diagnosis->is_technical_failure,
                    'technical_failure_reason' => $this->diagnosis->technical_failure_reason,
                    'confidence' => $this->diagnosis->confidence,
                    'probabilities' => $this->diagnosis->probabilities,
                    'image_path' => $this->diagnosis->image_path,
                    'image_url' => $this->diagnosis->image_path ? Storage::url($this->diagnosis->image_path) : null,
                    'is_out_of_scope' => (bool) $this->diagnosis->is_out_of_scope,
                    'out_of_scope_category' => $this->diagnosis->out_of_scope_category,
                    'patient_consented_dataset' => (bool) $this->diagnosis->patient_consented_dataset,
                    'doctor_authorized_dataset' => (bool) $this->diagnosis->doctor_authorized_dataset,
                    'admin_approved_dataset' => (bool) $this->diagnosis->admin_approved_dataset,
                    'is_eligible_for_dataset' => $this->diagnosis->isEligibleForDataset(),
                    'contributed_to_dataset' => (bool) $this->diagnosis->contributed_to_dataset,
                    'created_at' => $this->diagnosis->created_at,
                    'patient' => $this->diagnosis->patient ? [
                        'id' => $this->diagnosis->patient->id,
                        'uuid' => $this->diagnosis->patient->uuid,
                        'first_name' => $this->diagnosis->patient->first_name,
                        'last_name' => $this->diagnosis->patient->last_name,
                        'email' => $this->diagnosis->patient->email,
                    ] : null,
                ];
            }),
        ];
    }
}
