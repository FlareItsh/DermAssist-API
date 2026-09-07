<?php

namespace App\Repository;

use App\Models\Subscription;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class SubscriptionRepository
{
    public function getDashboardMetrics(): array
    {
        $active = Subscription::where('status', 'active')->count();
        $trialing = Subscription::where('status', 'trialing')->count();
        $pastDue = Subscription::where('status', 'past_due')->count();

        $mrr = Subscription::where('status', 'active')
            ->with('plan')
            ->get()
            ->sum(function ($sub) {
                if (! $sub->plan) {
                    return 0;
                }

                return $sub->billing_cycle === 'annual'
                    ? $sub->plan->price_annual / 12
                    : $sub->plan->price_monthly;
            });

        return [
            'mrr' => round($mrr, 2),
            'active_count' => $active,
            'trialing_count' => $trialing,
            'past_due_count' => $pastDue,
        ];
    }

    public function getRecentSubscribers(int $limit = 10): Collection
    {
        return Subscription::with(['user', 'plan'])
            ->orderBy('created_at', 'desc')
            ->take($limit)
            ->get();
    }

    public function paginate(?string $status = null, ?string $tier = null, int $perPage = 20): LengthAwarePaginator
    {
        return Subscription::with(['user', 'plan'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($tier, fn ($q) => $q->whereHas('plan', fn ($p) => $p->where('tier_type', $tier)))
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }
}
