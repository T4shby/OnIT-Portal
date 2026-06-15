<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\PortalLink;
use Illuminate\Database\Eloquent\Factories\Factory;

class PortalLinkFactory extends Factory
{
    protected $model = PortalLink::class;

    public function definition(): array
    {
        return [
            'client_id' => null,
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'url' => fake()->url(),
            'icon' => 'link',
            'display_order' => fake()->numberBetween(1, 10),
            'is_active' => true,
            'open_in_new_tab' => true,
        ];
    }

    public function forClient(Client $client): static
    {
        return $this->state(['client_id' => $client->id]);
    }
}
