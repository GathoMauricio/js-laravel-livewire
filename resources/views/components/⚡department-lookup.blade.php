<?php

/** Looks up a department and displays its assigned products by unique code. */
use Livewire\Component;
use Livewire\Attributes\Computed;
use App\Models\Department;
use Illuminate\Database\Eloquent\Relations\Relation;

new class extends Component
{
    public string $departmentCode = '';

    public string $searchedCode = '';

    public function search(): void
    {
        $this->departmentCode = strtoupper(trim($this->departmentCode));
        $this->searchedCode = '';

        $validated = $this->validate([
            'departmentCode' => ['required', 'string', 'max:40', 'exists:departments,code'],
        ]);

        $this->searchedCode = $validated['departmentCode'];
    }

    #[Computed]
    public function department(): ?Department
    {
        if ($this->searchedCode === '') {
            return null;
        }

        return Department::query()
            ->with(['products' => function (Relation $query): void {
                $query->orderBy('name');
            }])
            ->where('code', $this->searchedCode)
            ->first();
    }
};
?>

<section aria-labelledby="lookup-title">
    <div class="panel-heading">
        <div>
            <span class="eyebrow">Consulta</span>
            <h2 id="lookup-title">Productos por departamento</h2>
            <p>Busca una clave para consultar sus productos y cantidades asignadas.</p>
        </div>
    </div>

    <form class="row g-2 align-items-end" wire:submit="search">
        <div class="col-12 col-md-7 col-lg-5">
            <label class="form-label" for="lookup-code">Clave del departamento</label>
            <input id="lookup-code" class="form-control" type="text" maxlength="40" wire:model="departmentCode" placeholder="DEP-1001" autocomplete="off">
            @error('departmentCode') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
        </div>
        <div class="col-12 col-md-auto">
            <button class="btn btn-primary" type="submit" wire:loading.attr="disabled" wire:target="search">
                <span wire:loading.remove wire:target="search">Consultar departamento</span>
                <span wire:loading wire:target="search">Buscando...</span>
            </button>
        </div>
    </form>

    @if ($this->department)
        <div class="lookup-summary">
            <div>
                <strong>{{ $this->department->name }}</strong>
                <span>Clave {{ $this->department->code }}</span>
            </div>
            <span>{{ $this->department->products->count() }} productos</span>
        </div>

        <div class="data-table-wrap">
            <table class="table table-hover">
                <thead><tr><th>Clave</th><th>Producto</th><th class="text-end">Asignados</th><th class="text-end">Inventario disponible</th></tr></thead>
                <tbody>
                    @forelse ($this->department->products as $product)
                        <tr>
                            <td><span class="code-value">{{ $product->code }}</span></td>
                            <td>{{ $product->name }}</td>
                            <td class="text-end">{{ number_format($product->pivot->quantity) }}</td>
                            <td class="text-end">{{ number_format($product->stock) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><div class="empty-state"><strong>Sin productos asignados</strong>Este departamento aún no tiene asignaciones.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</section>
</div>