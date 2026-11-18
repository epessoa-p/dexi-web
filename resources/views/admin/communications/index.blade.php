@extends('layouts.app')

@section('title', 'Para enviar hoy')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $kinds = \App\Support\Communications::KINDS;
    $rows = $queue[$kind] ?? collect();
    $pendingTotal = collect($queue)->sum(fn ($r) => $r->whereNull('sent')->count());
    $isTomorrow = $remindFor->isTomorrow();
    $isToday = $remindFor->isToday();
@endphp
<div class="container-fluid" style="max-width: 1100px;">
    @include('admin.finance.partials.errors')

    <div class="page-header">
        <div>
            <h1><i class="bi bi-whatsapp"></i> Para enviar hoy</h1>
            <p class="page-subtitle">Toca <strong>Enviar</strong>: WhatsApp se abre con el mensaje listo y aquí queda registrado, para no escribirle dos veces.</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('communications.templates') }}" class="btn btn-light"><i class="bi bi-chat-square-text"></i> Mensajes</a>
            <a href="{{ route('communications.history') }}" class="btn btn-light"><i class="bi bi-clock-history"></i> Enviados</a>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-sm-4">
            <div class="card kpi-card"><div class="kpi-icon" style="--tone:#25a244"><i class="bi bi-send"></i></div>
                <div><div class="kpi-label">Por enviar</div><div class="kpi-value">{{ $pendingTotal }}</div><div class="kpi-hint">en todas las listas</div></div></div>
        </div>
        <div class="col-sm-4">
            <div class="card kpi-card"><div class="kpi-icon" style="--tone:#0E9F9A"><i class="bi bi-check2-all"></i></div>
                <div><div class="kpi-label">Enviados hoy</div><div class="kpi-value">{{ $sentToday }}</div><div class="kpi-hint"><a href="{{ route('communications.history') }}">ver historial</a></div></div></div>
        </div>
        <div class="col-sm-4">
            <div class="card kpi-card"><div class="kpi-icon" style="--tone:#4f46e5"><i class="bi bi-calendar2-week"></i></div>
                <div><div class="kpi-label">Controles próximos</div><div class="kpi-value">{{ $upcoming }}</div><div class="kpi-hint"><a href="{{ route('communications.followups') }}">ver seguimientos</a></div></div></div>
        </div>
    </div>

    <nav class="filter-chips mb-3" aria-label="Listas">
        @foreach($queue as $key => $list)
            @php $pending = $list->whereNull('sent')->count(); @endphp
            <a href="{{ route('communications.index', array_filter(['tipo' => $key, 'dia' => request('dia'), 'fecha' => request('fecha')])) }}" class="filter-chip {{ $kind === $key ? 'active' : '' }}">
                <i class="bi {{ $kinds[$key][1] }}"></i> {{ $kinds[$key][0] }}
                <span class="count" title="{{ $pending }} por enviar de {{ $list->count() }}">{{ $pending }}@if($list->count() !== $pending)/{{ $list->count() }}@endif</span>
            </a>
        @endforeach
    </nav>

    @if($kind === 'recordatorio')
        <form method="GET" class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <input type="hidden" name="tipo" value="recordatorio">
            <span class="small text-muted">Citas del</span>
            <a href="{{ route('communications.index', ['tipo' => 'recordatorio', 'dia' => 'hoy']) }}" class="btn btn-sm {{ $isToday ? 'btn-primary' : 'btn-light' }}">Hoy</a>
            <a href="{{ route('communications.index', ['tipo' => 'recordatorio']) }}" class="btn btn-sm {{ $isTomorrow ? 'btn-primary' : 'btn-light' }}">Mañana</a>
            <input type="date" name="fecha" value="{{ $remindFor->toDateString() }}" class="form-control form-control-sm w-auto" onchange="this.form.submit()" aria-label="Otra fecha">
            <span class="small fw-semibold">{{ ucfirst($remindFor->locale('es')->translatedFormat('l j \d\e F')) }}</span>
        </form>
    @endif

    <div class="card">
        @if($kind)
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi {{ $kinds[$kind][1] }}" style="color: {{ $kinds[$kind][2] }}"></i> {{ $kinds[$kind][0] }}
                <a href="{{ route('communications.templates') }}#t-{{ $kind }}" class="ms-auto small text-decoration-none"><i class="bi bi-pencil"></i> Cambiar el mensaje</a>
            </div>
        @endif
        <ul class="list-group list-group-flush">
            @forelse($rows as $row)
                @php $p = $row['patient']; $a = $row['appointment']; $f = $row['followup']; $editor = 'msg-' . $row['key']; @endphp
                <li class="list-group-item wa-row {{ $row['sent'] ? 'is-sent' : '' }}" data-wa-row>
                    <div class="d-flex flex-wrap align-items-center gap-2 gap-md-3">
                        <span class="avatar" style="--tone: {{ $kinds[$kind][2] }}">{{ $p->initials }}</span>
                        <div class="flex-fill" style="min-width: 12rem;">
                            <a href="{{ route('patients.show', [$p, 'tab' => 'recordatorios']) }}" class="fw-semibold text-decoration-none text-reset">{{ $p->full_name }}</a>
                            @if($a && $a->status === 'confirmada')<span class="badge bg-primary-subtle text-primary-emphasis ms-1">Confirmada</span>@endif
                            <div class="small text-muted">{{ $row['detail'] }}</div>
                            @if($row['sent'])
                                <div class="small text-success"><i class="bi bi-check2-all"></i> Enviado {{ $row['sent']->created_at->isToday() ? 'hoy ' . $row['sent']->created_at->format('H:i') : $row['sent']->created_at->format('d/m H:i') }}@if($row['sent']->user) por {{ $row['sent']->user->name }}@endif</div>
                            @endif
                        </div>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            @if($a && $kind === 'recordatorio' && $a->status === 'agendada' && $can('appointments.edit'))
                                <form method="POST" action="{{ route('appointments.status', $a) }}" class="m-0">
                                    @csrf
                                    <input type="hidden" name="status" value="confirmada">
                                    <button class="btn btn-sm btn-light" type="submit" title="El paciente respondió que sí"><i class="bi bi-check2-circle"></i> Confirmó</button>
                                </form>
                            @endif
                            @if($f && $can('communications.create'))
                                <form method="POST" action="{{ route('communications.followups.close', $f) }}" class="m-0 d-flex gap-1">
                                    @csrf
                                    <button class="btn btn-sm btn-light" name="status" value="hecho" title="Ya agendó o se resolvió"><i class="bi bi-check2"></i> Hecho</button>
                                    <button class="btn btn-sm btn-light text-muted" name="status" value="descartado" title="No hace falta"><i class="bi bi-x"></i></button>
                                </form>
                            @endif
                            @if($can('communications.create'))
                                @include('admin.communications.partials.sender', ['kind' => $kind, 'patient' => $p, 'message' => $row['message'], 'refs' => $row['refs'], 'target' => '#' . $editor, 'sent' => $row['sent'], 'class' => 'btn-sm'])
                            @endif
                        </div>
                    </div>
                    <details class="wa-message mt-2">
                        <summary class="small text-muted">Ver o ajustar el mensaje</summary>
                        <textarea id="{{ $editor }}" class="form-control form-control-sm mt-2" rows="3" maxlength="2000">{{ $row['message'] }}</textarea>
                    </details>
                </li>
            @empty
                <li class="list-group-item">
                    <div class="empty-state">
                        <div class="empty-icon"><i class="bi {{ $kind ? $kinds[$kind][1] : 'bi-whatsapp' }}"></i></div>
                        <h5 class="mb-1">Nada por enviar aquí</h5>
                        <p class="small mb-0">
                            @switch($kind)
                                @case('recordatorio') No hay citas agendadas para ese día. @break
                                @case('cumpleanos') Hoy no cumple años ningún paciente. @break
                                @case('seguimiento') No hay controles vencidos. Se programan desde la ficha del paciente (pestaña Recordatorios). @break
                                @case('inasistencia') Nadie faltó sin reagendar en los últimos {{ \App\Support\Communications::NO_SHOW_DAYS }} días. @break
                                @case('saldo') Ningún paciente tiene saldos vencidos. @break
                                @default Tu plan no incluye listas de envío.
                            @endswitch
                        </p>
                    </div>
                </li>
            @endforelse
        </ul>
    </div>
</div>
@include('admin.finance.partials.styles')
@push('styles')
<style>
    .wa-row.is-sent { background: rgba(37, 162, 68, .045); }
    .wa-message summary { cursor: pointer; width: fit-content; }
    .wa-message textarea { font-size: .85rem; }
</style>
@endpush
@endsection
