@extends('layouts.app')

@section('title', 'Roles')

@section('page')
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h1><i class="bi bi-shield-lock"></i> Roles</h1>
            <p class="page-subtitle">Catálogo global: cada clínica asigna estos roles a su equipo a través de los cargos.</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('roles.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nuevo rol</a>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle table-stack">
                <thead><tr><th class="ps-3">Rol</th><th>Descripción</th><th class="text-center">Permisos</th><th class="text-end pe-3"></th></tr></thead>
                <tbody>
                    @forelse($roles as $role)
                        <tr>
                            <td class="ps-3 stack-full">
                                <a href="{{ route('roles.show', $role) }}" class="fw-semibold text-decoration-none text-reset">{{ $role->name }}</a>
                                @if($role->isProtected())<i class="bi bi-lock-fill text-muted small" title="Rol del sistema"></i>@endif
                                <div><code class="small">{{ $role->slug }}</code></div>
                            </td>
                            <td class="small text-muted" data-label="Descripción">{{ $role->description ?: '—' }}</td>
                            <td class="text-center" data-label="Permisos"><span class="badge bg-light text-dark border">{{ $role->permissions->count() }}</span></td>
                            <td class="text-end pe-3 text-nowrap">
                                <a href="{{ route('roles.show', $role) }}" class="btn btn-sm btn-light" title="Ver"><i class="bi bi-eye"></i></a>
                                <a href="{{ route('roles.edit', $role) }}" class="btn btn-sm btn-light" title="Editar"><i class="bi bi-pencil"></i></a>
                                @unless($role->isProtected())
                                    <form action="{{ route('roles.destroy', $role) }}" method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar el rol {{ e($role->name) }}?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-light text-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="stack-full"><div class="empty-state"><div class="empty-icon"><i class="bi bi-shield-lock"></i></div><h5>Sin roles</h5></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3 d-flex justify-content-center">{{ $roles->links() }}</div>
</div>
@endsection
