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
            'confidence' => $this->confidence,
            'all_probabilities' => $this->probabilities,
            'image_url' => Storage::url($this->image_path),
            'status' => $this->status,
            'patient_consented_dataset' => (bool) $this->patient_consented_dataset,
            'contributed_to_dataset' => (bool) $this->contributed_to_dataset,
            'created_at' => $this->created_at,
            'image_quality' => $this->resource->image_quality ?? null,
            'doctor_availability' => $this->resource->doctor_availability ?? null,
            'is_inconclusive' => $this->resource->is_inconclusive ?? ($this->label === 'Inconclusive' || $this->label === 'None'),
            'clinical_feedback' => $this->resource->clinical_feedback ?? null,
        ];
    }
}
