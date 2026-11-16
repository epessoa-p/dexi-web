@extends('layouts.app')

@section('title', 'Consultorios')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $showCompany = ($globalMode ?? false);
@endphp
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h1><i class="bi bi-diagram-2"></i> Consultorios</h1>
            <p class="page-subtitle">Las sedes de la clínica. Cada una puede tener su caja y su agenda.</p>
        </div>
        @if($can('branches.create'))
            <div class="page-actions">
                <a href="{{ route('branches.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nuevo consultorio</a>
            </div>
        @endif
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle table-stack">
                <thead><tr><th class="ps-3">Consultorio</th>@if($showCompany)<th>Clínica</th>@endif<th>Encargado</th><th>Estado</th><th class="text-end pe-3"></th></tr></thead>
                <tbody>
                    @forelse($branches as $branch)
                        <tr>
                            <td class="ps-3 stack-full">
                                <a href="{{ route('branches.show', $branch) }}" class="fw-semibold text-decoration-none text-reset">{{ $branch->name }}</a>
                                @if($branch->code)<div class="small text-muted">Código {{ $branch->code }}</div>@endif
                            </td>
                            @if($showCompany)<td class="small" data-label="Clínica">{{ $branch->company?->name ?: '—' }}</td>@endif
                            <td class="small" data-label="Encargado">{{ $branch->manager_name ?: '—' }}</td>
                            <td data-label="Estado"><span class="badge {{ $branch->active ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary' }}">{{ $branch->active ? 'Activo' : 'Inactivo' }}</span></td>
                            <td class="text-end pe-3 text-nowrap">
                                <a href="{{ route('branches.show', $branch) }}" class="btn btn-sm btn-light" title="Ver"><i class="bi bi-eye"></i></a>
                                @if($can('branches.edit'))
                                    <a href="{{ route('branches.edit', $branch) }}" class="btn btn-sm btn-light" title="Editar"><i class="bi bi-pencil"></i></a>
                                @endif
                                @if($can('branches.delete'))
                                    <form action="{{ route('branches.destroy', $branch) }}" method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar el consultorio {{ e($branch->name) }}?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-light text-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="stack-full">
                            <div class="empty-state">
                                <div class="empty-icon"><i class="bi bi-diagram-2"></i></div>
                                <h5 class="mb-1">Sin consultorios</h5>
                                <p class="small mb-3">Registra la sede donde atiendes.</p>
                                @if($can('branches.create'))<a href="{{ route('branches.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nuevo consultorio</a>@endif
                            </div>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3 d-flex justify-content-center">{{ $branches->links() }}</div>
</div>
@endsection
