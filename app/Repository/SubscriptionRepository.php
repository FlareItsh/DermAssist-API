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

        $activeSubscriptions = Subscription::where('status', 'active')->with('plan')->get();

        $mrr = $activeSubscriptions->sum(function ($sub) {
            if (! $sub->plan) {
                return 0;
            }

            return $sub->billing_cycle === 'annual'
                ? $sub->plan->price_annual / 12
                : $sub->plan->price_monthly;
        });

        $thirtyDaysAgo = now()->subDays(30);

        $newSubscribers30d = Subscription::where('status', 'active')
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->count();

        $priorActiveSubscriptions = $activeSubscriptions->filter(function ($sub) use ($thirtyDaysAgo) {
            return $sub->created_at < $thirtyDaysAgo;
        });

        $priorMrr = $priorActiveSubscriptions->sum(function ($sub) {
            if (! $sub->plan) {
                return 0;
            }

            return $sub->billing_cycle === 'annual'
                ? $sub->plan->price_annual / 12
                : $sub->plan->price_monthly;
        });

        $mrrDelta = $mrr - $priorMrr;
        $mrrGrowthPct = 0;
        $mrrDirection = 'neutral';

        if ($priorMrr > 0) {
            $mrrGrowthPct = round(($mrrDelta / $priorMrr) * 100, 1);
            $mrrDirection = $mrrGrowthPct > 0 ? 'up' : ($mrrGrowthPct < 0 ? 'down' : 'neutral');
        } elseif ($mrr > 0) {
            $mrrGrowthPct = 100;
            $mrrDirection = 'up';
        }

        return [
            'mrr' => round($mrr, 2),
            'mrr_growth' => [
                'value' => ($mrrGrowthPct >= 0 ? '+' : '').$mrrGrowthPct.'%',
                'direction' => $mrrDirection,
                'label' => 'last 30d',
            ],
            'active_count' => $active,
            'new_subscribers_30d' => $newSubscribers30d,
            'active_growth' => [
                'value' => ($newSubscribers30d > 0 ? "+{$newSubscribers30d}" : '0'),
                'direction' => $newSubscribers30d > 0 ? 'up' : 'neutral',
                'label' => 'last 30d',
            ],
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
