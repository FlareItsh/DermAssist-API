<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Service\SubscriptionAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSubscriptionController extends Controller
{
    public function __construct(private SubscriptionAdminService $subscriptionAdminService) {}

    /**
     * Get dashboard metrics and subscriber account overview.
     */
    public function dashboard(): JsonResponse
    {
        return $this->subscriptionAdminService->getDashboardMetrics();
    }

    /**
     * Get list of all subscriptions with filtering options.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $tier = $request->query('tier_type');

        return $this->subscriptionAdminService->listSubscriptions($status, $tier);
    }
}
