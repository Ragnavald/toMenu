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
            'allows_online_payment' => true,
            'allows_orders' => true,
            'allows_delivery' => true,
        ];
    }

    /** Plano vitrine: cardápio publicado, sem pedidos nem entrega. */
    public function menuOnly(): static
    {
        return $this->state(fn () => [
            'name' => 'Cardápio digital',
            'slug' => 'cardapio-'.fake()->unique()->numberBetween(1, 99999),
            'price_cents' => 2900,
            'max_products' => 150,
            'allows_online_payment' => false,
            'allows_orders' => false,
            'allows_delivery' => false,
        ]);
    }
}
