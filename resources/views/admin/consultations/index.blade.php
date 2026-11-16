@extends('layouts.app')

@section('title', 'Consultas')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $chips = ['hoy' => ['Hoy', 'bi-calendar-day', $counts['hoy']], 'abiertas' => ['Abiertas', 'bi-pencil', $counts['abiertas']], 'todas' => ['Todas', 'bi-list-ul', null]];
    if ($mine) {
        $chips = ['hoy' => $chips['hoy'], 'mias' => ['Mis consultas', 'bi-person', null], 'abiertas' => $chips['abiertas'], 'todas' => $chips['todas']];
    }
@endphp
<div class="container-fluid">
    @include('admin.finance.partials.errors')

    <div class="page-header">
        <div>
            <h1><i class="bi bi-clipboard2-pulse"></i> Consultas</h1>
            <p class="page-subtitle">Atiende desde la cita o desde la ficha del paciente. Todo queda en su historia clínica.</p>
        </div>
    </div>

    @if($waiting->isNotEmpty() && $can('consultations.create'))
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-hourglass-split"></i> Esperando atención hoy</div>
            <div class="list-group list-group-flush">
                @foreach($waiting as $a)
                    <div class="list-group-item d-flex align-items-center gap-3 flex-wrap">
                        <span class="fw-bold tabular" style="min-width:3rem">{{ $a->starts_at->format('H:i') }}</span>
                        <div class="flex-fill min-w-0">
                            <div class="fw-semibold">@if($a->patient?->hasAlerts())<i class="bi bi-exclamation-triangle-fill text-danger"></i> @endif{{ $a->patient?->full_name }}</div>
                            <div class="small text-muted">{{ $a->title }} · {{ $a->professional?->full_name }}</div>
                        </div>
                        <span class="badge bg-{{ $a->status_color }}">{{ $a->status_label }}</span>
                        <form method="POST" action="{{ route('consultations.start') }}" class="m-0">
                            @csrf
                            <input type="hidden" name="appointment_id" value="{{ $a->id }}">
                            <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-play-fill"></i> Atender</button>
                        </form>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <nav class="filter-chips mb-3" aria-label="Filtros">
        @foreach($chips as $key => [$label, $icon, $count])
            <a href="{{ route('consultations.index', ['filter' => $key]) }}" class="filter-chip {{ $filter === $key ? 'active' : '' }}"><i class="bi {{ $icon }}"></i> {{ $label }} @if($count !== null)<span class="count">{{ $count }}</span>@endif</a>
        @endforeach
    </nav>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle table-stack">
                <thead><tr><th class="ps-3">Paciente</th><th>Fecha</th><th>Motivo</th><th>Profesional</th><th>Registro</th><th class="pe-3">Estado</th></tr></thead>
                <tbody>
                    @forelse($consultations as $c)
                        <tr class="row-link" onclick="location.href='{{ route('consultations.show', $c) }}'">
                            <td class="ps-3 stack-full">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="avatar">{{ $c->patient?->initials }}</span>
                                    <div><a href="{{ route('consultations.show', $c) }}" class="fw-semibold text-decoration-none text-reset">{{ $c->patient?->full_name }}</a><div class="small text-muted">{{ $c->code }}</div></div>
                                </div>
                            </td>
                            <td class="small text-nowrap" data-label="Fecha">{{ $c->started_at?->format('d/m/Y H:i') }}</td>
                            <td class="small" data-label="Motivo">{{ \Illuminate\Support\Str::limit($c->reason, 50) ?: '—' }}</td>
                            <td class="small" data-label="Profesional">{{ $c->professional?->full_name }}</td>
                            <td class="small text-muted" data-label="Registro">{{ $c->diagnoses_count }} dx · {{ $c->procedures_count }} proc.</td>
                            <td class="pe-3" data-label="Estado">
                                @if($c->isOpen())<span class="badge bg-warning text-dark">Abierta</span>@else<span class="badge bg-success-subtle text-success-emphasis"><i class="bi bi-lock-fill"></i> Cerrada</span>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="stack-full">
                            <div class="empty-state">
                                <div class="empty-icon"><i class="bi bi-clipboard2-pulse"></i></div>
                                <h5 class="mb-1">{{ $filter === 'hoy' ? 'Sin consultas hoy' : 'Sin consultas' }}</h5>
                                <p class="small mb-0">Para atender, abre la cita del paciente (o su ficha) y toca «Atender».</p>
                            </div>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3 d-flex justify-content-center">{{ $consultations->links() }}</div>
</div>
@endsection
