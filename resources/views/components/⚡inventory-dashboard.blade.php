<?php

/** Coordinates dashboard metrics and the active inventory panel. */
use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use App\Models\Department;
use App\Models\Product;

new class extends Component
{
    public string $activePanel = 'manage-products';

    private const PANELS = [
        'manage-products',
        'manage-departments',
        'assign-products',
        'department-lookup',
        'admin.user-manager',
        // Allow the dashboard to mount the AI chat through its dynamic component.
        'ai-chat-assistant',
    ];

    public function setPanel(string $panel): void
    {
        if (in_array($panel, self::PANELS, true)) {
            $this->activePanel = $panel;
        }
    }

    #[On('inventory-updated')]
    public function refreshInventory(): void
    {
        unset($this->productCount, $this->departmentCount, $this->availableStock);
    }

    #[Computed]
    public function productCount(): int
    {
        return Product::query()->count();
    }

    #[Computed]
    public function departmentCount(): int
    {
        return Department::query()->count();
    }

    #[Computed]
    public function availableStock(): int
    {
        return (int) Product::query()->sum('stock');
    }
};
?>

<main class="inventory-dashboard">
    <header class="page-heading">
        <div>
            <span class="eyebrow">Resumen operativo</span>
            <h1>Control de inventario</h1>
            <p>Productos, departamentos y movimientos de existencia.</p>
        </div>
    </header>

    <section class="metrics-grid" aria-label="Resumen del inventario">
        <article class="metric-item">
            <span class="metric-label">Productos registrados</span>
            <strong class="metric-value">{{ number_format($this->productCount) }}</strong>
        </article>
        <article class="metric-item">
            <span class="metric-label">Departamentos</span>
            <strong class="metric-value">{{ number_format($this->departmentCount) }}</strong>
        </article>
        <article class="metric-item">
            <span class="metric-label">Unidades disponibles</span>
            <strong class="metric-value">{{ number_format($this->availableStock) }}</strong>
        </article>
    </section>

    <nav class="section-tabs" aria-label="Secciones de inventario" role="tablist">
        @foreach ([
            ['component' => 'manage-products', 'label' => 'Productos'],
            ['component' => 'manage-departments', 'label' => 'Departamentos'],
            ['component' => 'assign-products', 'label' => 'Asignar productos'],
            ['component' => 'department-lookup', 'label' => 'Consulta por clave'],
            ['component' => 'admin.user-manager', 'label' => 'Usuarios'],
            {{-- This menu entry activates the allow-listed Livewire chat component. --}}
            ['component' => 'ai-chat-assistant', 'label' => 'Asistente IA'],
        ] as $panel)
            <button
                type="button"
                class="btn btn-sm {{ $activePanel === $panel['component'] ? 'active' : '' }}"
                role="tab"
                aria-selected="{{ $activePanel === $panel['component'] ? 'true' : 'false' }}"
                wire:click="setPanel('{{ $panel['component'] }}')"
            >
                {{ $panel['label'] }}
            </button>
        @endforeach
    </nav>

    <section class="panel-region" role="tabpanel" aria-live="polite">
        <livewire:dynamic-component :component="$activePanel" :key="$activePanel" />
    </section>
</main>