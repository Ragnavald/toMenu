<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Category> */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        $name = fake()->randomElement(['Entradas', 'Pizzas', 'Massas', 'Bebidas', 'Sobremesas']);

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'position' => fake()->numberBetween(0, 10),
            'is_active' => true,
        ];
    }
}
