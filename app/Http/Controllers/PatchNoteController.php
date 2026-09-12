<?php

namespace App\Http\Controllers;

use App\Models\PatchNote;
use App\Service\PatchNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatchNoteController extends Controller
{
    public function __construct(private PatchNoteService $patchNoteService) {}

    /**
     * Get published patch notes.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = (int) $request->input('limit', 20);

        return $this->patchNoteService->listPublished($limit);
    }

    /**
     * Get the latest published patch note.
     */
    public function latest(): JsonResponse
    {
        return $this->patchNoteService->getLatestPublished();
    }

    /**
     * View a published patch note.
     */
    public function show(PatchNote $patchNote): JsonResponse
    {
        if (! $patchNote->is_published) {
            return response()->json([
                'status' => 'error',
                'message' => 'Patch note not found or unpublished',
            ], 404);
        }

        return $this->patchNoteService->getPatchNote($patchNote);
    }
}
