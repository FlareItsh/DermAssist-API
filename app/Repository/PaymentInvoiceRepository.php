<?php

namespace App\Repository;

use App\Models\PaymentInvoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PaymentInvoiceRepository
{
    public function paginate(?string $status = null, int $perPage = 20): LengthAwarePaginator
    {
        return PaymentInvoice::with(['user', 'subscription.plan', 'approvedBy'])
            ->when($status, fn ($q) => $q->where('payment_status', $status))
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function countPending(): int
    {
        return PaymentInvoice::where('payment_status', 'pending')->count();
    }

    public function approve(PaymentInvoice $invoice, ?int $approvedByUserId, ?string $transactionReference = null): PaymentInvoice
    {
        $invoice->update([
            'payment_status' => 'paid',
            'paid_at' => now(),
            'approved_by_user_id' => $approvedByUserId,
            'transaction_reference' => $transactionReference ?? $invoice->transaction_reference,
        ]);

        return $invoice;
    }

    public function reject(PaymentInvoice $invoice, ?int $approvedByUserId): PaymentInvoice
    {
        $invoice->update([
            'payment_status' => 'rejected',
            'approved_by_user_id' => $approvedByUserId,
        ]);

        return $invoice;
    }
}
