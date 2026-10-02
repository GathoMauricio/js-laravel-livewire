<?php

/** Assigns available product stock to departments atomically. */
use Livewire\Component;
use Livewire\Attributes\Computed;
use App\Models\Department;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

new class extends Component
{
    public string $departmentId = '';

    public string $productId = '';

    public int $quantity = 1;

    public function assign(): void
    {
        $validated = $this->validate([
            'departmentId' => ['required', 'exists:departments,id'],
            'productId' => ['required', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        DB::transaction(function () use ($validated): void {
            $productId = (int) $validated['productId'];
            $quantity = (int) $validated['quantity'];

            $updated = Product::query()
                ->whereKey($productId)
                ->where('stock', '>=', $quantity)
                ->decrement('stock', $quantity);

            if ($updated !== 1) {
                throw ValidationException::withMessages([
                    'productId' => 'La existencia disponible no alcanza para esa asignación.',
                ]);
            }

            $department = Department::query()->findOrFail($validated['departmentId']);
            $assigned = $department->products()->whereKey($productId)->first()?->pivot->quantity ?? 0;

            $department->products()->syncWithoutDetaching([
                $productId => ['quantity' => (int) $assigned + $quantity],
            ]);
        });

        $this->quantity = 1;
        $this->productId = '';
        $this->dispatch('inventory-updated');
        session()->flash('status', 'Productos asignados y existencias actualizadas.');
    }

    #[Computed]
    public function departments(): Collection
    {
        return Department::query()->orderBy('name')->get();
    }

    #[Computed]
    public function availableProducts(): Collection
    {
        return Product::query()->where('stock', '>', 0)->orderBy('name')->get();
    }
};
?>

<section aria-labelledby="assignment-title">
    <div class="panel-heading">
        <div>
            <span class="eyebrow">Movimiento de inventario</span>
            <h2 id="assignment-title">Asignar productos</h2>
            <p>La cantidad asignada se descuenta de las existencias disponibles.</p>
        </div>
    </div>

    @if (session()->has('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    <div class="row g-4">
        <div class="col-12 col-lg-5">
            <form class="form-panel" wire:submit="assign">
                <h3>Nueva asignación</h3>
                <div class="mb-3">
                    <label class="form-label" for="assignment-department">Departamento</label>
                    <select id="assignment-department" class="form-select" wire:model="departmentId">
                        <option value="">Selecciona un departamento</option>
                        @foreach ($this->departments as $department)
                            <option value="{{ $department->id }}">{{ $department->code }} · {{ $department->name }}</option>
                        @endforeach
                    </select>
                    @error('departmentId') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>
                <div class="mb-3">
                    <label class="form-label" for="assignment-product">Producto</label>
                    <select id="assignment-product" class="form-select" wire:model="productId">
                        <option value="">Selecciona un producto</option>
                        @foreach ($this->availableProducts as $product)
                            <option value="{{ $product->id }}">{{ $product->code }} · {{ $product->name }} ({{ $product->stock }} disponibles)</option>
                        @endforeach
                    </select>
                    @error('productId') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>
                <div class="mb-4">
                    <label class="form-label" for="assignment-quantity">Cantidad</label>
                    <input id="assignment-quantity" class="form-control" type="number" min="1" max="1000000" wire:model="quantity">
                    @error('quantity') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>
                <button class="btn btn-primary w-100" type="submit" wire:loading.attr="disabled" wire:target="assign">
                    <span wire:loading.remove wire:target="assign">Confirmar asignación</span>
                    <span wire:loading wire:target="assign">Actualizando inventario...</span>
                </button>
            </form>
        </div>

        <div class="col-12 col-lg-7 d-flex align-items-center">
            <div class="empty-state w-100">
                <strong>Control de existencias activo</strong>
                No es posible asignar más unidades de las disponibles. Las asignaciones repetidas al mismo departamento se acumulan.
            </div>
        </div>
    </div>
</section>
</div>