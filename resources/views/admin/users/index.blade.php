@extends('layouts.app')

@section('title', 'Usuarios')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
@endphp
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h1><i class="bi bi-person-gear"></i> Usuarios</h1>
            <p class="page-subtitle">Quién puede entrar al sistema{{ $tenantCompany ? ' de ' . $tenantCompany->displayName() : '' }} y con qué rol.</p>
        </div>
        @if($can('users.create'))
            <div class="page-actions">
                <a href="{{ route('users.create') }}" class="btn btn-primary"><i class="bi bi-person-plus"></i> Nuevo usuario</a>
            </div>
        @endif
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle table-stack">
                <thead><tr><th class="ps-3">Usuario</th><th>Teléfono</th><th>Rol</th><th>Estado</th><th class="text-end pe-3"></th></tr></thead>
                <tbody>
                    @forelse($users as $user)
                        @php
                            $role = $user->is_super_admin ? null : ($tenantCompany ? $tenantCompany->getRoleForUser($user)?->name : null);
                            $initials = collect(preg_split('/[\s._@-]+/', $user->name))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
                        @endphp
                        <tr>
                            <td class="ps-3 stack-full">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="avatar">{{ $initials }}</span>
                                    <div class="min-w-0">
                                        <div class="fw-semibold">{{ $user->name }} @if($user->id === auth()->id())<span class="badge bg-light text-dark border">Tú</span>@endif</div>
                                        <div class="small text-muted text-truncate">{{ $user->email }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="small" data-label="Teléfono">{{ $user->phone ?: '—' }}</td>
                            <td data-label="Rol">
                                @if($user->is_super_admin)
                                    <span class="badge bg-dark">Superadmin</span>
                                @else
                                    <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">{{ $role ?? 'Usuario' }}</span>
                                @endif
                            </td>
                            <td data-label="Estado"><span class="badge {{ $user->active ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary' }}">{{ $user->active ? 'Activo' : 'Inactivo' }}</span></td>
                            <td class="text-end pe-3 text-nowrap">
                                @if($can('users.edit'))
                                    <a href="{{ route('users.edit', $user) }}" class="btn btn-sm btn-light" title="Editar"><i class="bi bi-pencil"></i></a>
                                @endif
                                @if($can('users.delete') && auth()->id() !== $user->id)
                                    <form action="{{ route('users.destroy', $user) }}" method="POST" class="d-inline" onsubmit="return confirm('¿Quitar el acceso de {{ e($user->name) }}?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-light text-danger" title="Quitar acceso"><i class="bi bi-person-x"></i></button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="stack-full">
                            <div class="empty-state">
                                <div class="empty-icon"><i class="bi bi-person-gear"></i></div>
                                <h5 class="mb-1">Sin usuarios</h5>
                                <p class="small mb-0">Crea usuarios para que tu equipo entre al sistema.</p>
                            </div>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3 d-flex justify-content-center">{{ $users->links() }}</div>
</div>
@endsection
