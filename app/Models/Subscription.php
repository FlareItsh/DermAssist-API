<?php

namespace App\Models;

use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'uuid',
    'user_id',
    'plan_id',
    'plan_version',
    'plan_snapshot',
    'billing_cycle',
    'status',
    'transaction_id',
    'starts_at',
    'ends_at',
    'trial_ends_at',
    'cancelled_at',
    'cancellation_reason',
])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'plan_version' => 'integer',
            'plan_snapshot' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Determine if subscription is currently active or within grace period.
     */
    public function isActive(): bool
    {
        if ($this->status !== 'active' && $this->status !== 'trialing') {
            return false;
        }

        $graceDays = $this->plan?->grace_period_days ?? 3;

        return $this->ends_at ? $this->ends_at->addDays($graceDays)->isFuture() : true;
    }

    /**
     * Get the user who owns the subscription.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the plan associated with the subscription.
     *
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Check if a feature code is included in this subscription's frozen plan snapshot.
     * If no snapshot exists (e.g. legacy subscription), falls back to live plan.
     */
    public function hasFeature(string $code): bool
    {
        if (! empty($this->plan_snapshot) && isset($this->plan_snapshot['features'])) {
            $features = $this->plan_snapshot['features'];
            if (array_key_exists($code, $features)) {
                return (bool) $features[$code];
            }
        }

        return $this->plan?->hasFeature($code) ?? false;
    }

    /**
     * Get effective feature map for this subscription.
     *
     * @return array<string, mixed>
     */
    public function getEffectiveFeatures(): array
    {
        if (! empty($this->plan_snapshot) && isset($this->plan_snapshot['features'])) {
            return $this->plan_snapshot['features'];
        }

        return $this->plan?->features ?? [];
    }

    /**
     * Get maximum clinics allowed for this subscription.
     */
    public function getMaxClinics(): ?int
    {
        if (! empty($this->plan_snapshot) && array_key_exists('max_clinics', $this->plan_snapshot)) {
            return $this->plan_snapshot['max_clinics'];
        }

        return $this->plan?->max_clinics ?? 1;
    }

    /**
     * Get maximum secretaries allowed for this subscription.
     */
    public function getMaxSecretaries(): ?int
    {
        if (! empty($this->plan_snapshot) && array_key_exists('max_secretaries', $this->plan_snapshot)) {
            return $this->plan_snapshot['max_secretaries'];
        }

        return $this->plan?->max_secretaries ?? 0;
    }

    /**
     * Get maximum doctors allowed for this subscription.
     */
    public function getMaxDoctors(): ?int
    {
        if (! empty($this->plan_snapshot) && array_key_exists('max_doctors', $this->plan_snapshot)) {
            return $this->plan_snapshot['max_doctors'];
        }

        return $this->plan?->max_doctors;
    }

    /**
     * Check whether the active plan has received updates since this subscription was created/renewed.
     */
    public function hasPlanUpdate(): bool
    {
        if (! $this->plan) {
            return false;
        }

        // 1. Version comparison
        if ($this->plan_version !== null && $this->plan->version !== null) {
            if ($this->plan->version > $this->plan_version) {
                return true;
            }
        }

        // 2. Fallback content comparison if snapshot exists
        if (! empty($this->plan_snapshot)) {
            $snapshot = $this->plan_snapshot;

            // Check if limits or pricing changed
            if (($snapshot['max_clinics'] ?? null) !== $this->plan->max_clinics ||
                ($snapshot['max_secretaries'] ?? null) !== $this->plan->max_secretaries ||
                ($snapshot['max_doctors'] ?? null) !== $this->plan->max_doctors ||
                (float) ($snapshot['price_monthly'] ?? 0) !== (float) $this->plan->price_monthly ||
                (float) ($snapshot['price_annual'] ?? 0) !== (float) $this->plan->price_annual) {
                return true;
            }

            // Check if any features differ
            if (isset($snapshot['features'])) {
                foreach ($snapshot['features'] as $code => $included) {
                    if ($code === 'custom_list') {
                        continue;
                    }
                    if ($this->plan->hasFeature($code) !== (bool) $included) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
