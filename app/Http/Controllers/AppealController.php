<?php

namespace App\Http\Controllers;

use App\Service\AppealService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppealController extends Controller
{
    private AppealService $appealService;

    public function __construct(AppealService $appealService)
    {
        $this->appealService = $appealService;
    }

    /**
     * Display a listing of the appeals.
     */
    public function index(): JsonResponse
    {
        $appeals = $this->appealService->listPendingAppeals();

        return response()->json([
            'data' => $appeals,
        ]);
    }

    /**
     * Store a newly created appeal in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_uuid' => 'required|exists:users,uuid',
            'diagnosis_label' => 'required|string',
            'suggested_label' => 'required|string',
            'description' => 'nullable|string',
        ]);

        $appeal = $this->appealService->createAppeal($validated);

        return response()->json([
            'message' => 'Appeal submitted successfully.',
            'data' => $appeal,
        ], 210);
    }
}
