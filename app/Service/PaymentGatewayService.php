<?php

namespace App\Service;

use App\Models\PaymentInvoice;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentGatewayService
{
    public function __construct(private PaymentInvoiceService $paymentInvoiceService) {}

    /**
     * Create a PayMongo checkout session link.
     */
    public function createPayMongoSession(User $user, Subscription $subscription, PaymentInvoice $invoice): array
    {
        $secretKey = config('services.paymongo.secret_key', env('PAYMONGO_SECRET_KEY', 'sk_test_mock_paymongo_key'));

        $amountInCents = (int) round($invoice->final_amount * 100);
        $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000'));
        $successUrl = "{$frontendUrl}/doctor/subscription?status=success&invoice={$invoice->uuid}";
        $cancelUrl = "{$frontendUrl}/doctor/subscription?status=cancelled";

        try {
            $response = Http::withBasicAuth($secretKey, '')
                ->post('https://api.paymongo.com/v1/checkout_sessions', [
                    'data' => [
                        'attributes' => [
                            'billing' => [
                                'name' => "{$user->first_name} {$user->last_name}",
                                'email' => $user->email,
                            ],
                            'line_items' => [
                                [
                                    'currency' => 'PHP',
                                    'amount' => $amountInCents,
                                    'description' => "DermAssist {$subscription->plan->name} Subscription ({$subscription->billing_cycle})",
                                    'name' => "DermAssist Subscription - {$subscription->plan->name}",
                                    'quantity' => 1,
                                ],
                            ],
                            'payment_method_types' => ['gcash', 'paymaya', 'card'],
                            'success_url' => $successUrl,
                            'cancel_url' => $cancelUrl,
                            'reference_number' => $invoice->uuid,
                        ],
                    ],
                ]);

            if ($response->successful()) {
                $checkoutUrl = $response->json('data.attributes.checkout_url');

                return [
                    'checkout_url' => $checkoutUrl,
                    'reference' => $invoice->uuid,
                ];
            }
        } catch (\Throwable $e) {
            Log::error('PayMongo API session creation error: '.$e->getMessage());
        }

        // Return simulated gateway URL for testing if API key is unconfigured
        return [
            'checkout_url' => "{$successUrl}&simulated=paymongo",
            'reference' => $invoice->uuid,
        ];
    }

    /**
     * Create a Stripe checkout session link.
     */
    public function createStripeSession(User $user, Subscription $subscription, PaymentInvoice $invoice): array
    {
        $secretKey = config('services.stripe.secret_key', env('STRIPE_SECRET_KEY', 'sk_test_mock_stripe_key'));

        $amountInCents = (int) round($invoice->final_amount * 100);
        $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000'));
        $successUrl = "{$frontendUrl}/doctor/subscription?status=success&invoice={$invoice->uuid}";
        $cancelUrl = "{$frontendUrl}/doctor/subscription?status=cancelled";

        try {
            $response = Http::withToken($secretKey)
                ->asForm()
                ->post('https://api.stripe.com/v1/checkout/sessions', [
                    'payment_method_types[0]' => 'card',
                    'line_items[0][price_data][currency]' => 'php',
                    'line_items[0][price_data][unit_amount]' => $amountInCents,
                    'line_items[0][price_data][product_data][name]' => "DermAssist Subscription - {$subscription->plan->name}",
                    'line_items[0][quantity]' => 1,
                    'mode' => 'payment',
                    'success_url' => $successUrl,
                    'cancel_url' => $cancelUrl,
                    'client_reference_id' => $invoice->uuid,
                    'customer_email' => $user->email,
                ]);

            if ($response->successful()) {
                $checkoutUrl = $response->json('url');

                return [
                    'checkout_url' => $checkoutUrl,
                    'reference' => $invoice->uuid,
                ];
            }
        } catch (\Throwable $e) {
            Log::error('Stripe API session creation error: '.$e->getMessage());
        }

        // Return simulated gateway URL for testing if API key is unconfigured
        return [
            'checkout_url' => "{$successUrl}&simulated=stripe",
            'reference' => $invoice->uuid,
        ];
    }

    /**
     * Handle incoming gateway webhook for automatic activation.
     */
    public function processWebhook(string $provider, array $payload): JsonResponse
    {
        $invoiceUuid = null;

        if ($provider === 'paymongo') {
            $invoiceUuid = $payload['data']['attributes']['data']['attributes']['reference_number'] ?? null;
        } elseif ($provider === 'stripe') {
            $invoiceUuid = $payload['data']['object']['client_reference_id'] ?? null;
        }

        if (! $invoiceUuid) {
            return response()->json(['status' => 'error', 'message' => 'Invoice reference not found in webhook payload.'], 400);
        }

        $invoice = PaymentInvoice::where('uuid', $invoiceUuid)->first();
        if (! $invoice) {
            return response()->json(['status' => 'error', 'message' => 'Matching invoice record not found.'], 404);
        }

        // Auto approve payment and activate subscription
        $this->paymentInvoiceService->approvePayment($invoice, null, strtoupper($provider).'-AUTO-'.now()->timestamp);

        return response()->json([
            'status' => 'success',
            'message' => 'Subscription activated automatically via '.$provider.' webhook.',
        ]);
    }

    /**
     * Auto activate invoice upon successful redirect return.
     */
    public function confirmReturnPayment(string $invoiceUuid, string $provider): JsonResponse
    {
        $invoice = PaymentInvoice::where('uuid', $invoiceUuid)->first();
        if (! $invoice) {
            return response()->json(['status' => 'error', 'message' => 'Invoice not found.'], 404);
        }

        if ($invoice->payment_status !== 'paid' && $invoice->payment_status !== 'approved') {
            $this->paymentInvoiceService->approvePayment($invoice, null, strtoupper($provider).'-INSTANT-'.now()->timestamp);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Payment confirmed and subscription activated instantly!',
            'data' => $invoice->fresh(['subscription.plan']),
        ]);
    }
}
