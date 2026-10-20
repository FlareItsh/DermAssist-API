<?php

namespace App\Http\Controllers;

use App\Models\CandidateDisease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CandidateDiseaseController extends Controller
{
    /**
     * Display a listing of candidate diseases.
     */
    public function index(): JsonResponse
    {
        $diseases = CandidateDisease::query()
            ->orderByDesc('is_core')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $diseases,
        ]);
    }

    /**
     * Store a newly created custom candidate disease.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'badge' => 'nullable|string|max:50',
            'icon' => 'nullable|string|max:50',
        ]);

        $baseSlug = Str::slug($validated['name'], '_');
        $slug = $baseSlug;
        $counter = 1;
        while (CandidateDisease::where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}_{$counter}";
            $counter++;
        }

        $disease = CandidateDisease::create([
            'name' => trim($validated['name']),
            'slug' => $slug,
            'category' => trim($validated['category'] ?? 'Research'),
            'description' => $validated['description'] ?? null,
            'badge' => $validated['badge'] ?? 'Custom',
            'icon' => $validated['icon'] ?? 'lucide:flask-conical',
            'is_default' => false,
            'is_core' => false,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => "Candidate disease '{$disease->name}' created successfully.",
            'data' => $disease,
        ], 201);
    }

    /**
     * Remove the specified candidate disease.
     */
    public function destroy(string $uuid): JsonResponse
    {
        $disease = CandidateDisease::where('uuid', $uuid)->firstOrFail();

        if ($disease->is_core || $disease->is_default) {
            return response()->json([
                'message' => 'Core model and default system diseases cannot be deleted.',
            ], 422);
        }

        $disease->delete();

        return response()->json([
            'message' => 'Candidate disease deleted successfully.',
        ]);
    }
}
