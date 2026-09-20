<?php

namespace App\Service;

use App\Http\Resources\PlanResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Coupon;
use App\Models\PaymentInvoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

class DoctorSubscriptionService
{
    public function __construct(private PaymentGatewayService $paymentGatewayService) {}

    /**
     * Get active plans for doctors to subscribe to.
     */
    public function getPlans(): JsonResponse
    {
        $plans = Plan::with('planFeatures')
            ->where('is_active', true)
            ->orderBy('price_monthly', 'asc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => PlanResource::collection($plans),
        ]);
    }

    /**
     * Get current doctor's active/latest subscription and payment history.
     */
    public function getMySubscription(User $user): JsonResponse
    {
        $subscription = $user->getActiveSubscription();

        if (! $subscription) {
            $subscription = Subscription::with('plan.planFeatures')
                ->where('user_id', $user->id)
                ->whereIn('status', ['active', 'trialing'])
                ->orderBy('created_at', 'desc')
                ->first();
        }

        if ($subscription && ! $subscription->relationLoaded('plan')) {
            $subscription->load('plan.planFeatures');
        }

        $invoices = PaymentInvoice::with('subscription.plan')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        $doctorSeatUsage = $user->role?->slug === 'doctor' ? $user->getDoctorSeatUsage() : null;

        // Associate Clinic Membership Check
        $associateCoverage = null;
        $clinicMemberships = $user->clinicMemberships()
            ->wherePivot('status', 'active')
            ->with('owner')
            ->get();

        // Match the specific clinic membership whose owner sponsors the subscription
        $sponsoringClinic = null;
        if ($subscription && $subscription->user_id !== $user->id) {
            $sponsoringClinic = $clinicMemberships->first(fn ($c) => $c->owner_doctor_id === $subscription->user_id);
        }

        if (! $sponsoringClinic) {
            $sponsoringClinic = $clinicMemberships->first(fn ($c) => $c->owner?->getActiveSubscription() !== null);
        }

        if (! $sponsoringClinic) {
            $sponsoringClinic = $clinicMemberships->first();
        }

        if ($sponsoringClinic && $sponsoringClinic->owner) {
            $ownerSub = $sponsoringClinic->owner->getActiveSubscription();
            $associateCoverage = [
                'clinic_id' => $sponsoringClinic->id,
                'clinic_uuid' => $sponsoringClinic->uuid,
                'clinic_name' => $sponsoringClinic->name,
                'owner_name' => trim($sponsoringClinic->owner->first_name.' '.$sponsoringClinic->owner->last_name),
                'role' => $sponsoringClinic->pivot->role ?? 'associate',
                'plan_name' => $ownerSub?->plan?->name ?? 'Clinic Group Plan',
                'is_active' => $ownerSub !== null,
            ];
        }

        $directSub = $user->getDirectSubscription();
        if ($directSub && ! $directSub->relationLoaded('plan')) {
            $directSub->load('plan.planFeatures');
        }

        $isInherited = $subscription && $subscription->user_id !== $user->id;

        return response()->json([
            'status' => 'success',
            'data' => [
                'subscription' => $subscription ? new SubscriptionResource($subscription) : null,
                'direct_subscription' => $directSub ? new SubscriptionResource($directSub) : null,
                'invoices' => $invoices,
                'doctor_seat_usage' => $doctorSeatUsage,
                'is_inherited' => $isInherited,
                'associate_coverage' => $associateCoverage,
            ],
        ]);
    }

    /**
     * Validate a coupon code.
     */
    public function validateCoupon(string $code, float $amount): JsonResponse
    {
        $coupon = Coupon::where('code', strtoupper(trim($code)))->first();

        if (! $coupon || ! $coupon->isValid()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or expired coupon code.',
            ], 422);
        }

        $discountAmount = 0.00;
        if ($coupon->discount_type === 'percentage') {
            $discountAmount = ($amount * $coupon->value) / 100;
        } else {
            $discountAmount = min($amount, (float) $coupon->value);
        }

        $finalAmount = max(0, $amount - $discountAmount);

        return response()->json([
            'status' => 'success',
            'data' => [
                'code' => $coupon->code,
                'discount_type' => $coupon->discount_type,
                'value' => $coupon->value,
                'discount_amount' => round($discountAmount, 2),
                'final_amount' => round($finalAmount, 2),
            ],
        ]);
    }

    /**
     * Handle subscription purchase/checkout.
     */
    public function checkout(User $user, array $data): JsonResponse
    {
        $plan = Plan::where('uuid', $data['plan_uuid'])->firstOrFail();

        $billingCycle = $data['billing_cycle'] ?? 'monthly';

        // Guard: Prevent duplicate purchase if doctor already holds an active subscription to the same plan and cycle
        $activeSub = $user->getDirectSubscription();
        if ($activeSub && $activeSub->isActive() && $activeSub->plan_id === $plan->id && $activeSub->billing_cycle === $billingCycle) {
            $endsAtFormatted = $activeSub->ends_at ? $activeSub->ends_at->format('M d, Y') : 'the end of your current billing cycle';

            return response()->json([
                'status' => 'error',
                'message' => "You already have an active {$billingCycle} subscription to {$plan->name} valid until {$endsAtFormatted}. Renewal of this plan is only available once your current subscription expires.",
            ], 422);
        }

        $paymentMethod = $data['payment_method'] ?? 'paymongo';
        $originalAmount = $billingCycle === 'annual' ? (float) $plan->price_annual : (float) $plan->price_monthly;

        $discountAmount = 0.00;
        if (! empty($data['coupon_code'])) {
            $coupon = Coupon::where('code', strtoupper(trim($data['coupon_code'])))->first();
            if ($coupon && $coupon->isValid()) {
                if ($coupon->discount_type === 'percentage') {
                    $discountAmount = ($originalAmount * $coupon->value) / 100;
                } else {
                    $discountAmount = min($originalAmount, (float) $coupon->value);
                }
                $coupon->increment('times_redeemed');
            }
        }

        $finalAmount = max(0, $originalAmount - $discountAmount);

        // Handle proof of payment upload if present
        $proofPath = null;
        if (isset($data['proof_of_payment']) && $data['proof_of_payment'] instanceof UploadedFile) {
            $proofPath = $data['proof_of_payment']->store('payment_proofs', 'public');
        }

        // Clean up any previous abandoned/unpaid pending subscriptions and invoices for this doctor
        $abandonedPendingSubscriptions = Subscription::where('user_id', $user->id)
            ->where('status', 'pending')
            ->get();

        foreach ($abandonedPendingSubscriptions as $pendingSub) {
            PaymentInvoice::where('subscription_id', $pendingSub->id)
                ->where('payment_status', 'pending')
                ->delete();
            $pendingSub->delete();
        }

        // Create subscription in pending state (only active once PayMongo confirms payment)
        $startsAt = now();
        $endsAt = $billingCycle === 'annual' ? now()->addYear() : now()->addMonth();

        $planSnapshot = $plan->createSnapshot();

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_version' => $plan->version ?? 1,
            'plan_snapshot' => $planSnapshot,
            'billing_cycle' => $billingCycle,
            'status' => 'pending',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        // Create Payment Invoice record
        $invoice = PaymentInvoice::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'amount' => $originalAmount,
            'discount_amount' => round($discountAmount, 2),
            'final_amount' => round($finalAmount, 2),
            'payment_method' => $paymentMethod,
            'payment_status' => 'pending',
            'proof_of_payment_path' => $proofPath,
            'transaction_reference' => $data['transaction_reference'] ?? null,
        ]);

        $checkoutUrl = null;
        if ($paymentMethod === 'paymongo') {
            $gatewayData = $this->paymentGatewayService->createPayMongoSession($user, $subscription, $invoice);
            $checkoutUrl = $gatewayData['checkout_url'];
        } elseif ($paymentMethod === 'stripe') {
            $gatewayData = $this->paymentGatewayService->createStripeSession($user, $subscription, $invoice);
            $checkoutUrl = $gatewayData['checkout_url'];
        }

        return response()->json([
            'status' => 'success',
            'message' => $checkoutUrl ? 'Redirecting to secure payment checkout gateway...' : 'Subscription order submitted successfully! Waiting for payment verification.',
            'data' => [
                'subscription' => new SubscriptionResource($subscription->load('plan')),
                'invoice' => $invoice,
                'checkout_url' => $checkoutUrl,
            ],
        ], 201);
    }

    /**
     * Toggle auto-renew on the doctor's direct subscription.
     */
    public function toggleAutoRenew(User $user, bool $autoRenew): JsonResponse
    {
        $subscription = $user->getDirectSubscription();

        if (! $subscription) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active direct subscription found to modify.',
            ], 404);
        }

        $updateData = ['auto_renew' => $autoRenew];

        // If re-enabling auto-renew on a subscription that was marked for cancellation, clear cancellation metadata
        if ($autoRenew && $subscription->isPendingCancellation()) {
            $updateData['cancelled_at'] = null;
            $updateData['cancellation_reason'] = null;
        }

        $subscription->update($updateData);

        return response()->json([
            'status' => 'success',
            'message' => $autoRenew
                ? 'Auto-renewal has been enabled.'
                : 'Auto-renewal has been disabled. Your plan will remain active until the end of the billing period.',
            'data' => [
                'subscription' => new SubscriptionResource($subscription->fresh(['plan.planFeatures'])),
            ],
        ]);
    }

    /**
     * Cancel the doctor's direct subscription at the end of the billing cycle.
     */
    public function cancelSubscription(User $user, array $data): JsonResponse
    {
        $subscription = $user->getDirectSubscription();

        if (! $subscription) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active direct subscription found to cancel.',
            ], 404);
        }

        $reason = $data['reason'] ?? 'User requested cancellation';
        if (! empty($data['feedback'])) {
            $reason .= ' - Feedback: '.$data['feedback'];
        }

        // Cancel at period end: keep status active until ends_at, disable auto-renew, record cancellation
        $subscription->update([
            'auto_renew' => false,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Subscription cancelled successfully. You will continue to have full access until '.($subscription->ends_at ? $subscription->ends_at->format('M d, Y') : 'the end of your billing cycle').'.',
            'data' => [
                'subscription' => new SubscriptionResource($subscription->fresh(['plan.planFeatures'])),
            ],
        ]);
    }

    /**
     * Resume a subscription scheduled for cancellation / re-enable auto-renew.
     */
    public function resumeSubscription(User $user): JsonResponse
    {
        $subscription = $user->getDirectSubscription();

        if (! $subscription) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active direct subscription found to resume.',
            ], 404);
        }

        if ($subscription->auto_renew && ! $subscription->cancelled_at) {
            return response()->json([
                'status' => 'info',
                'message' => 'Subscription already has auto-renew enabled.',
                'data' => [
                    'subscription' => new SubscriptionResource($subscription->fresh(['plan.planFeatures'])),
                ],
            ]);
        }

        $subscription->update([
            'auto_renew' => true,
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Subscription resumed successfully! Auto-renewal is now active.',
            'data' => [
                'subscription' => new SubscriptionResource($subscription->fresh(['plan.planFeatures'])),
            ],
        ]);
    }
}
