<?php

namespace App\Http\Controllers;

use App\Service\DoctorSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DoctorSubscriptionController extends Controller
{
    public function __construct(private DoctorSubscriptionService $doctorSubscriptionService) {}

    /**
     * Get active plans.
     */
    public function plans(): JsonResponse
    {
        return $this->doctorSubscriptionService->getPlans();
    }

    /**
     * Get current doctor's subscription status.
     */
    public function mySubscription(Request $request): JsonResponse
    {
        return $this->doctorSubscriptionService->getMySubscription($request->user());
    }

    /**
     * Validate a coupon code.
     */
    public function validateCoupon(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string',
            'amount' => 'required|numeric|min:0',
        ]);

        return $this->doctorSubscriptionService->validateCoupon(
            $request->input('code'),
            (float) $request->input('amount')
        );
    }

    /**
     * Process subscription checkout.
     */
    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_uuid' => 'required|exists:plans,uuid',
            'billing_cycle' => 'required|in:monthly,annual',
            'payment_method' => 'required|string',
            'transaction_reference' => 'nullable|string|max:255',
            'coupon_code' => 'nullable|string',
            'proof_of_payment' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        return $this->doctorSubscriptionService->checkout($request->user(), $validated);
    }
}
