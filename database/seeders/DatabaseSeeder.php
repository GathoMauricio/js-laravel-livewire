<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/** Seeds a demo user, catalog, departments, and sample inventory allocations. */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Administración de inventario',
            'email' => 'inventario@example.com',
        ]);

        $this->call([
            ProductSeeder::class,
            DepartmentSeeder::class,
        ]);

        $products = Product::query()->get();

        foreach (Department::query()->get() as $department) {
            foreach ($products->shuffle()->take(3) as $product) {
                $available = (int) $product->stock;

                if ($available === 0) {
                    continue;
                }

                $quantity = fake()->numberBetween(1, min($available, 4));

                $department->products()->attach($product, ['quantity' => $quantity]);
                $product->decrement('stock', $quantity);
                $product->refresh();
            }
        }
    }
}
