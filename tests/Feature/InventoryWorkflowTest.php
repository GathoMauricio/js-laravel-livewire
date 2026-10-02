<?php

namespace Tests\Feature;

use App\Livewire\AiChatAssistant;
use App\Models\Department;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
            ->assertSee('Consulta por clave')
            ->assertSee('Asistente IA');
    }

    public function test_dashboard_can_open_the_user_management_panel(): void
    {
        Livewire::test('inventory-dashboard')
            ->call('setPanel', 'admin.user-manager')
            ->assertSee('Usuarios')
            ->assertSee('Crear usuario')
            ->assertSee('Correo electrónico');
    }

    public function test_user_management_panel_can_create_and_delete_a_user(): void
    {
        Livewire::test('admin.user-manager')
            ->set('name', 'Patricia Gómez')
            ->set('email', 'patricia@example.com')
            ->set('password', 'password-seguro')
            ->call('saveUser')
            ->assertHasNoErrors()
            ->assertSee('Usuario creado correctamente.');

        $user = User::query()->where('email', 'patricia@example.com')->firstOrFail();

        Livewire::test('admin.user-manager')
            ->call('deleteUser', $user->id)
            ->assertHasNoErrors()
            ->assertSee('Usuario eliminado correctamente.');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    /** Verifies the Groq tool-call round, stock adjustment, and final assistant response. */
    public function test_ai_assistant_executes_stock_tools_and_returns_a_final_answer(): void
    {
        // Creates a known starting balance for deterministic tool assertions.
        $product = Product::factory()->create(['name' => 'Papel carta', 'stock' => 8]);

        // Replaces the provider with a fake key/model so this test makes no external request.
        config()->set('services.groq.key', 'test-api-key');
        config()->set('services.groq.model', 'openai/gpt-oss-120b');
        // First Groq response asks the assistant component to call adjust_stock.
        Http::fakeSequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_adjust_stock',
                            'type' => 'function',
                            'function' => [
                                'name' => 'adjust_stock',
                                'arguments' => json_encode(['product_id' => $product->id, 'quantity' => 5]),
                            ],
                        ]],
                    ],
                ]],
            ])
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Se agregaron 5 unidades. Ahora hay 13 en existencia.',
                    ],
                ]],
            ]);

        // Sends a realistic user instruction through the Livewire component.
        Livewire::test(AiChatAssistant::class)
            ->set('userMessage', 'Agrega 5 unidades de papel carta')
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->assertDispatched('inventory-updated')
            ->assertSee('Se agregaron 5 unidades. Ahora hay 13 en existencia.');

        // Confirms the local tool ran and the second fake response became visible.
        $this->assertSame(13, $product->fresh()->stock);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
            && $request['model'] === 'openai/gpt-oss-120b');
    }

    /** Verifies Groq can create a department and allocate stock through one tool. */
    public function test_ai_assistant_creates_department_and_assigns_product_atomically(): void
    {
        // Models 30 units available and 20 already assigned to another department.
        $product = Product::factory()->create(['code' => 'PRD-1001', 'name' => 'Prensa', 'stock' => 30]);
        $existingDepartment = Department::factory()->create(['name' => 'Administración']);
        $existingDepartment->products()->attach($product, ['quantity' => 20]);

        // Keeps the provider call deterministic and prevents external mutations in tests.
        config()->set('services.groq.key', 'test-api-key');
        config()->set('services.groq.model', 'openai/gpt-oss-120b');
        // Simulates the model selecting the composite assignment tool and then summarizing it.
        Http::fakeSequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_assign_product',
                            'type' => 'function',
                            'function' => [
                                'name' => 'assign_product_to_department',
                                'arguments' => json_encode([
                                    'department_name' => 'Dirección',
                                    'product_search' => 'Prensa',
                                    'quantity' => 20,
                                ]),
                            ],
                        ]],
                    ],
                ]],
            ])
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => "Se asignaron 20 unidades de Prensa a Dirección.\n\n| Departamento | Asignadas |\n| --- | ---: |\n| Administración | 20 |\n| Dirección | 20 |",
                    ],
                ]],
            ]);

        // Exercises the same natural-language request supplied by an inventory user.
        Livewire::test(AiChatAssistant::class)
            ->set('userMessage', 'Crea un nuevo departamento llamado Dirección y asígnale 20 unidades de prensa y devuelve el resumen en forma de tabla')
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->assertDispatched('inventory-updated')
            ->assertSee('20 unidades de Prensa')
            ->assertSee('Dirección');

        // Checks the generated unique key, stock decrease, pivot quantity, and preserved old row.
        $department = Department::query()->where('name', 'Dirección')->firstOrFail();

        $this->assertSame('DEP-DIRECCION', $department->code);
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(20, (int) $department->products()->whereKey($product->id)->first()->pivot->quantity);
        $this->assertDatabaseCount('department_product', 2);
        Http::assertSentCount(2);
    }

    /** Proves insufficient inventory leaves both departments and allocations untouched. */
    public function test_failed_department_assignment_does_not_create_department_or_change_stock(): void
    {
        // The requested allocation exceeds this product's available stock.
        $product = Product::factory()->create(['name' => 'Prensa', 'stock' => 5]);

        // Calls the business tool directly to isolate its transactional behavior.
        $result = app(InventoryAgentService::class)->executeTool('assign_product_to_department', [
            'department_name' => 'Dirección',
            'product_search' => 'Prensa',
            'quantity' => 20,
        ]);

        // Confirms failure rolls back without creating a department or pivot row.
        $this->assertFalse($result['success']);
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertDatabaseMissing('departments', ['name' => 'Dirección']);
        $this->assertDatabaseCount('department_product', 0);
    }

    /** Documents the distinct meanings of available, assigned, and total stock. */
    public function test_check_stock_tool_returns_department_allocations(): void
    {
        // Creates a known product/department allocation for the stock summary tool.
        $product = Product::factory()->create(['name' => 'Papel carta', 'stock' => 7]);
        $department = Department::factory()->create(['name' => 'Administración']);
        $department->products()->attach($product, ['quantity' => 4]);

        // Searches with the same service method used by Groq tool calling.
        $result = app(InventoryAgentService::class)->executeTool('check_stock', ['search' => 'papel']);

        $this->assertTrue($result['success']);
        $this->assertSame($product->id, $result['products'][0]['id']);
        $this->assertSame('Papel carta', $result['products'][0]['name']);
        $this->assertSame('Administración', $result['products'][0]['departments'][0]['name']);
        $this->assertSame(4, $result['products'][0]['departments'][0]['quantity']);
        $this->assertSame(7, $result['products'][0]['available_stock']);
        $this->assertSame(4, $result['products'][0]['assigned_stock']);
        $this->assertSame(11, $result['products'][0]['total_stock']);
    }

    public function test_adjust_stock_tool_does_not_allow_negative_inventory(): void
    {
        $product = Product::factory()->create(['stock' => 3]);

        $result = app(InventoryAgentService::class)->executeTool('adjust_stock', [
            'product_id' => $product->id,
            'quantity' => -4,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(3, $product->fresh()->stock);
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
