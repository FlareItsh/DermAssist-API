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

        return response()->json([
            'status' => 'success',
            'data' => [
                'subscription' => $subscription ? new SubscriptionResource($subscription) : null,
                'invoices' => $invoices,
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

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
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
}
