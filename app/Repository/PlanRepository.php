<?php

namespace App\Repository;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Collection;

class PlanRepository
{
    public function getAll(): Collection
    {
        return Plan::orderBy('sort_order', 'asc')->get();
    }

    public function findById(int $id): ?Plan
    {
        return Plan::find($id);
    }

    public function create(array $payload): Plan
    {
        return Plan::create($payload);
    }

    public function update(Plan $plan, array $payload): Plan
    {
        $plan->update($payload);

        return $plan;
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
