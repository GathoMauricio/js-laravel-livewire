{{-- Mounts the dynamic Livewire dashboard inside the shared application shell. --}}
@extends('layouts.app')

@section('title', 'Inventario central')

@section('content')
    <livewire:inventory-dashboard />
@endsection
