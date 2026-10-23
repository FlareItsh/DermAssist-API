<?php

namespace Database\Seeders;

use App\Models\Coupon;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class CouponSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Coupon::updateOrCreate(
            ['code' => 'DERM20'],
            [
                'discount_type' => 'percentage',
                'value' => 20.00,
                'duration' => 'once',
                'duration_in_months' => null,
                'valid_from' => Carbon::now()->startOfYear(),
                'valid_until' => Carbon::parse('2026-12-31 23:59:59'),
                'max_redemptions' => 100,
                'times_redeemed' => 0,
                'max_redemptions_per_user' => 1,
                'min_spend' => 0.00,
                'applicable_plans' => null,
                'customer_segment' => 'all',
                'is_active' => true,
            ]
        );
    }
}
