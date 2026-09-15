<?php

namespace Database\Factories;

use App\Models\PatchNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatchNote>
 */
class PatchNoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'version' => 'v'.$this->faker->semver(),
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'changes' => [
                'Added '.$this->faker->sentence(3),
                'Fixed '.$this->faker->sentence(3),
            ],
            'is_published' => false,
            'published_at' => null,
            'created_by' => User::factory(),
        ];
    }

    /**
     * Indicate that the patch note is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => true,
            'published_at' => now(),
        ]);
    }
}
