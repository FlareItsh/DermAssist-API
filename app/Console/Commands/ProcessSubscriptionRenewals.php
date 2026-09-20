<?php

namespace App\Console\Commands;

use App\Models\PaymentInvoice;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessSubscriptionRenewals extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:process-renewals';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process due subscription auto-renewals and mark expired subscriptions.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $expiredCount = self::processExpirations();
        $renewedCount = self::processRenewals();

        $this->info("Processed {$expiredCount} expired subscriptions and {$renewedCount} auto-renewals.");
    }

    /**
     * Expire subscriptions whose ends_at has passed and auto_renew is disabled.
     */
    public static function processExpirations(): int
    {
        $subscriptions = Subscription::with('plan')
            ->whereIn('status', ['active', 'trialing'])
            ->where('auto_renew', false)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->get();

        $count = 0;
        foreach ($subscriptions as $sub) {
            try {
                $sub->update([
                    'status' => 'expired',
                    'cancellation_reason' => $sub->cancellation_reason ?: 'Subscription term ended without auto-renewal.',
                ]);
                $count++;
            } catch (\Throwable $e) {
                Log::error("Failed to expire subscription #{$sub->id}: ".$e->getMessage());
            }
        }

        return $count;
    }

    /**
     * Automatically roll over subscriptions whose ends_at has passed with auto_renew enabled.
     */
    public static function processRenewals(): int
    {
        $subscriptions = Subscription::with('plan')
            ->whereIn('status', ['active', 'trialing'])
            ->where('auto_renew', true)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->get();

        $count = 0;
        foreach ($subscriptions as $sub) {
            try {
                $plan = $sub->plan;
                $previousEnd = $sub->ends_at->copy();
                $newEndsAt = $sub->billing_cycle === 'annual'
                    ? $previousEnd->copy()->addYear()
                    : $previousEnd->copy()->addMonth();

                $sub->update([
                    'starts_at' => $previousEnd,
                    'ends_at' => $newEndsAt,
                    'plan_version' => $plan ? ($plan->version ?? 1) : $sub->plan_version,
                    'plan_snapshot' => $plan ? $plan->createSnapshot() : $sub->plan_snapshot,
                ]);

                if ($plan) {
                    $amount = $sub->billing_cycle === 'annual'
                        ? (float) $plan->price_annual
                        : (float) $plan->price_monthly;

                    PaymentInvoice::create([
                        'subscription_id' => $sub->id,
                        'user_id' => $sub->user_id,
                        'amount' => $amount,
                        'discount_amount' => 0.00,
                        'final_amount' => $amount,
                        'payment_method' => 'Auto-Renew',
                        'payment_status' => 'approved',
                        'transaction_reference' => 'RENEWAL-'.now()->timestamp.'-'.$sub->id,
                    ]);
                }

                $count++;
            } catch (\Throwable $e) {
                Log::error("Failed to process auto-renewal for subscription #{$sub->id}: ".$e->getMessage());
            }
        }

        return $count;
    }
}
