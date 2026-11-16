@extends('layouts.app')

@section('title', 'Cargos y permisos')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $showCompany = ($globalMode ?? false);
@endphp
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h1><i class="bi bi-briefcase"></i> Cargos y permisos</h1>
            <p class="page-subtitle">Cada cargo (odontólogo, asistente, recepción…) hereda los permisos de su rol.</p>
        </div>
        @if($can('cargos.create'))
            <div class="page-actions">
                <a href="{{ route('cargos.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nuevo cargo</a>
            </div>
        @endif
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle table-stack">
                <thead><tr><th class="ps-3">Cargo</th><th>Rol y permisos</th>@if($showCompany)<th>Clínica</th>@endif<th>Estado</th><th class="text-end pe-3"></th></tr></thead>
                <tbody>
                    @forelse($cargos as $cargo)
                        <tr>
                            <td class="ps-3 stack-full">
                                <div class="fw-semibold">{{ $cargo->name }}</div>
                                <small class="text-muted">{{ $cargo->description ?: 'Sin descripción' }}</small>
                            </td>
                            <td data-label="Rol"><span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">{{ $cargo->role?->name ?? '—' }}</span></td>
                            @if($showCompany)<td class="small" data-label="Clínica">{{ $cargo->company?->name ?? '—' }}</td>@endif
                            <td data-label="Estado"><span class="badge {{ $cargo->active ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary' }}">{{ $cargo->active ? 'Activo' : 'Inactivo' }}</span></td>
                            <td class="text-end pe-3 text-nowrap">
                                @if($can('cargos.edit'))
                                    <a href="{{ route('cargos.edit', $cargo) }}" class="btn btn-sm btn-light" title="Editar"><i class="bi bi-pencil"></i></a>
                                @endif
                                @if($can('cargos.delete'))
                                    <form action="{{ route('cargos.destroy', $cargo) }}" method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar el cargo {{ e($cargo->name) }}?')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-light text-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="stack-full">
                            <div class="empty-state">
                                <div class="empty-icon"><i class="bi bi-briefcase"></i></div>
                                <h5 class="mb-1">Sin cargos todavía</h5>
                                <p class="small mb-3">Crea los cargos de tu equipo para asignarles permisos.</p>
                                @if($can('cargos.create'))<a href="{{ route('cargos.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nuevo cargo</a>@endif
                            </div>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3 d-flex justify-content-center">{{ $cargos->links() }}</div>
</div>
@endsection
