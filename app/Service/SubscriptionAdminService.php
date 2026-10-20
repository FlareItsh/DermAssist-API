<?php

namespace App\Service;

use App\Http\Resources\SubscriptionResource;
use App\Models\Diagnosis;
use App\Models\User;
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

    public function getAdminDashboardStats(): JsonResponse
    {
        $subscriptionMetrics = $this->subscriptionRepository->getProfitsMetrics();

        $thirtyDaysAgo = now()->subDays(30);

        $totalScans = Diagnosis::count();
        $doctorScans = Diagnosis::whereNotNull('doctor_uuid')->count();
        $patientScans = Diagnosis::whereNull('doctor_uuid')->count();
        $scans30d = Diagnosis::where('created_at', '>=', $thirtyDaysAgo)->count();

        $totalDoctors = User::whereHas('role', fn ($q) => $q->where('name', 'doctor'))->count();
        $totalPatients = User::whereHas('role', fn ($q) => $q->where('name', 'patient'))->count();
        $newDoctors30d = User::whereHas('role', fn ($q) => $q->where('name', 'doctor'))->where('created_at', '>=', $thirtyDaysAgo)->count();
        $newPatients30d = User::whereHas('role', fn ($q) => $q->where('name', 'patient'))->where('created_at', '>=', $thirtyDaysAgo)->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'subscription_profits' => $subscriptionMetrics,
                'scan_usage' => [
                    'total_scans' => $totalScans,
                    'doctor_scans_count' => $doctorScans,
                    'patient_scans_count' => $patientScans,
                    'scans_30d' => $scans30d,
                ],
                'user_counts' => [
                    'total_doctors' => $totalDoctors,
                    'total_patients' => $totalPatients,
                    'new_doctors_30d' => $newDoctors30d,
                    'new_patients_30d' => $newPatients30d,
                ],
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
