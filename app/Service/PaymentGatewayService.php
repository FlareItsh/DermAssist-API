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
        $secretKey = config('services.paymongo.secret_key') ?: env('PAYMONGO_SECRET_KEY');

        if (! $secretKey) {
            throw new \Exception('PayMongo API Secret Key is missing. Please add PAYMONGO_SECRET_KEY to your api/.env file.');
        }

        $amountInCents = (int) round($invoice->final_amount * 100);
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
        $successUrl = "{$frontendUrl}/doctor/subscription?status=success&invoice={$invoice->uuid}";
        $cancelUrl = "{$frontendUrl}/doctor/subscription?status=cancelled";

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
                        'payment_method_types' => ['gcash', 'paymaya', 'card', 'dob', 'dob_ubp'],
                        'success_url' => $successUrl,
                        'cancel_url' => $cancelUrl,
                        'reference_number' => $invoice->uuid,
                    ],
                ],
            ]);

        if ($response->failed()) {
            $errorMsg = $response->json('errors.0.detail') ?? $response->json('message') ?? 'Failed to connect to PayMongo gateway.';
            Log::error('PayMongo Session Error: '.$errorMsg);
            throw new \Exception('PayMongo Gateway Error: '.$errorMsg);
        }

        $checkoutSessionId = $response->json('data.id');
        $checkoutUrl = $response->json('data.attributes.checkout_url');

        // Store checkout session ID in invoice transaction_reference
        $invoice->update([
            'transaction_reference' => $checkoutSessionId,
        ]);

        return [
            'checkout_url' => $checkoutUrl,
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
        }

        if (! $invoiceUuid) {
            return response()->json(['status' => 'error', 'message' => 'Invoice reference not found in webhook payload.'], 400);
        }

        $invoice = PaymentInvoice::where('uuid', $invoiceUuid)->first();
        if (! $invoice) {
            return response()->json(['status' => 'error', 'message' => 'Matching invoice record not found.'], 404);
        }

        // Auto approve payment and activate subscription
        $this->paymentInvoiceService->approvePayment($invoice, null, 'PAYMONGO-'.now()->timestamp);

        return response()->json([
            'status' => 'success',
            'message' => 'Subscription activated automatically via PayMongo webhook.',
        ]);
    }

    /**
     * Verify checkout session status with PayMongo before activating subscription on return.
     */
    public function confirmReturnPayment(string $invoiceUuid, string $provider): JsonResponse
    {
        $invoice = PaymentInvoice::where('uuid', $invoiceUuid)->first();
        if (! $invoice) {
            return response()->json(['status' => 'error', 'message' => 'Invoice not found.'], 404);
        }

        if ($invoice->payment_status === 'paid' || $invoice->payment_status === 'approved') {
            return response()->json([
                'status' => 'success',
                'message' => 'Subscription is active!',
                'data' => $invoice->fresh(['subscription.plan']),
            ]);
        }

        $secretKey = config('services.paymongo.secret_key') ?: env('PAYMONGO_SECRET_KEY');
        $checkoutSessionId = $invoice->transaction_reference;

        if ($secretKey && $checkoutSessionId) {
            try {
                $response = Http::withBasicAuth($secretKey, '')
                    ->get("https://api.paymongo.com/v1/checkout_sessions/{$checkoutSessionId}");

                if ($response->successful()) {
                    $payments = $response->json('data.attributes.payments') ?? [];
                    $hasPaidPayment = collect($payments)->contains(function ($payment) {
                        return ($payment['attributes']['status'] ?? null) === 'paid';
                    });

                    if (! $hasPaidPayment) {
                        return response()->json([
                            'status' => 'pending',
                            'message' => 'Payment has not been completed yet.',
                        ], 400);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Error verifying PayMongo checkout session: '.$e->getMessage());
            }
        }

        $this->paymentInvoiceService->approvePayment($invoice, null, 'PAYMONGO-INSTANT-'.now()->timestamp);

        return response()->json([
            'status' => 'success',
            'message' => 'Payment confirmed and subscription activated successfully!',
            'data' => $invoice->fresh(['subscription.plan']),
        ]);
    }
}
