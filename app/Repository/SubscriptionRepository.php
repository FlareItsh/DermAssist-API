<?php

namespace App\Repository;

use App\Models\PaymentInvoice;
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

    public function getProfitsMetrics(): array
    {
        $totalRevenue = (float) PaymentInvoice::whereIn('payment_status', ['paid', 'approved'])->sum('final_amount');

        $activeSubscribersCount = Subscription::where('status', 'active')->distinct('user_id')->count('user_id');
        $totalSubscribedUsers = Subscription::distinct('user_id')->count('user_id');

        // Renewals: Users who have multiple paid/approved invoices or auto-renewals
        $renewedUsersByInvoice = PaymentInvoice::whereIn('payment_status', ['paid', 'approved'])
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('count(*) > 1')
            ->get()
            ->count();

        $renewedUsersByAutoRenew = PaymentInvoice::whereIn('payment_status', ['paid', 'approved'])
            ->where(function ($q) {
                $q->where('payment_method', 'Auto-Renew')
                    ->orWhere('transaction_reference', 'like', 'RENEWAL-%');
            })
            ->distinct('user_id')
            ->count('user_id');

        $renewedUsersCount = max($renewedUsersByInvoice, $renewedUsersByAutoRenew);

        $renewalRate = $totalSubscribedUsers > 0
            ? round(($renewedUsersCount / $totalSubscribedUsers) * 100, 1)
            : 0.0;

        $recentInvoices = PaymentInvoice::with(['user', 'subscription.plan'])
            ->whereIn('payment_status', ['paid', 'approved'])
            ->orderBy('created_at', 'desc')
            ->take(8)
            ->get()
            ->map(function ($inv) {
                return [
                    'id' => $inv->id,
                    'uuid' => $inv->uuid,
                    'amount' => (float) $inv->final_amount,
                    'payment_method' => $inv->payment_method,
                    'transaction_reference' => $inv->transaction_reference,
                    'is_renewal' => $inv->payment_method === 'Auto-Renew' || str_starts_with($inv->transaction_reference ?? '', 'RENEWAL-'),
                    'paid_at' => $inv->paid_at ?? $inv->created_at,
                    'user' => $inv->user ? [
                        'id' => $inv->user->id,
                        'name' => trim(($inv->user->first_name ?? '').' '.($inv->user->last_name ?? '')) ?: 'User #'.$inv->user->id,
                        'email' => $inv->user->email,
                    ] : null,
                    'plan' => $inv->subscription?->plan ? [
                        'id' => $inv->subscription->plan->id,
                        'name' => $inv->subscription->plan->name,
                        'tier_type' => $inv->subscription->plan->tier_type,
                        'billing_cycle' => $inv->subscription->billing_cycle,
                    ] : null,
                ];
            });

        $revenueTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthDate = now()->subMonths($i);
            $start = $monthDate->copy()->startOfMonth();
            $end = $monthDate->copy()->endOfMonth();

            $monthRevenue = (float) PaymentInvoice::whereIn('payment_status', ['paid', 'approved'])
                ->whereBetween('created_at', [$start, $end])
                ->sum('final_amount');

            $revenueTrend[] = [
                'label' => $monthDate->format('M'),
                'amount' => $monthRevenue,
            ];
        }

        $currentMonthRev = $revenueTrend[5]['amount'] ?? 0.0;
        $prevMonthRev = $revenueTrend[4]['amount'] ?? 0.0;
        $growthRate = 0.0;
        if ($prevMonthRev > 0) {
            $growthRate = round((($currentMonthRev - $prevMonthRev) / $prevMonthRev) * 100, 1);
        } elseif ($currentMonthRev > 0) {
            $growthRate = 100.0;
        }

        return [
            'total_revenue' => $totalRevenue,
            'growth_rate' => $growthRate,
            'revenue_trend' => $revenueTrend,
            'active_subscribers_count' => $activeSubscribersCount,
            'total_subscribed_users' => $totalSubscribedUsers,
            'renewed_users_count' => $renewedUsersCount,
            'renewal_rate_percentage' => $renewalRate,
            'recent_invoices' => $recentInvoices,
        ];
    }
}
