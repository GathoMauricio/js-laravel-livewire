<?php

namespace Database\Factories;

use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Generates departments with unique codes for seed and test data.
 *
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'DEP-'.$this->faker->unique()->numerify('####'),
            'name' => $this->faker->words(2, true),
        ];
    }
}
