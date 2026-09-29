<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            // clients.slug is UNIQUE; faker company names collide often enough
            // (and tests that pass an explicit name still get this slug) to make
            // multi-client tests flaky, so suffix a per-test unique number.
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'is_active' => true,
        ];
    }
}
