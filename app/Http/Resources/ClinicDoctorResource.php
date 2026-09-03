<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClinicDoctorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = is_array($this->resource) ? (object) $this->resource : $this->resource;

        return [
            'pivot_id' => $data->pivot_id ?? null,
            'role' => $data->role ?? 'associate',
            'status' => $data->status ?? 'active',
            'joined_at' => $data->joined_at ?? null,
            'clinic' => [
                'id' => $data->clinic_id ?? null,
                'uuid' => $data->clinic_uuid ?? null,
                'name' => $data->clinic_name ?? null,
            ],
            'doctor' => [
                'id' => $data->doctor_id ?? null,
                'uuid' => $data->doctor_uuid ?? null,
                'first_name' => $data->first_name ?? '',
                'middle_name' => $data->middle_name ?? null,
                'last_name' => $data->last_name ?? '',
                'full_name' => trim(($data->first_name ?? '').' '.($data->last_name ?? '')),
                'email' => $data->email ?? '',
                'prc_number' => $data->prc_number ?? null,
                'affiliation' => $data->affiliation ?? null,
                'avatar_path' => $data->avatar_path ?? null,
                'account_status' => $data->account_status ?? 'active',
            ],
        ];
    }
}
