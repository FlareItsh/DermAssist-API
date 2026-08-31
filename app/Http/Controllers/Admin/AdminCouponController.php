<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Service\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminCouponController extends Controller
{
    public function __construct(private CouponService $couponService) {}

    /**
     * Display a listing of coupons.
     */
    public function index(): JsonResponse
    {
        return $this->couponService->listCoupons();
    }

    /**
     * Store a newly created coupon code.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:coupons,code',
            'discount_type' => 'required|string|in:percentage,fixed_amount',
            'value' => 'required|numeric|min:0.01',
            'duration' => 'nullable|string|in:once,repeating,forever',
            'duration_in_months' => 'nullable|integer|min:1',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
            'max_redemptions' => 'nullable|integer|min:1',
            'is_active' => 'nullable|boolean',
        ]);

        $validated['code'] = Str::upper($validated['code']);

        return $this->couponService->createCoupon($validated);
    }

    /**
     * Toggle active state of a coupon.
     */
    public function toggleActive(Coupon $coupon): JsonResponse
    {
        return $this->couponService->toggleActive($coupon);
    }

    /**
     * Remove the specified coupon.
     */
    public function destroy(Coupon $coupon): JsonResponse
    {
        return $this->couponService->deleteCoupon($coupon);
    }
}
