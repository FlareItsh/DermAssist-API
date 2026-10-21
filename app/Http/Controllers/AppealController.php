<?php

namespace App\Http\Controllers;

use App\Service\AppealService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppealController extends Controller
{
    public function __construct(private AppealService $appealService) {}

    /**
     * Display a listing of the appeals with relations.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'all');
        $diagnosisUuid = $request->query('diagnosis_uuid');
        $userUuid = $request->query('user_uuid');

        if ($request->user() && $request->user()->role?->slug === 'doctor' && ! $userUuid) {
            $userUuid = $request->user()->uuid;
        }

        $appeals = $this->appealService->listAppeals(
            $status === 'all' ? null : $status,
            $diagnosisUuid,
            $userUuid
        );

        return response()->json([
            'data' => $appeals,
        ]);
    }

    /**
     * Store a newly created appeal in storage.
     * Immediately applies the doctor's verified diagnosis to the clinical record.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_uuid' => 'required|exists:users,uuid',
            'diagnosis_uuid' => 'nullable|string|exists:diagnoses,uuid',
            'diagnosis_label' => 'required|string',
            'suggested_label' => 'required|string',
            'description' => 'nullable|string',
        ]);

        $appeal = $this->appealService->submitAppeal($validated);

        return response()->json([
            'message' => 'Appeal submitted and clinical diagnosis updated successfully.',
            'data' => $appeal,
        ], 201);
    }

    /**
     * Resolve an appeal: confirms AI misclassification and moves image into retraining dataset.
     */
    public function resolve(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'resolution_notes' => 'nullable|string',
            'update_dataset' => 'nullable|boolean',
        ]);

        $appeal = $this->appealService->resolveAppeal($uuid, $validated);

        return response()->json([
            'message' => 'Appeal resolved and verified scan ingested for AI retraining.',
            'data' => $appeal,
        ]);
    }

    /**
     * Reject an appeal: marks as rejected with moderation notes without retraining ingestion.
     */
    public function reject(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'resolution_notes' => 'nullable|string',
        ]);

        $appeal = $this->appealService->rejectAppeal($uuid, $validated);

        return response()->json([
            'message' => 'Appeal rejected.',
            'data' => $appeal,
        ]);
    }

    /**
     * Restore an appeal back to pending.
     */
    public function restore(string $uuid): JsonResponse
    {
        $appeal = $this->appealService->restoreAppeal($uuid);

        return response()->json([
            'message' => 'Appeal restored to pending status.',
            'data' => $appeal,
        ]);
    }
}
