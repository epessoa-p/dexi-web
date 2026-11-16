@extends('layouts.app')

@section('title', 'Cajas')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
@endphp
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h1><i class="bi bi-safe"></i> Cajas</h1>
            <p class="page-subtitle">Los cajones del mostrador. Los turnos (apertura, arqueo, cierre) se manejan en <a href="{{ route('cash.index') }}">Ventas y caja › Caja</a>.</p>
        </div>
        @if($can('cajas.create'))
            <div class="page-actions">
                <a href="{{ route('cajas.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nueva caja</a>
            </div>
        @endif
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle table-stack">
                <thead><tr><th class="ps-3">Caja</th><th>Consultorio</th><th class="text-end">Fondo sugerido</th><th>Turno</th><th>Estado</th><th class="text-end pe-3"></th></tr></thead>
                <tbody>
                    @forelse($cajas as $caja)
                        @php $open = $caja->openSession(); @endphp
                        <tr>
                            <td class="ps-3 stack-full">
                                <div class="fw-semibold">{{ $caja->name }}</div>
                                <small class="text-muted">{{ $caja->code ? 'Código ' . $caja->code : ($caja->description ?: 'Sin descripción') }}</small>
                            </td>
                            <td class="small" data-label="Consultorio">{{ $caja->branch?->name ?? '—' }}</td>
                            <td class="text-end tabular" data-label="Fondo sugerido">{{ $money($caja->balance) }}</td>
                            <td data-label="Turno">
                                @if($open)
                                    <a href="{{ route('cash.show', $open) }}" class="badge bg-success text-decoration-none"><i class="bi bi-unlock"></i> Abierta</a>
                                @else
                                    <span class="badge bg-light text-dark border"><i class="bi bi-lock"></i> Cerrada</span>
                                @endif
                            </td>
                            <td data-label="Estado"><span class="badge {{ $caja->active ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary' }}">{{ $caja->active ? 'Activa' : 'Inactiva' }}</span></td>
                            <td class="text-end pe-3 text-nowrap">
                                @if($can('cajas.edit'))
                                    <a href="{{ route('cajas.edit', $caja) }}" class="btn btn-sm btn-light" title="Editar"><i class="bi bi-pencil"></i></a>
                                @endif
                                @if($can('cajas.delete'))
                                    <form action="{{ route('cajas.destroy', $caja) }}" method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar la caja {{ e($caja->name) }}?')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-light text-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="stack-full">
                            <div class="empty-state">
                                <div class="empty-icon"><i class="bi bi-safe"></i></div>
                                <h5 class="mb-1">Todavía no hay cajas</h5>
                                <p class="small mb-3">Crea la caja del mostrador para abrir turnos, cobrar y arquear.</p>
                                @if($can('cajas.create'))<a href="{{ route('cajas.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nueva caja</a>@endif
                            </div>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
