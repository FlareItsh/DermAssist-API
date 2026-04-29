<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'doctor' => $this->relationLoaded('doctor') && $this->doctor ? [
                'id' => $this->doctor->uuid,
                'name' => trim($this->doctor->first_name.' '.$this->doctor->last_name),
                'avatar' => $this->doctor->avatar_url,
            ] : null,
            'patient' => $this->relationLoaded('patient') && $this->patient ? [
                'id' => $this->patient->uuid,
                'name' => trim($this->patient->first_name.' '.$this->patient->last_name),
                'avatar' => $this->patient->avatar_url,
            ] : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
