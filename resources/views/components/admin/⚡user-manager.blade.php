<?php

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|email|max:255|unique:users,email')]
    public string $email = '';

    #[Validate('required|string|min:8')]
    public string $password = '';

    public function saveUser(): void
    {
        $validated = $this->validate();

        User::query()->create($validated);

        $this->reset(['name', 'email', 'password']);
        unset($this->users);

        session()->flash('status', 'Usuario creado correctamente.');
    }

    public function deleteUser(int $userId): void
    {
        User::query()->findOrFail($userId)->delete();

        unset($this->users);

        session()->flash('status', 'Usuario eliminado correctamente.');
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function users(): Collection
    {
        return User::query()->orderBy('name')->get();
    }
};
?>

<section aria-labelledby="users-title">
    <div class="panel-heading">
        <div>
            <span class="eyebrow">Administración</span>
            <h2 id="users-title">Usuarios</h2>
            <p>Crea cuentas y administra los usuarios registrados.</p>
        </div>
    </div>

    @if (session()->has('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    <div class="row g-4">
        <div class="col-12 col-lg-4">
            <form class="form-panel" wire:submit="saveUser">
                <h3>Crear usuario</h3>
                <div class="mb-3">
                    <label class="form-label" for="user-name">Nombre</label>
                    <input id="user-name" class="form-control" type="text" maxlength="255" wire:model="name" autocomplete="name">
                    @error('name') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>
                <div class="mb-3">
                    <label class="form-label" for="user-email">Correo electrónico</label>
                    <input id="user-email" class="form-control" type="email" maxlength="255" wire:model="email" autocomplete="email">
                    @error('email') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>
                <div class="mb-4">
                    <label class="form-label" for="user-password">Contraseña</label>
                    <input id="user-password" class="form-control" type="password" wire:model="password" autocomplete="new-password">
                    @error('password') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>
                <button class="btn btn-primary w-100" type="submit" wire:loading.attr="disabled" wire:target="saveUser">
                    <span wire:loading.remove wire:target="saveUser">Crear usuario</span>
                    <span wire:loading wire:target="saveUser">Guardando...</span>
                </button>
            </form>
        </div>

        <div class="col-12 col-lg-8">
            <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
                <div>
                    <h3 class="h6 mb-1 fw-bold">Usuarios registrados</h3>
                    <span class="small text-secondary">{{ $this->users->count() }} usuarios</span>
                </div>
            </div>

            <div class="data-table-wrap">
                <table class="table table-hover">
                    <thead>
                        <tr><th>Nombre</th><th>Correo electrónico</th><th class="text-end">Acciones</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($this->users as $user)
                            <tr wire:key="user-{{ $user->id }}">
                                <td>{{ $user->name }}</td>
                                <td>{{ $user->email }}</td>
                                <td class="text-end">
                                    <button class="btn btn-outline-danger btn-sm" type="button" wire:click="deleteUser({{ $user->id }})" wire:confirm="¿Eliminar a {{ $user->name }}?" wire:loading.attr="disabled" wire:target="deleteUser({{ $user->id }})">
                                        Eliminar
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3"><div class="empty-state"><strong>No hay usuarios</strong>Crea un usuario para que aparezca en esta lista.</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>