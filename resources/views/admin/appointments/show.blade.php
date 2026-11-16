@extends('layouts.app')

@section('title', 'Cita · ' . $appointment->patient?->full_name)

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $a = $appointment;
    $p = $a->patient;
    $alerts = $p?->medicalAlerts() ?? [];
    $actions = [
        'confirmada' => ['Confirmar', 'bi-check2-circle', 'primary'],
        'en_espera' => ['Llegó', 'bi-door-open', 'warning'],
        'en_atencion' => ['Pasar a atención', 'bi-activity', 'info'],
        'atendida' => ['Terminar atención', 'bi-check2-all', 'success'],
        'no_asistio' => ['No asistió', 'bi-person-x', 'outline-danger'],
        'agendada' => ['Volver a agendada', 'bi-arrow-counterclockwise', 'light'],
    ];
    $reminder = $a->isClosed() ? null : $a->reminderLink($tenantCompany->displayName());
@endphp
<div class="container-fluid" style="max-width: 1080px;">
    @include('admin.finance.partials.errors')

    <div class="page-header">
        <div class="min-w-0">
            <a href="{{ route('appointments.index', ['date' => $a->starts_at->toDateString()]) }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Agenda</a>
            <h1 class="mt-1 text-capitalize">{{ $a->starts_at->locale('es')->translatedFormat('l j \d\e F') }} · {{ $a->starts_at->format('H:i') }}–{{ $a->ends_at->format('H:i') }}</h1>
            <p class="page-subtitle">
                <span class="badge bg-{{ $a->status_color }}"><i class="bi {{ $a->status_icon }}"></i> {{ $a->status_label }}</span>
                @if($a->overbooked)<span class="badge bg-warning-subtle text-warning-emphasis">Sobreturno</span>@endif
                @if($a->reschedule_count)<span class="small ms-1"><i class="bi bi-arrow-repeat"></i> reprogramada {{ $a->reschedule_count }} {{ $a->reschedule_count === 1 ? 'vez' : 'veces' }}</span>@endif
            </p>
        </div>
        <div class="page-actions">
            @php $clinical = $tenantCompany->planAllows('historia_clinica') && $can('consultations.view'); $consultation = $clinical ? $a->consultation : null; @endphp
            @if($consultation)
                <a href="{{ route('consultations.show', $consultation) }}" class="btn btn-primary"><i class="bi bi-clipboard2-pulse"></i> {{ $consultation->isOpen() ? 'Continuar consulta' : 'Ver consulta' }}</a>
            @elseif($clinical && $can('consultations.create') && ! in_array($a->status, ['cancelada', 'no_asistio'], true))
                <form method="POST" action="{{ route('consultations.start') }}" class="m-0">
                    @csrf
                    <input type="hidden" name="appointment_id" value="{{ $a->id }}">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-play-fill"></i> Atender</button>
                </form>
            @endif
            @if($reminder)
                <a href="{{ $reminder }}" target="_blank" rel="noopener" class="btn btn-outline-success"><i class="bi bi-whatsapp"></i> Recordar por WhatsApp</a>
            @endif
            @if($can('appointments.edit') && ! $a->isClosed())
                <a href="{{ route('appointments.edit', $a) }}" class="btn btn-light"><i class="bi bi-pencil"></i> Editar / reprogramar</a>
            @endif
        </div>
    </div>

    @if($alerts)
        <div class="medical-banner" role="alert">
            <i class="bi bi-exclamation-octagon-fill"></i>
            <div>
                <div class="fw-bold mb-1">Antes de atender a {{ $p->first_name }}</div>
                <div class="d-flex flex-wrap gap-1">
                    @foreach($alerts as $alert)<span class="alert-chip {{ $alert['critical'] ? 'critical' : '' }}">{{ $alert['label'] }}</span>@endforeach
                </div>
                @if($p->medications)<div class="small mt-1"><strong>Medicación:</strong> {{ $p->medications }}</div>@endif
            </div>
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card mb-3">
                <div class="card-body d-flex gap-3 align-items-center">
                    <span class="avatar avatar-lg {{ $alerts && $alerts[0]['critical'] ? 'is-alert' : '' }}">{{ $p?->initials }}</span>
                    <div class="flex-fill min-w-0">
                        <div class="fs-5 fw-bold">{{ $p?->full_name }}</div>
                        <div class="small text-muted">{{ collect([$p?->code, $p?->age_label, $p?->phone ?: $p?->guardian_phone])->filter()->implode(' · ') }}</div>
                    </div>
                    @if($p && $can('patients.view'))
                        <a href="{{ route('patients.show', $p) }}" class="btn btn-light"><i class="bi bi-person-vcard"></i> Ficha</a>
                    @endif
                </div>
                <div class="card-body border-top">
                    <dl class="info-list">
                        <dt>Procedimiento</dt><dd>{{ $a->procedure?->name ?? 'Consulta / sin definir' }}@if($a->procedure && $a->procedure->hasPrice()) <span class="text-muted">· {{ \App\Support\Money::format($a->procedure->price, $tenantCompany) }}</span>@endif</dd>
                        <dt>Profesional</dt><dd><span class="d-inline-block rounded-circle me-1" style="width:.6rem;height:.6rem;background: {{ $a->professional?->color }}"></span>{{ $a->professional?->full_name }}</dd>
                        <dt>Duración</dt><dd>{{ $a->duration_minutes }} min</dd>
                        @if($a->branch)<dt>Consultorio</dt><dd>{{ $a->branch->name }}</dd>@endif
                        <dt>Motivo</dt><dd>{!! $a->reason ? e($a->reason) : '<span class="text-muted">—</span>' !!}</dd>
                        @if($a->notes)<dt>Notas</dt><dd>{{ $a->notes }}</dd>@endif
                        @if($a->status === 'cancelada')<dt>Cancelada</dt><dd class="text-danger">{{ $a->cancelled_at?->format('d/m H:i') }} · {{ $a->cancel_reason }}</dd>@endif
                        @if($a->arrived_at)<dt>Llegó</dt><dd>{{ $a->arrived_at->format('H:i') }}@if($a->started_at) · pasó {{ $a->started_at->format('H:i') }} ({{ (int) $a->arrived_at->diffInMinutes($a->started_at) }} min de espera)@endif</dd>@endif
                        @if($a->finished_at)<dt>Terminó</dt><dd>{{ $a->finished_at->format('H:i') }}</dd>@endif
                    </dl>
                </div>
            </div>

            @if($can('appointments.edit') && ($a->status !== 'atendida'))
                <div class="card mb-3">
                    <div class="card-header">Cambiar estado</div>
                    <div class="card-body d-flex flex-wrap gap-2">
                        @foreach(\App\Models\Appointment::TRANSITIONS[$a->status] ?? [] as $status)
                            @continue($status === 'cancelada')
                            <form method="POST" action="{{ route('appointments.status', $a) }}">
                                @csrf
                                <input type="hidden" name="status" value="{{ $status }}">
                                <button class="btn btn-{{ $actions[$status][2] }}" type="submit"><i class="bi {{ $actions[$status][1] }}"></i> {{ $actions[$status][0] }}</button>
                            </form>
                        @endforeach
                    </div>
                    @if($a->canMoveTo('cancelada'))
                        <details class="border-top">
                            <summary class="card-body py-2 text-danger fw-semibold small"><i class="bi bi-x-circle"></i> Cancelar la cita</summary>
                            <form method="POST" action="{{ route('appointments.status', $a) }}" class="card-body pt-0 d-flex gap-2 flex-wrap">
                                @csrf
                                <input type="hidden" name="status" value="cancelada">
                                <input type="text" name="cancel_reason" class="form-control flex-fill" style="min-width: 220px" maxlength="255" placeholder="Motivo (el paciente avisó, enfermedad…)" required>
                                <button class="btn btn-outline-danger" type="submit">Cancelar cita</button>
                            </form>
                        </details>
                    @endif
                </div>
            @endif
        </div>

        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-clock-history"></i> Historial de la cita</div>
                <ul class="list-group list-group-flush small">
                    @foreach($a->logs as $log)
                        <li class="list-group-item">
                            <div>{{ $log->description }}</div>
                            <div class="text-muted">{{ $log->created_at?->format('d/m/Y H:i') }}@if($log->user) · {{ $log->user->name }}@endif</div>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-calendar3"></i> Otras citas del paciente</span>
                    @if($p && $can('appointments.create'))<a href="{{ route('appointments.create', ['patient_id' => $p->id]) }}" class="small text-decoration-none">+ Agendar otra</a>@endif
                </div>
                <ul class="list-group list-group-flush small">
                    @forelse($history as $other)
                        <li class="list-group-item d-flex justify-content-between gap-2">
                            <a href="{{ route('appointments.show', $other) }}" class="text-decoration-none text-reset">
                                {{ $other->starts_at->format('d/m/Y H:i') }} · {{ $other->title }}
                                <span class="text-muted d-block">{{ $other->professional?->full_name }}</span>
                            </a>
                            <span class="badge bg-{{ $other->status_color }} align-self-start">{{ $other->status_label }}</span>
                        </li>
                    @empty
                        <li class="list-group-item text-muted">Es su primera cita.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
