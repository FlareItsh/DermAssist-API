<?php

namespace App\Repository;

use App\Models\Feature;
use Illuminate\Database\Eloquent\Collection;

class FeatureRepository
{
    /**
     * Get all features ordered by sort order.
     *
     * @return Collection<int, Feature>
     */
    public function getAll(): Collection
    {
        return Feature::orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get();
    }

    /**
     * Get all active features.
     *
     * @return Collection<int, Feature>
     */
    public function getActive(): Collection
    {
        return Feature::where('is_active', true)
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get();
    }

    /**
     * Create a new feature.
     */
    public function create(array $data): Feature
    {
        return Feature::create($data);
    }

    /**
     * Find feature by ID or UUID.
     */
    public function findByUuid(string $uuid): ?Feature
    {
        return Feature::where('uuid', $uuid)->first();
    }

    /**
     * Update feature.
     */
    public function update(Feature $feature, array $data): Feature
    {
        $feature->update($data);

        return $feature->fresh();
    }

    /**
     * Delete feature.
     */
    public function delete(Feature $feature): bool
    {
        return $feature->delete();
    }
}
