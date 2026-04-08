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
            'price' => $this->faker->randomFloat(2, 0, 100),
            'features' => ['Feature 1', 'Feature 2', 'Feature 3'],
            'interval' => $this->faker->randomElement(['month', 'year']),
            'interval_count' => 1,
            'trial_period_days' => 7,
            'grace_period_days' => 5,
            'sort_order' => 1,
            'is_active' => true,
        ];
    }
}
