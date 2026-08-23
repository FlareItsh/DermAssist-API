<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'location' => $this->location,
            'street' => $this->street,
            'barangay' => $this->barangay,
            'city' => $this->city,
            'province' => $this->province,
            'country' => $this->country,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'age' => $this->age,
            'gender' => $this->gender,
            'affiliation' => $this->affiliation,
            'prcNumber' => $this->prc_number,
            'email' => $this->email,
            'role' => $this->role->slug,
            'avatar_path' => $this->avatar_path,
            'total_scans' => $this->diagnoses_count ?? 0,
            'doctor_verification' => new VerificationResource($this->latestDoctorVerification),
            'is_doctor_registered' => (bool) $this->is_doctor_registered,
            'registered_by_doctor_id' => $this->registered_by_doctor_id,
            'registered_by_doctor' => $this->registeredByDoctor ? [
                'id' => $this->registeredByDoctor->id,
                'uuid' => $this->registeredByDoctor->uuid,
                'first_name' => $this->registeredByDoctor->first_name,
                'last_name' => $this->registeredByDoctor->last_name,
                'email' => $this->registeredByDoctor->email,
                'affiliation' => $this->registeredByDoctor->affiliation,
                'prc_number' => $this->registeredByDoctor->prc_number,
                'city' => $this->registeredByDoctor->city,
                'province' => $this->registeredByDoctor->province,
                'latitude' => $this->registeredByDoctor->latitude,
                'longitude' => $this->registeredByDoctor->longitude,
                'avatar_path' => $this->registeredByDoctor->avatar_path,
            ] : null,
            'account_status' => $this->account_status ?? 'active',
            'account_action' => $this->account_action,
            'account_action_scheduled_at' => $this->account_action_scheduled_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
