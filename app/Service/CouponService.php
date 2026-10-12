<?php

namespace App\Service;

use App\Http\Resources\CouponResource;
use App\Models\Coupon;
use App\Repository\CouponRepository;
use Illuminate\Http\JsonResponse;

class CouponService
{
    public function __construct(private CouponRepository $couponRepository) {}

    public function listCoupons(?string $status = null): JsonResponse
    {
        $coupons = $this->couponRepository->getAll($status);

        return response()->json([
            'status' => 'success',
            'data' => CouponResource::collection($coupons),
        ]);
    }

    public function getCoupon(Coupon $coupon): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => new CouponResource($coupon),
        ]);
    }

    public function createCoupon(array $payload): JsonResponse
    {
        $coupon = $this->couponRepository->create($payload);

        return response()->json([
            'status' => 'success',
            'message' => 'Coupon created successfully.',
            'data' => new CouponResource($coupon),
        ], 201);
    }

    public function updateCoupon(Coupon $coupon, array $payload): JsonResponse
    {
        $hasRedemptions = ($coupon->times_redeemed > 0) || $coupon->subscriptionDiscounts()->exists();

        // Guard: Prevent mutation of financial core values if coupon was already redeemed
        if ($hasRedemptions) {
            unset($payload['code'], $payload['discount_type'], $payload['value']);
        }

        $updated = $this->couponRepository->update($coupon, $payload);

        return response()->json([
            'status' => 'success',
            'message' => 'Coupon updated successfully.',
            'data' => new CouponResource($updated),
        ]);
    }

    public function toggleActive(Coupon $coupon): JsonResponse
    {
        $toggled = $this->couponRepository->toggleActive($coupon);

        return response()->json([
            'status' => 'success',
            'message' => 'Coupon status toggled successfully.',
            'data' => new CouponResource($toggled),
        ]);
    }

    public function deleteCoupon(Coupon $coupon, bool $force = false): JsonResponse
    {
        $hasRedemptions = ($coupon->times_redeemed > 0) || $coupon->subscriptionDiscounts()->exists();

        if ($force) {
            if ($hasRedemptions) {
                // Safeguard: Fallback to soft-delete / archive
                $this->couponRepository->delete($coupon, false);

                return response()->json([
                    'status' => 'warning',
                    'message' => 'Coupon has active redemption history and cannot be permanently removed. It has been archived instead to preserve financial audit integrity.',
                    'archived' => true,
                ], 200);
            }

            $this->couponRepository->delete($coupon, true);

            return response()->json([
                'status' => 'success',
                'message' => 'Coupon permanently deleted.',
                'permanent' => true,
            ]);
        }

        $this->couponRepository->delete($coupon, false);

        return response()->json([
            'status' => 'success',
            'message' => 'Coupon archived successfully.',
            'archived' => true,
        ]);
    }

    public function restoreCoupon(Coupon $coupon): JsonResponse
    {
        $this->couponRepository->restore($coupon);

        return response()->json([
            'status' => 'success',
            'message' => 'Coupon restored successfully.',
            'data' => new CouponResource($coupon),
        ]);
    }
}
