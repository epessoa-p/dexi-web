@extends('layouts.app')

@section('title', 'Clínicas')

@section('page')
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h1><i class="bi bi-hospital"></i> Clínicas</h1>
            <p class="page-subtitle">Las clínicas que usan Dexi. «Entrar» te deja trabajar dentro de una, como su administrador.</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('companies.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nueva clínica</a>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle table-stack">
                <thead><tr><th class="ps-3">Clínica</th><th>NIT / RUC</th><th>Plan</th><th>Suscripción</th><th>Estado</th><th class="text-end pe-3"></th></tr></thead>
                <tbody>
                    @forelse($companies as $company)
                        @php $subscription = $company->subscription; @endphp
                        <tr>
                            <td class="ps-3 stack-full">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="avatar" style="--tone:#0891b2"><i class="bi bi-hospital"></i></span>
                                    <div class="min-w-0">
                                        <a href="{{ route('companies.show', $company) }}" class="fw-semibold text-decoration-none text-reset">{{ $company->name }}</a>
                                        @if($company->email)<div class="small text-muted text-truncate">{{ $company->email }}</div>@endif
                                    </div>
                                </div>
                            </td>
                            <td class="small" data-label="NIT / RUC">{{ $company->ruc ?? '—' }}</td>
                            <td class="small" data-label="Plan">{{ $subscription?->plan?->name ?? '—' }}</td>
                            <td data-label="Suscripción">
                                @if($subscription)
                                    <span class="badge bg-{{ $subscription->statusColor() }}">{{ $subscription->statusLabel() }}</span>
                                    @if($subscription->periodEnd())<div class="small text-muted">hasta {{ $subscription->periodEnd()->format('d/m/Y') }}</div>@endif
                                @else
                                    <span class="badge bg-secondary">Sin suscripción</span>
                                @endif
                            </td>
                            <td data-label="Estado"><span class="badge {{ $company->active ? 'bg-success-subtle text-success-emphasis' : 'bg-danger' }}">{{ $company->active ? 'Activa' : 'Inactiva' }}</span></td>
                            <td class="text-end pe-3 text-nowrap">
                                @if($company->active)
                                    <form action="{{ route('set-company', $company->id) }}" method="POST" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-primary" title="Trabajar dentro de esta clínica"><i class="bi bi-box-arrow-in-right"></i> Entrar</button>
                                    </form>
                                @endif
                                <a href="{{ route('companies.edit', $company) }}" class="btn btn-sm btn-light" title="Editar"><i class="bi bi-pencil"></i></a>
                                <form action="{{ route('companies.destroy', $company) }}" method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar la clínica {{ e($company->name) }}? Se borran todos sus datos.')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-light text-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="stack-full">
                            <div class="empty-state">
                                <div class="empty-icon"><i class="bi bi-hospital"></i></div>
                                <h5 class="mb-1">Aún no hay clínicas</h5>
                                <p class="small mb-3">Al crear una se le asigna su prueba y su administrador en un solo paso.</p>
                                <a href="{{ route('companies.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nueva clínica</a>
                            </div>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3 d-flex justify-content-center">{{ $companies->links() }}</div>
</div>
@endsection
