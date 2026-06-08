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
        $type = $this->clinicalNote ? 'doctor_diagnosis' : 'scan';
        $title = $type === 'doctor_diagnosis' 
            ? 'Doctor\'s Diagnosis' 
            : 'AI Scan Result';

        if ($this->label && $this->label !== 'None') {
            $title .= ': ' . $this->label;
        }

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'type' => $type,
            'title' => $title,
            'label' => $this->label,
            'confidence' => $this->confidence,
            'probabilities' => $this->probabilities,
            'image_path' => $this->image_path,
            'created_at' => $this->created_at,
            'patient' => $this->patient,
            'doctor' => $this->doctor ?? ($this->clinicalNote ? $this->clinicalNote->doctor : null),
            'clinical_note' => $this->clinicalNote,
        ];
    }
}