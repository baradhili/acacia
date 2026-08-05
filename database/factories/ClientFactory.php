<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'email' => fake()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->state(),
            'postcode' => fake()->postcode(),
            'country' => 'Australia',
            'abn' => fake()->numerify('## ### ### ###'),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
