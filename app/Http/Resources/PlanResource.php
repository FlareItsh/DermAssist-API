<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
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
            'name' => $this->name,
            'slug' => $this->slug,
            'tier_type' => $this->tier_type,
            'price_monthly' => (float) $this->price_monthly,
            'price_annual' => (float) $this->price_annual,
            'max_doctors' => $this->max_doctors,
            'max_clinics' => $this->max_clinics,
            'features' => $this->features ?? [],
            'trial_period_days' => $this->trial_period_days,
            'grace_period_days' => $this->grace_period_days,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
