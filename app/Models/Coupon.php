<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code',
    'discount_type',
    'value',
    'duration',
    'duration_in_months',
    'valid_from',
    'valid_until',
    'max_redemptions',
    'times_redeemed',
    'max_redemptions_per_user',
    'min_spend',
    'applicable_plans',
    'customer_segment',
    'is_active',
])]
class Coupon extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_spend' => 'decimal:2',
            'applicable_plans' => 'array',
            'max_redemptions_per_user' => 'integer',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'is_active' => 'boolean',
            'max_redemptions' => 'integer',
            'times_redeemed' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    public function subscriptionDiscounts(): HasMany
    {
        return $this->hasMany(SubscriptionDiscount::class);
    }

    public function isValid(?float $amount = null, ?User $user = null, ?Plan $plan = null): bool
    {
        if (! $this->is_active || $this->trashed()) {
            return false;
        }

        if ($this->valid_from && $this->valid_from->isFuture()) {
            return false;
        }

        if ($this->valid_until && $this->valid_until->isPast()) {
            return false;
        }

        if ($this->max_redemptions !== null && $this->times_redeemed >= $this->max_redemptions) {
            return false;
        }

        if ($amount !== null && $this->min_spend !== null && $amount < (float) $this->min_spend) {
            return false;
        }

        if ($plan !== null && ! empty($this->applicable_plans)) {
            $planIds = array_map('strval', $this->applicable_plans);
            if (! in_array((string) $plan->id, $planIds, true) && ! in_array((string) $plan->uuid, $planIds, true)) {
                return false;
            }
        }

        if ($user !== null) {
            if ($this->max_redemptions_per_user !== null) {
                $userRedemptions = SubscriptionDiscount::where('coupon_id', $this->id)
                    ->whereHas('subscription', function ($query) use ($user) {
                        $query->where('user_id', $user->id);
                    })
                    ->count();

                if ($userRedemptions >= $this->max_redemptions_per_user) {
                    return false;
                }
            }

            if ($this->customer_segment === 'new_doctors') {
                $hasPriorSub = Subscription::where('user_id', $user->id)->exists();
                if ($hasPriorSub) {
                    return false;
                }
            } elseif ($this->customer_segment === 'renewals') {
                $hasPriorSub = Subscription::where('user_id', $user->id)->exists();
                if (! $hasPriorSub) {
                    return false;
                }
            }
        }

        return true;
    }
}
