<?php

namespace App\Repository;

use App\Models\Coupon;
use Illuminate\Database\Eloquent\Collection;

class CouponRepository
{
    public function getAll(): Collection
    {
        return Coupon::orderBy('created_at', 'desc')->get();
    }

    public function create(array $payload): Coupon
    {
        return Coupon::create($payload);
    }

    public function toggleActive(Coupon $coupon): Coupon
    {
        $coupon->is_active = ! $coupon->is_active;
        $coupon->save();

        return $coupon;
    }

    public function delete(Coupon $coupon): bool
    {
        return $coupon->delete();
    }
}
