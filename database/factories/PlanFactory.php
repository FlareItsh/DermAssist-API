<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->sentence(2);

        return [
            'name' => $name,
            'slug' => str($name)->slug(),
            'tier_type' => 'professional',
            'price_monthly' => 1499.00,
            'price_annual' => 14990.00,
            'max_doctors' => 1,
            'max_clinics' => 1,
            'features' => ['Feature 1', 'Feature 2', 'Feature 3'],
            'trial_period_days' => 7,
            'grace_period_days' => 5,
            'sort_order' => 1,
            'is_active' => true,
        ];
    }
}
