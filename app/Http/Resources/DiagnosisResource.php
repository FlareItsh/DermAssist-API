<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class DiagnosisResource extends JsonResource
{
    /**
     * The "data" wrapper that should be applied.
     *
     * @var string|null
     */
    public static $wrap = null;

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
            'label' => $this->label,
            'original_ai_label' => $this->original_ai_label,
            'scan_type' => $this->scan_type,
            'is_technical_failure' => (bool) $this->is_technical_failure,
            'technical_failure_reason' => $this->technical_failure_reason,
            'confidence' => $this->confidence,
            'all_probabilities' => $this->probabilities,
            'image_url' => $this->image_path ? Storage::url($this->image_path) : null,
            'status' => $this->status,
            'patient_consented_dataset' => (bool) $this->patient_consented_dataset,
            'doctor_authorized_dataset' => (bool) $this->doctor_authorized_dataset,
            'doctor_authorized_at' => $this->doctor_authorized_at,
            'admin_approved_dataset' => (bool) $this->admin_approved_dataset,
            'admin_approved_at' => $this->admin_approved_at,
            'is_eligible_for_dataset' => $this->isEligibleForDataset(),
            'contributed_to_dataset' => (bool) $this->contributed_to_dataset,
            'out_of_scope_category' => $this->out_of_scope_category,
            'is_out_of_scope' => (bool) $this->is_out_of_scope,
            'out_of_scope_contributed' => (bool) $this->out_of_scope_contributed,
            'created_at' => $this->created_at,
            'image_quality' => $this->resource->image_quality ?? null,
            'doctor_availability' => $this->resource->doctor_availability ?? null,
            'is_inconclusive' => $this->resource->is_inconclusive ?? ($this->label === 'Inconclusive' || $this->label === 'None'),
            'clinical_feedback' => $this->resource->clinical_feedback ?? null,
            'appeal' => $this->appeal ? [
                'id' => $this->appeal->id,
                'uuid' => $this->appeal->uuid,
                'parent_appeal_id' => $this->appeal->parent_appeal_id,
                'resubmission_count' => $this->appeal->resubmission_count ?? 0,
                'status' => $this->appeal->status,
                'diagnosis_label' => $this->appeal->diagnosis_label,
                'suggested_label' => $this->appeal->suggested_label,
                'description' => $this->appeal->description,
                'resolution_notes' => $this->appeal->resolution_notes,
                'resolved_at' => $this->appeal->resolved_at,
                'created_at' => $this->appeal->created_at,
            ] : null,
        ];
    }
}
