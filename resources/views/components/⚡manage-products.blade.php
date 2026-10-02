<?php

/** Registers inventory products and filters the current catalog. */
use Livewire\Component;
use Livewire\Attributes\Computed;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

new class extends Component
{
    public string $code = '';

    public string $name = '';

    public int $stock = 0;

    public string $search = '';

    public function saveProduct(): void
    {
        $this->code = strtoupper(trim($this->code));

        $validated = $this->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9-]+$/', 'unique:products,code'],
            'name' => ['required', 'string', 'max:160'],
            'stock' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        Product::query()->create($validated);

        $this->reset(['code', 'name']);
        $this->stock = 0;
        $this->dispatch('inventory-updated');
        session()->flash('status', 'Producto registrado correctamente.');
    }

    #[Computed]
    public function filteredProducts(): Collection
    {
        return Product::query()
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $term = '%'.trim($this->search).'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('code', 'like', $term)->orWhere('name', 'like', $term);
                });
            })
            ->orderBy('name')
            ->limit(100)
            ->get();
    }
};
?>

<section aria-labelledby="products-title">
    <div class="panel-heading">
        <div>
            <span class="eyebrow">Catálogo</span>
            <h2 id="products-title">Productos</h2>
            <p>Registra artículos y consulta las existencias disponibles.</p>
        </div>
    </div>

    @if (session()->has('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    <div class="row g-4">
        <div class="col-12 col-lg-4">
            <form class="form-panel" wire:submit="saveProduct">
                <h3>Registrar producto</h3>
                <div class="mb-3">
                    <label class="form-label" for="product-code">Clave</label>
                    <input id="product-code" class="form-control" type="text" maxlength="40" wire:model="code" placeholder="PRD-00001" autocomplete="off">
                    @error('code') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>
                <div class="mb-3">
                    <label class="form-label" for="product-name">Nombre del producto</label>
                    <input id="product-name" class="form-control" type="text" maxlength="160" wire:model="name" placeholder="Nombre del artículo">
                    @error('name') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>
                <div class="mb-4">
                    <label class="form-label" for="product-stock">Existencia inicial</label>
                    <input id="product-stock" class="form-control" type="number" min="0" max="1000000" wire:model="stock">
                    @error('stock') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>
                <button class="btn btn-primary w-100" type="submit" wire:loading.attr="disabled" wire:target="saveProduct">
                    <span wire:loading.remove wire:target="saveProduct">Registrar producto</span>
                    <span wire:loading wire:target="saveProduct">Guardando...</span>
                </button>
            </form>
        </div>

        <div class="col-12 col-lg-8">
            <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
                <div>
                    <h3 class="h6 mb-1 fw-bold">Existencias actuales</h3>
                    <span class="small text-secondary">Mostrando hasta 100 coincidencias</span>
                </div>
                <div class="flex-grow-1" style="max-width: 310px">
                    <label class="visually-hidden" for="product-search">Buscar productos</label>
                    <input id="product-search" class="form-control" type="search" wire:model.live.debounce.250ms="search" placeholder="Buscar por clave o nombre">
                </div>
            </div>

            <div class="data-table-wrap">
                <table class="table table-hover">
                    <thead>
                        <tr><th>Clave</th><th>Producto</th><th class="text-end">Disponible</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($this->filteredProducts as $product)
                            <tr>
                                <td><span class="code-value">{{ $product->code }}</span></td>
                                <td>{{ $product->name }}</td>
                                <td class="text-end"><span class="stock-pill {{ $product->stock < 5 ? 'low' : '' }}">{{ number_format($product->stock) }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="3"><div class="empty-state"><strong>No hay productos</strong>Registra un producto o cambia la búsqueda.</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>