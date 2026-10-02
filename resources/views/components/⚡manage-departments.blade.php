<?php

/** Registers departments and lists their unique lookup codes. */
use Livewire\Component;
use Livewire\Attributes\Computed;
use App\Models\Department;
use Illuminate\Database\Eloquent\Collection;

new class extends Component
{
    public string $code = '';

    public string $name = '';

    public function saveDepartment(): void
    {
        $this->code = strtoupper(trim($this->code));

        $validated = $this->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9-]+$/', 'unique:departments,code'],
            'name' => ['required', 'string', 'max:160'],
        ]);

        Department::query()->create($validated);

        $this->reset(['code', 'name']);
        $this->dispatch('inventory-updated');
        session()->flash('status', 'Departamento registrado correctamente.');
    }

    #[Computed]
    public function departments(): Collection
    {
        return Department::query()->withCount('products')->orderBy('name')->limit(100)->get();
    }
};
?>

<section aria-labelledby="departments-title">
    <div class="panel-heading">
        <div>
            <span class="eyebrow">Organización</span>
            <h2 id="departments-title">Departamentos</h2>
            <p>Registra las áreas que reciben productos del inventario.</p>
        </div>
    </div>

    @if (session()->has('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    <div class="row g-4">
        <div class="col-12 col-lg-4">
            <form class="form-panel" wire:submit="saveDepartment">
                <h3>Registrar departamento</h3>
                <div class="mb-3">
                    <label class="form-label" for="department-code">Clave</label>
                    <input id="department-code" class="form-control" type="text" maxlength="40" wire:model="code" placeholder="DEP-1001" autocomplete="off">
                    @error('code') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>
                <div class="mb-4">
                    <label class="form-label" for="department-name">Nombre</label>
                    <input id="department-name" class="form-control" type="text" maxlength="160" wire:model="name" placeholder="Nombre del departamento">
                    @error('name') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>
                <button class="btn btn-primary w-100" type="submit" wire:loading.attr="disabled" wire:target="saveDepartment">
                    <span wire:loading.remove wire:target="saveDepartment">Registrar departamento</span>
                    <span wire:loading wire:target="saveDepartment">Guardando...</span>
                </button>
            </form>
        </div>

        <div class="col-12 col-lg-8">
            <h3 class="h6 mb-3 fw-bold">Departamentos registrados</h3>
            <div class="data-table-wrap">
                <table class="table table-hover">
                    <thead><tr><th>Clave</th><th>Departamento</th><th class="text-end">Productos asignados</th></tr></thead>
                    <tbody>
                        @forelse ($this->departments as $department)
                            <tr>
                                <td><span class="code-value">{{ $department->code }}</span></td>
                                <td>{{ $department->name }}</td>
                                <td class="text-end">{{ $department->products_count }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3"><div class="empty-state"><strong>Aún no hay departamentos</strong>Registra el primero para empezar.</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>