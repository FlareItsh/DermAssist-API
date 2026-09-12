<?php

namespace App\Repository;

use App\Models\PatchNote;
use Illuminate\Database\Eloquent\Collection;

class PatchNoteRepository
{
    /**
     * Get all patch notes ordered by newest first.
     *
     * @return Collection<int, PatchNote>
     */
    public function getAll(): Collection
    {
        return PatchNote::with('creator')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get published patch notes for users.
     *
     * @return Collection<int, PatchNote>
     */
    public function getPublished(int $limit = 20): Collection
    {
        return PatchNote::with('creator')
            ->where('is_published', true)
            ->orderBy('published_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get latest published patch note.
     */
    public function getLatestPublished(): ?PatchNote
    {
        return PatchNote::where('is_published', true)
            ->orderBy('published_at', 'desc')
            ->first();
    }

    /**
     * Find patch note by UUID.
     */
    public function findByUuid(string $uuid): ?PatchNote
    {
        return PatchNote::with('creator')->where('uuid', $uuid)->first();
    }

    /**
     * Create a new patch note.
     */
    public function create(array $data): PatchNote
    {
        return PatchNote::create($data);
    }

    /**
     * Update an existing patch note.
     */
    public function update(PatchNote $patchNote, array $data): PatchNote
    {
        $patchNote->update($data);

        return $patchNote->fresh(['creator']);
    }

    /**
     * Delete a patch note.
     */
    public function delete(PatchNote $patchNote): bool
    {
        return (bool) $patchNote->delete();
    }
}
