<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional()->firstName(),
            'last_name' => fake()->lastName(),
            'age' => fake()->numberBetween(18, 65),
            'gender' => fake()->randomElement(['Male', 'Female', 'Other']),
            'street' => fake()->streetAddress(),
            'barangay' => fake()->randomElement(['Buhangin', 'Matina', 'Agdao', 'Talomo', 'Toril', 'Ecoland', 'Obrero']),
            'city' => 'Davao City',
            'province' => 'Davao del Sur',
            'country' => 'Philippines',
            'latitude' => fake()->latitude(7.05, 7.15),
            'longitude' => fake()->longitude(125.55, 125.65),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
