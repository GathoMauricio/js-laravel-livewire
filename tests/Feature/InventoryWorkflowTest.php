<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Covers inventory registration, stock movement, search, and department lookup. */
class InventoryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_product_registration_and_dynamic_sections(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('Control de inventario')
            ->assertSee('Registrar producto')
            ->assertSee('Consulta por clave');
    }

    public function test_product_can_be_registered_and_searched(): void
    {
        Livewire::test('manage-products')
            ->set('code', 'prd-1001')
            ->set('name', 'Papel carta')
            ->set('stock', 35)
            ->call('saveProduct')
            ->assertHasNoErrors()
            ->assertSee('Producto registrado correctamente.');

        $this->assertDatabaseHas('products', [
            'code' => 'PRD-1001',
            'name' => 'Papel carta',
            'stock' => 35,
        ]);

        Product::factory()->create(['code' => 'PRD-1002', 'name' => 'Tóner negro']);

        Livewire::test('manage-products')
            ->set('search', 'PRD-1001')
            ->assertSee('Papel carta')
            ->assertDontSee('Tóner negro');
    }

    public function test_product_code_must_be_unique(): void
    {
        Product::factory()->create(['code' => 'PRD-2001']);

        Livewire::test('manage-products')
            ->set('code', 'PRD-2001')
            ->set('name', 'Producto repetido')
            ->set('stock', 5)
            ->call('saveProduct')
            ->assertHasErrors(['code' => 'unique']);

        $this->assertDatabaseCount('products', 1);
    }

    public function test_department_can_be_registered_with_a_unique_code(): void
    {
        Livewire::test('manage-departments')
            ->set('code', 'dep-1001')
            ->set('name', 'Recursos humanos')
            ->call('saveDepartment')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('departments', [
            'code' => 'DEP-1001',
            'name' => 'Recursos humanos',
        ]);

        Livewire::test('manage-departments')
            ->set('code', 'DEP-1001')
            ->set('name', 'Duplicado')
            ->call('saveDepartment')
            ->assertHasErrors(['code' => 'unique']);
    }

    public function test_assignment_decreases_stock_and_accumulates_department_quantity(): void
    {
        $product = Product::factory()->create(['stock' => 12]);
        $department = Department::factory()->create();

        Livewire::test('assign-products')
            ->set('departmentId', (string) $department->id)
            ->set('productId', (string) $product->id)
            ->set('quantity', 4)
            ->call('assign')
            ->assertHasNoErrors();

        Livewire::test('assign-products')
            ->set('departmentId', (string) $department->id)
            ->set('productId', (string) $product->id)
            ->set('quantity', 3)
            ->call('assign')
            ->assertHasNoErrors();

        $this->assertSame(5, $product->fresh()->stock);
        $this->assertSame(7, (int) $department->products()->first()->pivot->quantity);
    }

    public function test_assignment_cannot_reduce_stock_below_zero(): void
    {
        $product = Product::factory()->create(['stock' => 2]);
        $department = Department::factory()->create();

        Livewire::test('assign-products')
            ->set('departmentId', (string) $department->id)
            ->set('productId', (string) $product->id)
            ->set('quantity', 3)
            ->call('assign')
            ->assertHasErrors('productId');

        $this->assertSame(2, $product->fresh()->stock);
        $this->assertDatabaseCount('department_product', 0);
    }

    public function test_department_lookup_shows_assigned_products(): void
    {
        $product = Product::factory()->create(['code' => 'PRD-3001', 'name' => 'Silla operativa']);
        $department = Department::factory()->create(['code' => 'DEP-3001', 'name' => 'Administración']);
        $department->products()->attach($product, ['quantity' => 6]);

        Livewire::test('department-lookup')
            ->set('departmentCode', 'dep-3001')
            ->call('search')
            ->assertHasNoErrors()
            ->assertSee('Administración')
            ->assertSee('Silla operativa')
            ->assertSee('6')
            ->set('departmentCode', 'DEP-UNKNOWN')
            ->call('search')
            ->assertHasErrors('departmentCode')
            ->assertDontSee('Administración');
    }
}
