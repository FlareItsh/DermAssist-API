<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentInvoice;
use App\Service\PaymentInvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPaymentController extends Controller
{
    public function __construct(private PaymentInvoiceService $paymentInvoiceService) {}

    /**
     * Display a listing of payment invoices / offline receipts.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');

        return $this->paymentInvoiceService->listPayments($status);
    }

    /**
     * Approve an offline payment receipt and activate subscription.
     */
    public function approve(Request $request, PaymentInvoice $invoice): JsonResponse
    {
        $approvedByUserId = $request->user()?->id;
        $transactionReference = $request->input('transaction_reference');

        return $this->paymentInvoiceService->approvePayment($invoice, $approvedByUserId, $transactionReference);
    }

    /**
     * Reject an offline payment receipt.
     */
    public function reject(Request $request, PaymentInvoice $invoice): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $approvedByUserId = $request->user()?->id;

        return $this->paymentInvoiceService->rejectPayment($invoice, $approvedByUserId, $validated['reason']);
    }
}
