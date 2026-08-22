<?php

namespace App\Service;

use App\Http\Resources\SubscriptionResource;
use App\Repository\PaymentInvoiceRepository;
use App\Repository\SubscriptionRepository;
use Illuminate\Http\JsonResponse;

class SubscriptionAdminService
{
    public function __construct(
        private SubscriptionRepository $subscriptionRepository,
        private PaymentInvoiceRepository $paymentInvoiceRepository
    ) {}

    public function getDashboardMetrics(): JsonResponse
    {
        $metrics = $this->subscriptionRepository->getDashboardMetrics();
        $metrics['pending_payments_count'] = $this->paymentInvoiceRepository->countPending();

        $recentSubscribers = $this->subscriptionRepository->getRecentSubscribers(10);

        return response()->json([
            'status' => 'success',
            'data' => [
                'metrics' => $metrics,
                'recent_subscribers' => SubscriptionResource::collection($recentSubscribers),
            ],
        ]);
    }

    public function listSubscriptions(?string $status, ?string $tier): JsonResponse
    {
        $paginator = $this->subscriptionRepository->paginate($status, $tier);

        return response()->json([
            'status' => 'success',
            'data' => SubscriptionResource::collection($paginator)->response()->getData(true),
        ]);
    }
}
