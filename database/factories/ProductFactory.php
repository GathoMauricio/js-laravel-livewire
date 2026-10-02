<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Generates products with unique inventory codes and realistic stock values.
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'PRD-'.$this->faker->unique()->numerify('#####'),
            'name' => $this->faker->words(2, true),
            'stock' => $this->faker->numberBetween(0, 120),
        ];
    }
}
