@extends('layouts.app')

@section('title', 'Detalle del consultorio')

@section('page')
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h1 class="mb-1">{{ $branch->name }}</h1>
            <p class="text-muted mb-0">Detalle general del consultorio.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('branches.edit', $branch) }}" class="btn btn-primary">Editar</a>
            <a href="{{ route('branches.index') }}" class="btn btn-outline-secondary">Volver</a>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">Información general</h6>
                    <p class="mb-1"><strong>Clínica:</strong> {{ $branch->company?->name ?: '-' }}</p>
                    <p class="mb-1"><strong>Código:</strong> {{ $branch->code ?: '-' }}</p>
                    <p class="mb-1"><strong>Encargado:</strong> {{ $branch->manager_name ?: '-' }}</p>
                    <p class="mb-1"><strong>Correo:</strong> {{ $branch->email ?: '-' }}</p>
                    <p class="mb-1"><strong>Teléfono:</strong> {{ $branch->phone ?: '-' }}</p>
                    <p class="mb-1"><strong>Dirección:</strong> {{ $branch->address ?: '-' }}</p>
                    <p class="mb-0">
                        <strong>Estado:</strong>
                        <span class="badge {{ $branch->active ? 'bg-success' : 'bg-secondary' }}">{{ $branch->active ? 'Activo' : 'Inactivo' }}</span>
                    </p>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">Cajas del consultorio</h6>
                    @forelse($branch->cajas as $caja)
                        <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                            <div>
                                <div class="fw-semibold">{{ $caja->name }}</div>
                                <small class="text-muted">{{ $caja->code ?: 'Sin código' }}</small>
                            </div>
                            <span class="badge {{ $caja->active ? 'bg-success' : 'bg-secondary' }}">{{ $caja->active ? 'Activa' : 'Inactiva' }}</span>
                        </div>
                    @empty
                        <p class="text-muted mb-0">Este consultorio todavía no tiene cajas registradas.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
