<?php

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'slug',
    'tier_type',
    'price_monthly',
    'price_annual',
    'max_doctors',
    'max_clinics',
    'max_secretaries',
    'features',
    'trial_period_days',
    'grace_period_days',
    'sort_order',
    'is_active',
])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'features' => 'array',
            'is_active' => 'boolean',
            'price_monthly' => 'decimal:2',
            'price_annual' => 'decimal:2',
            'max_doctors' => 'integer',
            'max_clinics' => 'integer',
            'max_secretaries' => 'integer',
            'trial_period_days' => 'integer',
            'grace_period_days' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Get the subscriptions associated with this plan.
     *
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Get the normalized features associated with this plan.
     *
     * @return BelongsToMany<Feature, $this>
     */
    public function planFeatures(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'plan_has_features', 'plan_id', 'feature_id')
            ->withPivot('is_included')
            ->withTimestamps();
    }

    /**
     * Check if a feature code is active and included in this plan.
     */
    public function hasFeature(string $code): bool
    {
        $feature = $this->planFeatures->firstWhere('code', $code);
        if ($feature) {
            return (bool) ($feature->pivot->is_included && $feature->is_active);
        }

        // Fallback to legacy features JSON if relationship not loaded or empty
        $legacy = $this->features ?? [];

        return ! empty($legacy[$code]);
    }
}
