<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CouponResource extends JsonResource
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
            'code' => $this->code,
            'discount_type' => $this->discount_type,
            'value' => (float) $this->value,
            'duration' => $this->duration,
            'duration_in_months' => $this->duration_in_months,
            'valid_from' => $this->valid_from?->toIso8601String(),
            'valid_until' => $this->valid_until?->toIso8601String(),
            'max_redemptions' => $this->max_redemptions,
            'times_redeemed' => $this->times_redeemed,
            'max_redemptions_per_user' => $this->max_redemptions_per_user,
            'min_spend' => $this->min_spend !== null ? (float) $this->min_spend : null,
            'applicable_plans' => $this->applicable_plans,
            'customer_segment' => $this->customer_segment,
            'is_active' => $this->is_active,
            'is_valid' => $this->isValid(),
            'is_archived' => $this->trashed(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'has_redemptions' => ($this->times_redeemed > 0) || $this->subscriptionDiscounts()->exists(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
