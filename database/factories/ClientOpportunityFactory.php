<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientOpportunity;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientOpportunityFactory extends Factory
{
    protected $model = ClientOpportunity::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'category' => fake()->randomElement(['backup', 'security', 'device_refresh', 'new_service']),
            'status' => 'open',
            'display_order' => fake()->numberBetween(1, 10),
            'is_active' => true,
        ];
    }
}
