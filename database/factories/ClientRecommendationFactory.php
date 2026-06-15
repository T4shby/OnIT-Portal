<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientRecommendation;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientRecommendationFactory extends Factory
{
    protected $model = ClientRecommendation::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'category' => fake()->randomElement(['security', 'service', 'technology']),
            'priority' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
            'display_order' => fake()->numberBetween(1, 10),
            'is_active' => true,
        ];
    }
}
