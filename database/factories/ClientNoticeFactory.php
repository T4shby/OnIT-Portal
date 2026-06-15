<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientNotice;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientNoticeFactory extends Factory
{
    protected $model = ClientNotice::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'published_at' => now(),
            'expires_at' => null,
            'is_active' => true,
        ];
    }
}
