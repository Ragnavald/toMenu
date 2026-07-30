<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Plan> */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'name' => 'Pro',
            'slug' => 'pro-'.fake()->unique()->numberBetween(1, 99999),
            'price_cents' => 9900,
            'max_products' => 500,
            'allows_custom_domain' => true,
            'allows_online_payment' => true,
        ];
    }
}
