@extends('layouts.app')

@section('title', 'Hoy y sala de espera')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $canEdit = $can('appointments.edit');
    $isToday = $date->isToday();
    $board = [
        'pending' => ['Por llegar', 'bi-calendar-check', '#64748b', ['en_espera' => ['Llegó', 'bi-door-open', 'warning'], 'confirmada' => ['Confirmar', 'bi-check2', 'light']]],
        'waiting' => ['En sala de espera', 'bi-hourglass-split', '#d97706', ['en_atencion' => ['Pasar', 'bi-activity', 'info']]],
        'attending' => ['En atención', 'bi-activity', '#0891b2', ['atendida' => ['Terminar', 'bi-check2-all', 'success']]],
        'done' => ['Atendidas', 'bi-check2-all', '#059669', []],
    ];
    $total = collect($columns)->except('missed')->sum(fn ($c) => $c->count());
    $clinical = $tenantCompany->planAllows('historia_clinica') && $can('consultations.create');
@endphp
<div class="container-fluid">
    @include('admin.finance.partials.errors')

    <div class="page-header">
        <div>
            <h1><i class="bi bi-hourglass-split"></i> {{ $isToday ? 'Hoy' : ucfirst($date->locale('es')->translatedFormat('l j \d\e F')) }}</h1>
            <p class="page-subtitle">{{ $total }} {{ $total === 1 ? 'cita' : 'citas' }} · marca la llegada, pasa a atención y termina, sin salir de esta pantalla.</p>
        </div>
        <div class="page-actions">
            <div class="btn-group">
                <a href="{{ route('appointments.today', array_filter(['date' => $date->copy()->subDay()->toDateString(), 'professional' => $professional])) }}" class="btn btn-light" aria-label="Día anterior"><i class="bi bi-chevron-left"></i></a>
                @unless($isToday)<a href="{{ route('appointments.today', array_filter(['professional' => $professional])) }}" class="btn btn-light">Hoy</a>@endunless
                <a href="{{ route('appointments.today', array_filter(['date' => $date->copy()->addDay()->toDateString(), 'professional' => $professional])) }}" class="btn btn-light" aria-label="Día siguiente"><i class="bi bi-chevron-right"></i></a>
            </div>
            <a href="{{ route('appointments.index', ['date' => $date->toDateString()]) }}" class="btn btn-light"><i class="bi bi-calendar-week"></i> Calendario</a>
            @if($can('appointments.create'))
                <a href="{{ route('appointments.create', ['start' => $date->toDateString() . 'T' . now()->addMinutes(15 - now()->minute % 15)->format('H:i')]) }}" class="btn btn-primary"><i class="bi bi-calendar-plus"></i> Nueva cita</a>
            @endif
        </div>
    </div>

    @if($professionals->count() > 1)
        <nav class="filter-chips mb-3" aria-label="Profesional">
            <a href="{{ route('appointments.today', array_filter(['date' => $isToday ? null : $date->toDateString()])) }}" class="filter-chip {{ ! $professional ? 'active' : '' }}">Todos</a>
            @foreach($professionals as $pro)
                <a href="{{ route('appointments.today', array_filter(['date' => $isToday ? null : $date->toDateString(), 'professional' => $pro->id])) }}" class="filter-chip {{ (string) $professional === (string) $pro->id ? 'active' : '' }}">
                    <span class="d-inline-block rounded-circle" style="width:.6rem;height:.6rem;background: {{ $pro->color }}"></span> {{ $pro->full_name }}
                </a>
            @endforeach
        </nav>
    @endif

    <div class="board">
        @foreach($board as $key => [$title, $icon, $tone, $moves])
            <section class="board-col" style="--tone: {{ $tone }}">
                <header class="board-head"><i class="bi {{ $icon }}"></i> {{ $title }} <span class="count">{{ $columns[$key]->count() }}</span></header>
                <div class="board-body">
                    @forelse($columns[$key] as $a)
                        @php $wait = $a->waitingMinutes(); $alerts = $a->patient?->medicalAlerts() ?? []; @endphp
                        <article class="board-card {{ $key === 'pending' && $isToday && $a->starts_at->lt(now()->subMinutes(10)) ? 'is-late' : '' }}" style="--pro: {{ $a->professional?->color }}">
                            <a href="{{ route('appointments.show', $a) }}" class="stretched-link" aria-label="Ver cita de {{ $a->patient?->full_name }}"></a>
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div class="fw-bold tabular">{{ $a->starts_at->format('H:i') }}</div>
                                @if($wait !== null)
                                    <span class="badge {{ $wait >= 20 ? 'bg-danger' : 'bg-warning text-dark' }}" title="Tiempo en sala de espera"><i class="bi bi-stopwatch"></i> {{ $wait }} min</span>
                                @else
                                    <span class="d-flex gap-1">
                                        @if($a->status === 'confirmada')<span class="badge bg-primary-subtle text-primary-emphasis">Confirmada</span>@endif
                                        @if($key === 'pending' && $isToday && $a->starts_at->lt(now()->subMinutes(10)))<span class="badge bg-danger-subtle text-danger-emphasis">Atrasada</span>@endif
                                    </span>
                                @endif
                            </div>
                            <div class="fw-semibold mt-1">
                                @if($alerts)<i class="bi bi-exclamation-triangle-fill text-danger" title="{{ collect($alerts)->pluck('label')->implode(' · ') }}"></i>@endif
                                {{ $a->patient?->full_name }}
                            </div>
                            <div class="small text-muted">{{ $a->title }}</div>
                            <div class="small mt-1"><span class="d-inline-block rounded-circle me-1" style="width:.55rem;height:.55rem;background: {{ $a->professional?->color }}"></span>{{ $a->professional?->full_name }}</div>
                            @if($clinical && in_array($key, ['waiting', 'attending'], true))
                                <div class="mt-2 position-relative" style="z-index: 2">
                                    @if($a->consultation)
                                        <a href="{{ route('consultations.show', $a->consultation) }}" class="btn btn-sm btn-primary"><i class="bi bi-clipboard2-pulse"></i> Continuar consulta</a>
                                    @else
                                        <form method="POST" action="{{ route('consultations.start') }}" class="d-inline">
                                            @csrf
                                            <input type="hidden" name="appointment_id" value="{{ $a->id }}">
                                            <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-play-fill"></i> Atender</button>
                                        </form>
                                    @endif
                                </div>
                            @endif
                            @if($canEdit && $moves)
                                <div class="d-flex flex-wrap gap-1 mt-2 position-relative" style="z-index: 2">
                                    @foreach($moves as $status => [$label, $btnIcon, $btnTone])
                                        @continue(! $a->canMoveTo($status))
                                        <form method="POST" action="{{ route('appointments.status', $a) }}">
                                            @csrf
                                            <input type="hidden" name="status" value="{{ $status }}">
                                            <button class="btn btn-sm btn-{{ $btnTone }}" type="submit"><i class="bi {{ $btnIcon }}"></i> {{ $label }}</button>
                                        </form>
                                    @endforeach
                                    @if($key === 'pending' && $isToday && $a->starts_at->lt(now()) && $a->canMoveTo('no_asistio'))
                                        <form method="POST" action="{{ route('appointments.status', $a) }}">
                                            @csrf
                                            <input type="hidden" name="status" value="no_asistio">
                                            <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-person-x"></i> No vino</button>
                                        </form>
                                    @endif
                                </div>
                            @endif
                        </article>
                    @empty
                        <div class="board-empty">—</div>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>

    @if($columns['missed']->isNotEmpty())
        <details class="card mt-3">
            <summary class="card-body py-2 fw-semibold small">Canceladas y no asistidas ({{ $columns['missed']->count() }})</summary>
            <ul class="list-group list-group-flush small">
                @foreach($columns['missed'] as $a)
                    <li class="list-group-item d-flex justify-content-between">
                        <a href="{{ route('appointments.show', $a) }}" class="text-decoration-none text-reset">{{ $a->starts_at->format('H:i') }} · {{ $a->patient?->full_name }} · {{ $a->title }}</a>
                        <span class="badge bg-{{ $a->status_color }}">{{ $a->status_label }}</span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</div>

@push('styles')
<style>
    .board { display: grid; grid-template-columns: repeat(4, minmax(220px, 1fr)); gap: 1rem; overflow-x: auto; padding-bottom: .25rem; }
    .board-col { background: #eef3f4; border-radius: var(--dx-radius); display: flex; flex-direction: column; min-height: 260px; }
    .board-head { padding: .7rem .9rem; font-weight: 700; font-size: .88rem; color: var(--tone); display: flex; align-items: center; gap: .45rem; border-bottom: 3px solid var(--tone); }
    .board-head .count { margin-left: auto; background: #fff; color: var(--dx-text); border-radius: 999px; padding: 0 .55rem; font-size: .75rem; }
    .board-body { padding: .6rem; display: flex; flex-direction: column; gap: .6rem; }
    .board-card { position: relative; background: #fff; border-radius: 12px; padding: .7rem .8rem; border: 1px solid var(--dx-border); border-left: 4px solid var(--pro); box-shadow: var(--dx-shadow); }
    .board-card:hover { box-shadow: var(--dx-shadow-hover); }
    .board-card.is-late { border-color: #f5c2c7; border-left-color: #dc3545; }
    .board-empty { text-align: center; color: #9aaab0; padding: 1.5rem 0; }
    @media (max-width: 991.98px) { .board { grid-template-columns: repeat(4, 78%); scroll-snap-type: x mandatory; } .board-col { scroll-snap-align: start; } }
</style>
@endpush
@endsection
