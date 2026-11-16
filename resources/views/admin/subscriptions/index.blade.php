@extends('layouts.app')

@section('title', 'Suscripciones')

@section('page')
@php
    $chips = [
        null => ['Todas', 'bi-list-ul', $counts['total']],
        'writable' => ['Al día / en prueba', 'bi-check-circle', $counts['writable']],
        'expiring' => ['Vencen en 30 días', 'bi-hourglass-split', $counts['expiring']],
        'lapsed' => ['Vencidas', 'bi-exclamation-circle', $counts['lapsed']],
        'blocked' => ['Suspendidas / canceladas', 'bi-slash-circle', null],
    ];
@endphp
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h1><i class="bi bi-credit-card-2-front"></i> Suscripciones</h1>
            <p class="page-subtitle">Estado de la suscripción de cada clínica, con renovación en un clic.</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('subscriptions.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Asignar suscripción</a>
        </div>
    </div>

    <div class="row g-3 mb-3">
        @foreach([
            ['Total', $counts['total'], 'bi-hospital', '#0891b2'],
            ['Al día / en prueba', $counts['writable'], 'bi-check-circle', '#059669'],
            ['Vencen en 30 días', $counts['expiring'], 'bi-hourglass-split', '#ea580c'],
            ['Vencidas (incl. en gracia)', $counts['lapsed'], 'bi-exclamation-circle', '#dc3545'],
        ] as [$label, $value, $icon, $tone])
            <div class="col-6 col-xl-3">
                <div class="card kpi-card">
                    <div class="kpi-icon" style="--tone: {{ $tone }}"><i class="bi {{ $icon }}"></i></div>
                    <div><div class="kpi-label">{{ $label }}</div><div class="kpi-value">{{ $value }}</div></div>
                </div>
            </div>
        @endforeach
    </div>

    <nav class="filter-chips mb-3" aria-label="Filtros">
        @foreach($chips as $key => [$label, $icon, $count])
            <a href="{{ route('subscriptions.index', array_filter(['filter' => $key])) }}" class="filter-chip {{ (string) $filter === (string) $key ? 'active' : '' }}">
                <i class="bi {{ $icon }}"></i> {{ $label }} @if($count !== null)<span class="count">{{ $count }}</span>@endif
            </a>
        @endforeach
    </nav>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle table-stack">
                <thead><tr><th class="ps-3">Clínica</th><th>Plan</th><th>Estado</th><th>Inicio</th><th>Vence</th><th class="text-end pe-3"></th></tr></thead>
                <tbody>
                    @forelse($subscriptions as $subscription)
                        @php $daysLeft = $subscription->daysRemaining(); @endphp
                        <tr>
                            <td class="ps-3 stack-full">
                                <a href="{{ route('subscriptions.show', $subscription) }}" class="fw-semibold text-decoration-none text-reset">{{ $subscription->company?->name ?? '—' }}</a>
                                <div class="small text-muted tabular">{{ $subscription->currency }} {{ number_format($subscription->price, 2) }}</div>
                            </td>
                            <td class="small" data-label="Plan">{{ $subscription->plan?->name ?? '—' }}</td>
                            <td data-label="Estado"><span class="badge bg-{{ $subscription->statusColor() }}">{{ $subscription->statusLabel() }}</span></td>
                            <td class="small" data-label="Inicio">{{ $subscription->starts_at?->format('d/m/Y') }}</td>
                            <td class="small" data-label="Vence">
                                @if($subscription->periodEnd())
                                    {{ $subscription->periodEnd()->format('d/m/Y') }}
                                    @if($subscription->allowsWrite() && $daysLeft !== null && $daysLeft <= 30)
                                        <div class="text-warning-emphasis">{{ $daysLeft }} {{ Str::plural('día', $daysLeft) }}</div>
                                    @endif
                                @else
                                    <span class="text-muted">Sin vencimiento</span>
                                @endif
                            </td>
                            <td class="text-end pe-3 text-nowrap">
                                <a href="{{ route('subscriptions.show', $subscription) }}" class="btn btn-sm btn-light" title="Ver"><i class="bi bi-eye"></i></a>
                                <a href="{{ route('subscriptions.edit', $subscription) }}" class="btn btn-sm btn-light" title="Editar"><i class="bi bi-pencil"></i></a>
                                <form action="{{ route('subscriptions.renew', $subscription) }}" method="POST" class="d-inline" onsubmit="return confirm('¿Renovar un período a {{ e($subscription->company?->name) }}?')">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-success" title="Renovar un período"><i class="bi bi-arrow-clockwise"></i> Renovar</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="stack-full">
                            <div class="empty-state">
                                <div class="empty-icon"><i class="bi bi-credit-card-2-front"></i></div>
                                <h5 class="mb-1">Nada por aquí</h5>
                                <p class="small mb-0">No hay suscripciones que coincidan con el filtro.</p>
                            </div>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3 d-flex justify-content-center">{{ $subscriptions->links() }}</div>
</div>
@endsection
