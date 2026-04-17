<?php

namespace App\Http\Controllers;

use App\Models\Appeal;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppealController extends Controller
{
    /**
     * Display a listing of the appeals.
     */
    public function index(): JsonResponse
    {
        $appeals = Appeal::with('user')->where('status', 'pending')->latest()->get();

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

        $user = User::where('uuid', $validated['user_uuid'])->firstOrFail();

        $appeal = Appeal::create([
            'user_id' => $user->id,
            'diagnosis_label' => $validated['diagnosis_label'],
            'suggested_label' => $validated['suggested_label'],
            'description' => $validated['description'],
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Appeal submitted successfully.',
            'data' => $appeal,
        ], 210);
    }
}
