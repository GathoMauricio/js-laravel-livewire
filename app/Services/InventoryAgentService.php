<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Defines and executes the database tools exposed to the inventory assistant. */
class InventoryAgentService
{
    /** Describe las tools que Groq puede solicitar mediante Function Calling. */
    public function getToolsSchema(): array
    {
        // La respuesta de este método se envía en cada petición al proveedor.
        return [
            [
                'type' => 'function',
                'function' => [
                    // check_stock permite búsquedas de solo lectura por nombre.
                    'name' => 'check_stock',
                    'description' => 'Search inventory products by name and return available stock and department allocations.',
                    'strict' => true,
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'search' => [
                                'type' => 'string',
                                'description' => 'Text to match against product names.',
                            ],
                        ],
                        'required' => ['search'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    // adjust_stock cambia existencias sin asociarlas a un departamento.
                    'name' => 'adjust_stock',
                    'description' => 'Adjust a product stock. Positive quantity adds units; negative quantity removes units. Stock cannot become negative.',
                    'strict' => true,
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'product_id' => [
                                'type' => 'integer',
                                'description' => 'The numeric ID of the product to adjust.',
                            ],
                            'quantity' => [
                                'type' => 'integer',
                                'description' => 'Non-zero units to add (positive) or remove (negative).',
                            ],
                        ],
                        'required' => ['product_id', 'quantity'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    // Esta tool combina alta del departamento y asignación de producto.
                    'name' => 'assign_product_to_department',
                    'description' => 'Find one product by name or code, create the department if it does not exist, then allocate the requested units. Decreases available stock atomically. Use this instead of adjust_stock for department assignments.',
                    'strict' => true,
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'department_name' => [
                                'type' => 'string',
                                'description' => 'Name of the department to create or use.',
                            ],
                            'product_search' => [
                                'type' => 'string',
                                'description' => 'Product name or code to identify exactly one product.',
                            ],
                            'quantity' => [
                                'type' => 'integer',
                                'description' => 'Positive number of units to allocate.',
                            ],
                        ],
                        'required' => ['department_name', 'product_search', 'quantity'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];
    }

    /** Executes a tool request and returns a JSON-serializable result. */
    public function executeTool(string $name, array $args): array
    {
        // Solo se ejecutan tools incluidas explícitamente en el contrato anterior.
        return match ($name) {
            'check_stock' => $this->checkStock($args),
            'adjust_stock' => $this->adjustStock($args),
            'assign_product_to_department' => $this->assignProductToDepartment($args),
            default => ['success' => false, 'message' => 'La herramienta solicitada no está disponible.'],
        };
    }

    /** Searches products by name and includes their department allocations. */
    private function checkStock(array $args): array
    {
        // Lee el término proporcionado por el modelo sin confiar en su tipo.
        $search = $args['search'] ?? null;

        // Una búsqueda vacía no debe provocar una consulta amplia al catálogo.
        if (! is_string($search) || trim($search) === '') {
            return ['success' => false, 'message' => 'Indica un texto para buscar productos.'];
        }

        // Carga departamentos y cantidades en la misma consulta para evitar N+1.
        $products = Product::query()
            ->with(['departments' => fn ($query) => $query->orderBy('departments.name')])
            ->where('name', 'like', '%'.trim($search).'%')
            ->orderBy('name')
            ->limit(20)
            ->get();

        // Calcula existencias disponibles, asignadas y totales por producto.
        return [
            'success' => true,
            'count' => $products->count(),
            'products' => $products->map(function (Product $product): array {
                // Expone cada asignación con la clave y nombre del departamento.
                $departments = $product->departments->map(fn ($department): array => [
                    'id' => $department->id,
                    'code' => $department->code,
                    'name' => $department->name,
                    'quantity' => (int) $department->pivot->quantity,
                ])->all();
                $availableStock = (int) $product->stock; // stock representa solo lo que queda en bodega.
                $assignedStock = array_sum(array_column($departments, 'quantity')); // suma unidades fuera de bodega.

                // Mantiene disponible separado de asignado para impedir dobles restas.
                return [
                    'id' => $product->id,
                    'code' => $product->code,
                    'name' => $product->name,
                    'stock' => $availableStock,
                    'available_stock' => $availableStock,
                    'assigned_stock' => $assignedStock,
                    'total_stock' => $availableStock + $assignedStock,
                    'departments' => $departments,
                ];
            })->all(),
        ];
    }

    /** Applies an atomic stock adjustment without allowing a negative balance. */
    private function adjustStock(array $args): array
    {
        // Extrae parámetros antes de validar la solicitud de la herramienta.
        $productId = $args['product_id'] ?? null;
        $quantity = $args['quantity'] ?? null;

        // Limita IDs, cantidades cero y magnitudes excesivas antes de tocar la base.
        if (! is_int($productId) || $productId < 1 || ! is_int($quantity) || $quantity === 0 || abs($quantity) > 1_000_000) {
            return ['success' => false, 'message' => 'La clave del producto o la cantidad no son válidas.'];
        }

        // La lectura y la modificación pertenecen a una misma transacción.
        return DB::transaction(function () use ($productId, $quantity): array {
            // Devuelve un error si el producto desapareció antes de ejecutar la tool.
            if (! Product::query()->whereKey($productId)->exists()) {
                return ['success' => false, 'message' => 'No se encontró el producto indicado.'];
            }

            if ($quantity > 0) {
                // Incrementar en SQL evita sobrescribir stock actualizado concurrentemente.
                $updated = Product::query()->whereKey($productId)->increment('stock', $quantity);
            } else {
                // La condición evita que dos solicitudes retiren más de lo disponible.
                $decrease = abs($quantity);
                $updated = Product::query()
                    ->whereKey($productId)
                    ->where('stock', '>=', $decrease)
                    ->decrement('stock', $decrease);
            }

            if ($updated !== 1) {
                return [
                    'success' => false,
                    'message' => 'La existencia disponible no alcanza para retirar esa cantidad.',
                ];
            }

            // Lee el nuevo valor para informar el saldo final al modelo.
            $product = Product::query()->find($productId);

            return [
                'success' => true,
                'product_id' => $productId,
                'product_name' => $product?->name,
                'change' => $quantity,
                'stock' => (int) ($product?->stock ?? 0),
                'message' => $quantity > 0
                    ? 'Se agregaron unidades a la existencia.'
                    : 'Se retiraron unidades de la existencia.',
            ];
        });
    }

    /** Creates a department when needed and allocates stock in one transaction. */
    private function assignProductToDepartment(array $args): array
    {
        // Extrae los tres valores necesarios para asignar inventario a un área.
        $departmentName = $args['department_name'] ?? null;
        $productSearch = $args['product_search'] ?? null;
        $quantity = $args['quantity'] ?? null;

        // Rechaza parámetros incorrectos antes de abrir una transacción.
        if (! is_string($departmentName)
            || trim($departmentName) === ''
            || mb_strlen(trim($departmentName)) > 160
            || ! is_string($productSearch)
            || trim($productSearch) === ''
            || ! is_int($quantity)
            || $quantity < 1
            || $quantity > 1_000_000) {
            return ['success' => false, 'message' => 'El departamento, producto o cantidad no son válidos.'];
        }

        // Normaliza espacios para evitar crear nombres con diferencias accidentales.
        $departmentName = trim($departmentName);
        $productSearch = trim($productSearch);

        // Cualquier fallo revierte conjuntamente el descuento y la asignación.
        return DB::transaction(function () use ($departmentName, $productSearch, $quantity): array {
            // Limita resultados a dos para distinguir coincidencia única de ambigua.
            $products = Product::query()
                ->with('departments')
                ->where(function ($query) use ($productSearch): void {
                    $query->where('name', 'like', '%'.$productSearch.'%')
                        ->orWhere('code', $productSearch);
                })
                ->orderBy('name')
                ->limit(2)
                ->get();

            if ($products->isEmpty()) {
                // No crea departamentos si no existe el producto pedido.
                return ['success' => false, 'message' => 'No se encontró un producto que coincida con esa búsqueda.'];
            }

            if ($products->count() > 1) {
                // Solicita una búsqueda más precisa en vez de asignar el producto equivocado.
                return [
                    'success' => false,
                    'message' => 'La búsqueda coincide con varios productos. Usa una clave o nombre más específico.',
                    'matches' => $products->map(fn (Product $product): array => [
                        'id' => $product->id,
                        'code' => $product->code,
                        'name' => $product->name,
                    ])->all(),
                ];
            }

            // La coincidencia única identifica el registro que se va a modificar.
            $product = $products->first();
            // Descuenta en SQL solo cuando aún existe cantidad suficiente.
            $updated = Product::query()
                ->whereKey($product->id)
                ->where('stock', '>=', $quantity)
                ->decrement('stock', $quantity);

            if ($updated !== 1) {
                // El retorno ocurre antes de crear el departamento o cambiar el pivot.
                return [
                    'success' => false,
                    'message' => 'No hay suficientes unidades disponibles para completar la asignación.',
                    'available_stock' => (int) $product->stock,
                    'requested_quantity' => $quantity,
                ];
            }

            // Reutiliza departamentos existentes sin distinguir mayúsculas/minúsculas.
            $department = Department::query()
                ->whereRaw('LOWER(name) = ?', [Str::lower($departmentName)])
                ->first();
            $departmentCreated = $department === null;

            if ($departmentCreated) {
                // La clave se genera aquí para que sea única incluso ante colisiones.
                $department = Department::query()->create([
                    'name' => $departmentName,
                    'code' => $this->uniqueDepartmentCode($departmentName),
                ]);
            }

            // Lee una posible asignación anterior para acumular cantidades.
            $existingAllocation = $department->products()
                ->where('products.id', $product->id)
                ->first();
            $departmentQuantity = (int) ($existingAllocation?->pivot->quantity ?? 0) + $quantity;

            // Inserta o actualiza el pivot sin borrar otras relaciones del departamento.
            $department->products()->syncWithoutDetaching([
                $product->id => ['quantity' => $departmentQuantity],
            ]);

            // Recarga stock y departamentos después de confirmar la nueva asignación.
            $product->refresh()->load('departments');
            $departments = $product->departments->map(fn (Department $assignedDepartment): array => [
                'id' => $assignedDepartment->id,
                'code' => $assignedDepartment->code,
                'name' => $assignedDepartment->name,
                'quantity' => (int) $assignedDepartment->pivot->quantity,
            ])->all();
            // Resume el estado para que el modelo pueda devolver una tabla correcta.
            $availableStock = (int) $product->stock;
            $assignedStock = array_sum(array_column($departments, 'quantity'));

            return [
                'success' => true,
                'department_created' => $departmentCreated,
                'department' => [
                    'id' => $department->id,
                    'code' => $department->code,
                    'name' => $department->name,
                    'quantity_assigned' => $departmentQuantity,
                ],
                'product' => [
                    'id' => $product->id,
                    'code' => $product->code,
                    'name' => $product->name,
                    'available_stock' => $availableStock,
                    'assigned_stock' => $assignedStock,
                    'total_stock' => $availableStock + $assignedStock,
                    'departments' => $departments,
                ],
                'message' => 'La asignación se completó correctamente.',
            ];
        });
    }

    /** Generates a readable unique department code, adding a suffix on collisions. */
    private function uniqueDepartmentCode(string $departmentName): string
    {
        // Convierte el nombre en una clave legible y compatible con el límite de columna.
        $baseCode = 'DEP-'.Str::upper(Str::slug($departmentName));
        $baseCode = substr($baseCode === 'DEP-' ? 'DEP-DEPARTMENT' : $baseCode, 0, 40);
        $code = $baseCode;
        $suffix = 2;

        // Agrega sufijos incrementales si ya existe una clave generada igual.
        while (Department::query()->where('code', $code)->exists()) {
            $suffixText = '-'.$suffix++;
            $code = substr($baseCode, 0, 40 - strlen($suffixText)).$suffixText;
        }

        return $code;
    }
}
