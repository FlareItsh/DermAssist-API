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
    public function index(Request $request): JsonResponse
    {
        return $this->couponService->listCoupons($request->query('status'));
    }

    /**
     * Display the specified coupon.
     */
    public function show(int $id): JsonResponse
    {
        $coupon = Coupon::withTrashed()->findOrFail($id);

        return $this->couponService->getCoupon($coupon);
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
            'max_redemptions_per_user' => 'nullable|integer|min:1',
            'min_spend' => 'nullable|numeric|min:0',
            'applicable_plans' => 'nullable|array',
            'customer_segment' => 'nullable|string|in:all,new_doctors,renewals',
            'is_active' => 'nullable|boolean',
        ]);

        $validated['code'] = Str::upper($validated['code']);

        return $this->couponService->createCoupon($validated);
    }

    /**
     * Update the specified coupon.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $coupon = Coupon::withTrashed()->findOrFail($id);

        $validated = $request->validate([
            'code' => 'sometimes|required|string|max:50|unique:coupons,code,'.$coupon->id,
            'discount_type' => 'sometimes|required|string|in:percentage,fixed_amount',
            'value' => 'sometimes|required|numeric|min:0.01',
            'duration' => 'nullable|string|in:once,repeating,forever',
            'duration_in_months' => 'nullable|integer|min:1',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date',
            'max_redemptions' => 'nullable|integer|min:1',
            'max_redemptions_per_user' => 'nullable|integer|min:1',
            'min_spend' => 'nullable|numeric|min:0',
            'applicable_plans' => 'nullable|array',
            'customer_segment' => 'nullable|string|in:all,new_doctors,renewals',
            'is_active' => 'nullable|boolean',
        ]);

        if (! empty($validated['code'])) {
            $validated['code'] = Str::upper($validated['code']);
        }

        return $this->couponService->updateCoupon($coupon, $validated);
    }

    /**
     * Toggle active state of a coupon.
     */
    public function toggleActive(int $id): JsonResponse
    {
        $coupon = Coupon::withTrashed()->findOrFail($id);

        return $this->couponService->toggleActive($coupon);
    }

    /**
     * Remove the specified coupon (soft delete by default, force if specified).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $coupon = Coupon::withTrashed()->findOrFail($id);
        $force = $request->boolean('force', false);

        return $this->couponService->deleteCoupon($coupon, $force);
    }

    /**
     * Restore the specified archived coupon.
     */
    public function restore(int $id): JsonResponse
    {
        $coupon = Coupon::withTrashed()->findOrFail($id);

        return $this->couponService->restoreCoupon($coupon);
    }
}
