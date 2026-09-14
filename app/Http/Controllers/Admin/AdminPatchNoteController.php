<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PatchNote;
use App\Service\PatchNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPatchNoteController extends Controller
{
    public function __construct(private PatchNoteService $patchNoteService) {}

    /**
     * Display a listing of all patch notes.
     */
    public function index(): JsonResponse
    {
        return $this->patchNoteService->listAll();
    }

    /**
     * Store a newly created patch note.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'version' => 'nullable|string|max:50',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'changes' => 'nullable|array',
            'changes.*' => 'string|max:500',
            'is_published' => 'nullable|boolean',
        ]);

        return $this->patchNoteService->create($validated, $request->user()?->id);
    }

    /**
     * Display the specified patch note.
     */
    public function show(PatchNote $patchNote): JsonResponse
    {
        return $this->patchNoteService->getPatchNote($patchNote);
    }

    /**
     * Update the specified patch note.
     */
    public function update(Request $request, PatchNote $patchNote): JsonResponse
    {
        $validated = $request->validate([
            'version' => 'nullable|string|max:50',
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'changes' => 'nullable|array',
            'changes.*' => 'string|max:500',
            'is_published' => 'nullable|boolean',
        ]);

        return $this->patchNoteService->update($patchNote, $validated);
    }

    /**
     * Toggle the published status of a patch note.
     */
    public function togglePublish(PatchNote $patchNote): JsonResponse
    {
        return $this->patchNoteService->togglePublish($patchNote);
    }

    /**
     * Remove the specified patch note.
     */
    public function destroy(PatchNote $patchNote): JsonResponse
    {
        return $this->patchNoteService->delete($patchNote);
    }
}
