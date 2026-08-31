<?php

namespace App\Service;

use App\Http\Resources\PaymentInvoiceResource;
use App\Models\PaymentInvoice;
use App\Repository\PaymentInvoiceRepository;
use Illuminate\Http\JsonResponse;

class PaymentInvoiceService
{
    public function __construct(private PaymentInvoiceRepository $paymentInvoiceRepository) {}

    public function listPayments(?string $status): JsonResponse
    {
        $paginator = $this->paymentInvoiceRepository->paginate($status);

        return response()->json([
            'status' => 'success',
            'data' => PaymentInvoiceResource::collection($paginator)->response()->getData(true),
        ]);
    }

    public function approvePayment(PaymentInvoice $invoice, ?int $approvedByUserId, ?string $transactionReference = null): JsonResponse
    {
        if ($invoice->payment_status === 'paid') {
            return response()->json([
                'status' => 'error',
                'message' => 'Invoice is already marked as paid.',
            ], 422);
        }

        $approvedInvoice = $this->paymentInvoiceRepository->approve($invoice, $approvedByUserId, $transactionReference);

        $subscription = $approvedInvoice->subscription;
        if ($subscription) {
            $durationMonths = $subscription->billing_cycle === 'annual' ? 12 : 1;

            $startsAt = now();
            $endsAt = (clone $startsAt)->addMonths($durationMonths);

            $subscription->update([
                'status' => 'active',
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Payment approved and doctor subscription activated successfully.',
            'data' => new PaymentInvoiceResource($approvedInvoice->fresh(['subscription', 'user', 'approvedBy'])),
        ]);
    }

    public function rejectPayment(PaymentInvoice $invoice, ?int $approvedByUserId, string $reason): JsonResponse
    {
        $rejectedInvoice = $this->paymentInvoiceRepository->reject($invoice, $approvedByUserId);

        if ($rejectedInvoice->subscription) {
            $rejectedInvoice->subscription->update([
                'status' => 'past_due',
                'cancellation_reason' => $reason,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Payment rejected successfully.',
            'data' => new PaymentInvoiceResource($rejectedInvoice),
        ]);
    }
}
