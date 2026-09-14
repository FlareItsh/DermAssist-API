<?php

namespace App\Service;

use App\Http\Resources\PatchNoteResource;
use App\Models\PatchNote;
use App\Repository\PatchNoteRepository;
use Illuminate\Http\JsonResponse;

class PatchNoteService
{
    public function __construct(private PatchNoteRepository $patchNoteRepository) {}

    /**
     * Get all patch notes (Admin view).
     */
    public function listAll(): JsonResponse
    {
        $notes = $this->patchNoteRepository->getAll();

        return response()->json([
            'status' => 'success',
            'data' => PatchNoteResource::collection($notes),
        ]);
    }

    /**
     * Get published patch notes for users / notification feed.
     */
    public function listPublished(int $limit = 20): JsonResponse
    {
        $notes = $this->patchNoteRepository->getPublished($limit);

        return response()->json([
            'status' => 'success',
            'data' => PatchNoteResource::collection($notes),
        ]);
    }

    /**
     * Get the latest published patch note.
     */
    public function getLatestPublished(): JsonResponse
    {
        $note = $this->patchNoteRepository->getLatestPublished();

        return response()->json([
            'status' => 'success',
            'data' => $note ? new PatchNoteResource($note) : null,
        ]);
    }

    /**
     * Create a new patch note.
     */
    public function create(array $payload, ?int $userId = null): JsonResponse
    {
        if ($userId) {
            $payload['created_by'] = $userId;
        }

        if (! empty($payload['is_published'])) {
            $payload['published_at'] = $payload['published_at'] ?? now();
        }

        $patchNote = $this->patchNoteRepository->create($payload);

        return response()->json([
            'status' => 'success',
            'message' => 'Patch note created successfully',
            'data' => new PatchNoteResource($patchNote->load('creator')),
        ], 201);
    }

    /**
     * Get a single patch note.
     */
    public function getPatchNote(PatchNote $patchNote): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => new PatchNoteResource($patchNote->load('creator')),
        ]);
    }

    /**
     * Update an existing patch note.
     */
    public function update(PatchNote $patchNote, array $payload): JsonResponse
    {
        if (isset($payload['is_published']) && $payload['is_published'] && ! $patchNote->published_at) {
            $payload['published_at'] = now();
        }

        $updated = $this->patchNoteRepository->update($patchNote, $payload);

        return response()->json([
            'status' => 'success',
            'message' => 'Patch note updated successfully',
            'data' => new PatchNoteResource($updated),
        ]);
    }

    /**
     * Toggle the published status.
     */
    public function togglePublish(PatchNote $patchNote): JsonResponse
    {
        $isPublishing = ! $patchNote->is_published;
        $data = [
            'is_published' => $isPublishing,
            'published_at' => $isPublishing ? ($patchNote->published_at ?? now()) : $patchNote->published_at,
        ];

        $updated = $this->patchNoteRepository->update($patchNote, $data);

        return response()->json([
            'status' => 'success',
            'message' => $isPublishing ? 'Patch note published successfully' : 'Patch note unpublished',
            'data' => new PatchNoteResource($updated),
        ]);
    }

    /**
     * Delete a patch note.
     */
    public function delete(PatchNote $patchNote): JsonResponse
    {
        $this->patchNoteRepository->delete($patchNote);

        return response()->json([
            'status' => 'success',
            'message' => 'Patch note deleted successfully',
        ]);
    }
}
