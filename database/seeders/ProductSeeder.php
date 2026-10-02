<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

/** Seeds a sample catalog through the product factory. */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        Product::factory()->count(16)->create();
    }
}
