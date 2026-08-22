<?php

namespace App\Service;

use App\Http\Resources\CouponResource;
use App\Models\Coupon;
use App\Repository\CouponRepository;
use Illuminate\Http\JsonResponse;

class CouponService
{
    public function __construct(private CouponRepository $couponRepository) {}

    public function listCoupons(): JsonResponse
    {
        $coupons = $this->couponRepository->getAll();

        return response()->json([
            'status' => 'success',
            'data' => CouponResource::collection($coupons),
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

    public function toggleActive(Coupon $coupon): JsonResponse
    {
        $toggled = $this->couponRepository->toggleActive($coupon);

        return response()->json([
            'status' => 'success',
            'message' => 'Coupon status toggled successfully.',
            'data' => new CouponResource($toggled),
        ]);
    }

    public function deleteCoupon(Coupon $coupon): JsonResponse
    {
        $this->couponRepository->delete($coupon);

        return response()->json([
            'status' => 'success',
            'message' => 'Coupon deleted successfully.',
        ]);
    }
}
