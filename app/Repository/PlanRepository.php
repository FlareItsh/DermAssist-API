<?php

namespace App\Repository;

use App\Models\Feature;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Collection;

class PlanRepository
{
    public function getAll(): Collection
    {
        return Plan::with('planFeatures')->orderBy('sort_order', 'asc')->get();
    }

    public function findById(int $id): ?Plan
    {
        return Plan::with('planFeatures')->find($id);
    }

    public function create(array $payload): Plan
    {
        $features = $payload['features'] ?? null;
        $plan = Plan::create($payload);

        $this->syncFeatures($plan, $features);

        return $plan->load('planFeatures');
    }

    public function update(Plan $plan, array $payload): Plan
    {
        $features = $payload['features'] ?? null;
        $plan->update($payload);

        if ($features !== null) {
            $this->syncFeatures($plan, $features);
        }

        return $plan->load('planFeatures');
    }

    private function syncFeatures(Plan $plan, ?array $features): void
    {
        if ($features === null) {
            return;
        }

        // Support array of { feature_id/uuid/code, is_included } OR associative map { code: true/false }
        $allFeatures = Feature::all();
        $syncData = [];

        foreach ($allFeatures as $feature) {
            $isIncluded = false;

            if (isset($features[$feature->code])) {
                $isIncluded = (bool) $features[$feature->code];
            } elseif (isset($features[$feature->id])) {
                $isIncluded = (bool) $features[$feature->id];
            } elseif (isset($features[$feature->uuid])) {
                $isIncluded = (bool) $features[$feature->uuid];
            }

            $syncData[$feature->id] = ['is_included' => $isIncluded];
        }

        $plan->planFeatures()->sync($syncData);
    }

    public function toggleActive(Plan $plan): Plan
    {
        $plan->is_active = ! $plan->is_active;
        $plan->save();

        return $plan;
    }

    public function delete(Plan $plan): bool
    {
        return $plan->delete();
    }

    public function hasSubscriptions(Plan $plan): bool
    {
        return $plan->subscriptions()->count() > 0;
    }
}
