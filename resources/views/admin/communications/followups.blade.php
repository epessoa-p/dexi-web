@extends('layouts.app')

@section('title', 'Seguimientos y controles')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $filters = ['pendiente' => ['Pendientes', 'bi-hourglass-split'], 'hecho' => ['Hechos', 'bi-check2-all'], 'descartado' => ['Descartados', 'bi-x-circle']];
@endphp
<div class="container-fluid" style="max-width: 1100px;">
    @include('admin.finance.partials.errors')

    <div class="page-header">
        <div>
            <h1><i class="bi bi-arrow-repeat"></i> Seguimientos y controles</h1>
            <p class="page-subtitle">Controles programados por paciente. El día que vencen aparecen en <a href="{{ route('communications.index', ['tipo' => 'seguimiento']) }}">Para enviar hoy</a> con el mensaje listo.</p>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <nav class="filter-chips" aria-label="Estado">
            @foreach($filters as $key => [$label, $icon])
                <a href="{{ route('communications.followups', array_filter(['estado' => $key, 'q' => request('q')])) }}" class="filter-chip {{ $filter === $key ? 'active' : '' }}">
                    <i class="bi {{ $icon }}"></i> {{ $label }} <span class="count">{{ $counts[$key] ?? 0 }}</span>
                </a>
            @endforeach
        </nav>
        <form method="GET" class="ms-auto d-flex gap-2">
            <input type="hidden" name="estado" value="{{ $filter }}">
            <input type="search" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Buscar paciente…" aria-label="Buscar paciente">
        </form>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle table-stack mb-0">
                <thead><tr><th class="ps-3">Paciente</th><th>Motivo</th><th>Fecha</th><th>Programó</th><th class="text-end pe-3"></th></tr></thead>
                <tbody>
                    @forelse($followups as $f)
                        @php $p = $f->patient; @endphp
                        <tr>
                            <td class="ps-3 stack-full">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="avatar">{{ $p->initials }}</span>
                                    <a href="{{ route('patients.show', [$p, 'tab' => 'recordatorios']) }}" class="fw-semibold text-decoration-none text-reset">{{ $p->full_name }}</a>
                                </div>
                            </td>
                            <td data-label="Motivo">{{ $f->reason }}@if($f->notes)<div class="small text-muted">{{ $f->notes }}</div>@endif</td>
                            <td data-label="Fecha" class="{{ $f->isOverdue() ? 'text-danger fw-semibold' : '' }}">
                                {{ $f->due_date->format('d/m/Y') }}
                                @if($f->isPending())<div class="small {{ $f->isOverdue() ? '' : 'text-muted' }}">{{ $f->due_date->isToday() ? 'hoy' : $f->due_date->locale('es')->diffForHumans(today(), ['syntax' => \Carbon\CarbonInterface::DIFF_RELATIVE_TO_NOW, 'parts' => 1]) }}</div>@endif
                            </td>
                            <td data-label="Programó" class="small text-muted">{{ $f->creator?->name ?? '—' }}<div>{{ $f->created_at->format('d/m/Y') }}</div></td>
                            <td class="text-end pe-3 stack-full">
                                @if($can('communications.create'))
                                    <form method="POST" action="{{ route('communications.followups.close', $f) }}" class="d-inline-flex gap-1 m-0">
                                        @csrf
                                        @if($f->isPending())
                                            <button class="btn btn-sm btn-light" name="status" value="hecho"><i class="bi bi-check2"></i> Hecho</button>
                                            <button class="btn btn-sm btn-light text-muted" name="status" value="descartado" title="Descartar"><i class="bi bi-x"></i></button>
                                        @else
                                            <button class="btn btn-sm btn-light" name="status" value="pendiente"><i class="bi bi-arrow-counterclockwise"></i> Reabrir</button>
                                        @endif
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="stack-full">
                            <div class="empty-state">
                                <div class="empty-icon"><i class="bi bi-arrow-repeat"></i></div>
                                <h5 class="mb-1">Sin seguimientos {{ mb_strtolower($filters[$filter][0]) }}</h5>
                                <p class="small mb-0">Se programan desde la ficha del paciente, pestaña <strong>Recordatorios</strong>: «Control en 6 meses», «Retiro de puntos»…</p>
                            </div>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $followups->links() }}</div>
</div>
@include('admin.finance.partials.styles')
@endsection
