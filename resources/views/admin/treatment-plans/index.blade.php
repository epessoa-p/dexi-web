@extends('layouts.app')

@section('title', 'Planes de tratamiento')

@section('page')
@php
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
    $filters = \App\Http\Controllers\Admin\TreatmentPlanController::FILTERS;
@endphp
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h1><i class="bi bi-clipboard2-check"></i> Planes de tratamiento</h1>
            <p class="page-subtitle">Presupuestos por pieza: se presentan, el paciente los aprueba y avanzan solos con cada consulta.</p>
        </div>
    </div>

    <form method="GET" class="mb-3" role="search">
        <input type="hidden" name="filter" value="{{ $filter }}">
        <div class="search-hero">
            <i class="bi bi-search"></i>
            <input type="search" name="q" value="{{ request('q') }}" class="form-control" placeholder="Buscar por paciente…" aria-label="Buscar por paciente">
        </div>
    </form>

    <nav class="filter-chips mb-3" aria-label="Filtros">
        @foreach($filters as $key => [$label, $icon])
            <a href="{{ route('treatment-plans.index', array_filter(['filter' => $key, 'q' => request('q')])) }}" class="filter-chip {{ $filter === $key ? 'active' : '' }}">
                <i class="bi {{ $icon }}"></i> {{ $label }} @isset($counts[$key])<span class="count">{{ $counts[$key] }}</span>@endisset
            </a>
        @endforeach
    </nav>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle table-stack">
                <thead><tr><th class="ps-3">Paciente</th><th>Plan</th><th>Avance</th><th class="text-end">Total</th><th class="pe-3">Estado</th></tr></thead>
                <tbody>
                    @forelse($plans as $plan)
                        @php $t = $plan->totals(); @endphp
                        <tr class="row-link" onclick="location.href='{{ route('treatment-plans.show', $plan) }}'">
                            <td class="ps-3 stack-full">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="avatar">{{ $plan->patient?->initials }}</span>
                                    <div><a href="{{ route('treatment-plans.show', $plan) }}" class="fw-semibold text-decoration-none text-reset">{{ $plan->patient?->full_name }}</a>
                                        <div class="small text-muted">{{ $plan->code }} · {{ $plan->updated_at->diffForHumans() }}</div></div>
                                </div>
                            </td>
                            <td class="small" data-label="Plan">{{ $plan->title }}<div class="text-muted">{{ $t['items'] }} {{ $t['items'] === 1 ? 'procedimiento' : 'procedimientos' }}</div></td>
                            <td data-label="Avance" style="min-width: 140px">
                                @if(in_array($plan->status, ['aprobado', 'terminado'], true))
                                    <div class="progress" style="height: 7px" role="progressbar" aria-valuenow="{{ $t['progress'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="Avance">
                                        <div class="progress-bar bg-success" style="width: {{ $t['progress'] }}%"></div>
                                    </div>
                                    <div class="small text-muted">{{ $t['items_done'] }} de {{ $t['items'] }}</div>
                                @else
                                    <span class="small text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end fw-semibold tabular" data-label="Total">{{ $money($t['total']) }}</td>
                            <td class="pe-3" data-label="Estado"><span class="badge bg-{{ $plan->status_color }}"><i class="bi {{ $plan->status_icon }}"></i> {{ $plan->status_label }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="stack-full">
                            <div class="empty-state">
                                <div class="empty-icon"><i class="bi bi-clipboard2-check"></i></div>
                                <h5 class="mb-1">Sin planes aquí</h5>
                                <p class="small mb-0">Se crean desde la ficha del paciente o desde la consulta («Plan de tratamiento»), a partir de los hallazgos del odontograma.</p>
                            </div>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3 d-flex justify-content-center">{{ $plans->links() }}</div>
</div>
@endsection
