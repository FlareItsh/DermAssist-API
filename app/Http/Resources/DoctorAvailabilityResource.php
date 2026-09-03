<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DoctorAvailabilityResource extends JsonResource
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
            'doctor_id' => $this->doctor_id,
            'clinic_id' => $this->clinic_id,
            'location_name' => $this->location_name,
            'clinic' => $this->clinic ? [
                'id' => $this->clinic->id,
                'uuid' => $this->clinic->uuid,
                'name' => $this->clinic->name,
                'address' => $this->clinic->address,
                'phone' => $this->clinic->phone,
            ] : null,
            'available_date' => $this->available_date->toDateString(),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'is_available' => (bool) $this->is_available,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
