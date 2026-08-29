<?php

namespace App\Http\Controllers;

use App\Service\PaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    public function __construct(private PaymentGatewayService $paymentGatewayService) {}

    /**
     * Handle incoming PayMongo webhook events.
     */
    public function handlePayMongo(Request $request): JsonResponse
    {
        return $this->paymentGatewayService->processWebhook('paymongo', $request->all());
    }

    /**
     * Handle incoming Stripe webhook events.
     */
    public function handleStripe(Request $request): JsonResponse
    {
        return $this->paymentGatewayService->processWebhook('stripe', $request->all());
    }
}
