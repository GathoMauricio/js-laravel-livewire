{{-- Shared document shell for the inventory dashboard and Livewire assets. --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>@yield('title', 'Inventario central')</title>
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body>
        <div class="app-shell">
            <header class="shell-topbar">
                <a class="brand-mark" href="{{ route('inventory') }}" aria-label="Inicio de inventario">IC</a>
                <div class="brand-copy">
                    <small>Gestión institucional</small>
                    <strong>Inventario central</strong>
                </div>
                <div class="system-state" aria-label="Sistema operativo">
                    <span class="state-dot" aria-hidden="true"></span>
                    <span>Operativo</span>
                </div>
            </header>
            <div class="workspace">
                @yield('content')
            </div>
        </div>
        @livewireScripts
    </body>
</html>
