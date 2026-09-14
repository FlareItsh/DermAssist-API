<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
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
            'user_id' => $this->user_id,
            'plan_id' => $this->plan_id,
            'plan_version' => $this->plan_version,
            'plan_snapshot' => $this->plan_snapshot,
            'effective_features' => $this->getEffectiveFeatures(),
            'effective_max_clinics' => $this->getMaxClinics(),
            'effective_max_secretaries' => $this->getMaxSecretaries(),
            'effective_max_doctors' => $this->getMaxDoctors(),
            'has_plan_update' => $this->hasPlanUpdate(),
            'latest_plan_version' => $this->plan?->version,
            'user' => new UserResource($this->whenLoaded('user')),
            'plan' => new PlanResource($this->whenLoaded('plan')),
            'billing_cycle' => $this->billing_cycle,
            'auto_renew' => $this->isAutoRenew(),
            'is_pending_cancellation' => $this->isPendingCancellation(),
            'status' => $this->status,
            'transaction_id' => $this->transaction_id,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'is_active' => $this->isActive(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
