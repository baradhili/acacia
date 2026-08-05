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
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'salary' => fake()->randomElement([null, 60000, 75000, 85000, 100000, 120000]),
            'charge_out_rate' => fake()->randomElement([null, 100, 150, 175, 200, 250]),
            'position' => fake()->randomElement(['Developer', 'Designer', 'Manager', 'Consultant', 'Analyst']),
            'phone' => fake()->optional()->phoneNumber(),
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

    /**
     * Indicate that the user has a charge out rate.
     */
    public function withChargeOutRate(float $rate = 150): static
    {
        return $this->state(fn (array $attributes) => [
            'charge_out_rate' => $rate,
        ]);
    }

    /**
     * Indicate that the user has a salary.
     */
    public function withSalary(float $salary = 80000): static
    {
        return $this->state(fn (array $attributes) => [
            'salary' => $salary,
        ]);
    }
}
