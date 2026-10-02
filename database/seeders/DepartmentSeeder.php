<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

/** Seeds sample departments through the department factory. */
class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        Department::factory()->count(5)->create();
    }
}
