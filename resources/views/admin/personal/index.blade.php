@extends('layouts.app')

@section('title', 'Odontólogos y personal')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $showCompany = ($globalMode ?? false);
@endphp
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h1><i class="bi bi-person-vcard"></i> Odontólogos y personal</h1>
            <p class="page-subtitle">Tu equipo. Darle acceso al sistema es opcional.</p>
        </div>
        @if($can('personal.create'))
            <div class="page-actions">
                <a href="{{ route('personal.create') }}" class="btn btn-primary"><i class="bi bi-person-plus"></i> Agregar al equipo</a>
            </div>
        @endif
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle table-stack">
                <thead><tr><th class="ps-3">Nombre</th><th>Cargo</th><th>Acceso al sistema</th>@if($showCompany)<th>Clínica</th>@endif<th>Estado</th><th class="text-end pe-3"></th></tr></thead>
                <tbody>
                    @forelse($personals as $personal)
                        @php $initials = collect(explode(' ', $personal->full_name))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode(''); @endphp
                        <tr>
                            <td class="ps-3 stack-full">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="avatar" style="--tone:#4f46e5">{{ $initials }}</span>
                                    <div class="min-w-0">
                                        <div class="fw-semibold">{{ $personal->full_name }}</div>
                                        <small class="text-muted">{{ $personal->id_number ?: 'Sin documento' }}</small>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Cargo">
                                <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">{{ $personal->cargo?->name ?? '—' }}</span>
                            </td>
                            <td data-label="Acceso">
                                @if($personal->user)
                                    <span class="small"><i class="bi bi-check-circle-fill text-success"></i> {{ $personal->user->email }}</span>
                                @else
                                    <span class="small text-muted"><i class="bi bi-dash-circle"></i> Sin acceso</span>
                                @endif
                            </td>
                            @if($showCompany)<td class="small" data-label="Clínica">{{ $personal->company?->name ?? '—' }}</td>@endif
                            <td data-label="Estado"><span class="badge {{ $personal->active ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary' }}">{{ $personal->active ? 'Activo' : 'Inactivo' }}</span></td>
                            <td class="text-end pe-3 text-nowrap">
                                @if($can('personal.edit'))
                                    <a href="{{ route('personal.edit', $personal) }}" class="btn btn-sm btn-light" title="Editar"><i class="bi bi-pencil"></i></a>
                                @endif
                                @if($can('personal.delete'))
                                    <form action="{{ route('personal.destroy', $personal) }}" method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar a {{ e($personal->full_name) }} del equipo?')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-light text-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="stack-full">
                            <div class="empty-state">
                                <div class="empty-icon"><i class="bi bi-person-vcard"></i></div>
                                <h5 class="mb-1">Tu equipo está vacío</h5>
                                <p class="small mb-3">Agrega a los odontólogos, asistentes y recepción.</p>
                                @if($can('personal.create'))<a href="{{ route('personal.create') }}" class="btn btn-primary"><i class="bi bi-person-plus"></i> Agregar al equipo</a>@endif
                            </div>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3 d-flex justify-content-center">{{ $personals->links() }}</div>
</div>
@endsection
