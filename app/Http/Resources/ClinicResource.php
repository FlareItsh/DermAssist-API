<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClinicResource extends JsonResource
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
            'owner_doctor_id' => $this->owner_doctor_id,
            'name' => $this->name,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'geo_latitude' => $this->geo_latitude,
            'geo_longitude' => $this->geo_longitude,
            'is_active' => (bool) $this->is_active,
            'is_owner' => $request->user() ? $this->owner_doctor_id === $request->user()->id : false,
            'owner_doctor' => $this->owner ? [
                'id' => $this->owner->id,
                'uuid' => $this->owner->uuid,
                'full_name' => trim($this->owner->first_name.' '.$this->owner->last_name),
                'email' => $this->owner->email,
            ] : null,
            'my_role' => $this->pivot?->role ?? null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
