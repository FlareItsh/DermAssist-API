<?php

namespace App\Repository;

use App\Models\Coupon;
use Illuminate\Database\Eloquent\Collection;

class CouponRepository
{
    public function getAll(?string $status = null): Collection
    {
        $query = Coupon::query();

        return match ($status) {
            'archived' => $query->onlyTrashed()->orderBy('deleted_at', 'desc')->get(),
            'active' => $query->where('is_active', true)->orderBy('created_at', 'desc')->get(),
            'inactive' => $query->where('is_active', false)->orderBy('created_at', 'desc')->get(),
            'all' => $query->withTrashed()->orderBy('created_at', 'desc')->get(),
            default => $query->orderBy('created_at', 'desc')->get(),
        };
    }

    public function findById(int $id, bool $withTrashed = true): ?Coupon
    {
        $query = Coupon::query();
        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->find($id);
    }

    public function create(array $payload): Coupon
    {
        return Coupon::create($payload);
    }

    public function update(Coupon $coupon, array $payload): Coupon
    {
        $coupon->update($payload);

        return $coupon;
    }

    public function toggleActive(Coupon $coupon): Coupon
    {
        $coupon->is_active = ! $coupon->is_active;
        $coupon->save();

        return $coupon;
    }

    public function delete(Coupon $coupon, bool $force = false): bool
    {
        if ($force) {
            return (bool) $coupon->forceDelete();
        }

        return (bool) $coupon->delete();
    }

    public function restore(Coupon $coupon): bool
    {
        return (bool) $coupon->restore();
    }
}
