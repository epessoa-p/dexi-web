@extends('layouts.app')

@section('title', "Orden {$order->code}")

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
    $o = $order;
    $next = \App\Models\LabOrder::TRANSITIONS[$o->status] ?? [];
    $steps = ['enviado', 'en_proceso', 'recibido', 'instalado'];
    $current = array_search($o->status, $steps, true);
    // Botón principal según el estado.
    $primary = match ($o->status) {
        'enviado' => ['en_proceso', 'Lo están haciendo', 'bi-gear', 'btn-light'],
        'en_proceso' => ['recibido', 'Llegó del laboratorio', 'bi-box-arrow-in-down', 'btn-primary'],
        'recibido' => ['instalado', 'Instalado en el paciente', 'bi-check2-all', 'btn-success'],
        default => null,
    };
@endphp
<div class="container-fluid" style="max-width: 1100px;">
    @include('admin.finance.partials.errors')

    <div class="page-header">
        <div>
            <a href="{{ route('lab-orders.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Laboratorio</a>
            <h1 class="mt-1">{{ $o->code }} · {{ $o->work_label }}</h1>
            <p class="page-subtitle">
                <span class="badge bg-{{ $o->status_color }}{{ $o->status_color === 'warning' ? ' text-dark' : '' }}">{{ $o->status_label }}</span>
                @if($o->isLate())<span class="badge bg-danger">Atrasado</span>@endif
                <span class="ms-1">{{ $o->lab?->name }}</span>
            </p>
        </div>
        <div class="page-actions">
            <a href="{{ route('lab-orders.print', $o) }}" target="_blank" class="btn btn-light"><i class="bi bi-printer"></i> Imprimir orden</a>
            @if($can('lab_orders.edit') && ! in_array($o->status, ['instalado', 'cancelado'], true))<a href="{{ route('lab-orders.edit', $o) }}" class="btn btn-light"><i class="bi bi-pencil"></i> Editar</a>@endif
        </div>
    </div>

    @if($o->status === 'cancelado')
        <div class="alert alert-secondary"><i class="bi bi-x-octagon"></i> Cancelada el {{ $o->cancelled_at?->format('d/m/Y') }} · {{ $o->cancel_reason }}</div>
    @else
        <div class="card mb-3">
            <div class="card-body">
                <div class="lab-steps mb-3">
                    @foreach($steps as $i => $step)
                        <div class="lab-step {{ $current !== false && $i <= $current ? 'done' : '' }} {{ $i === $current ? 'current' : '' }}">
                            <span class="dot"><i class="bi {{ \App\Models\LabOrder::STATUSES[$step][2] }}"></i></span>
                            <span class="small">{{ \App\Models\LabOrder::STATUSES[$step][0] }}</span>
                        </div>
                    @endforeach
                </div>
                @if($can('lab_orders.edit') && $next)
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        @if($primary)
                            <form method="POST" action="{{ route('lab-orders.status', $o) }}" class="m-0">
                                @csrf
                                <input type="hidden" name="status" value="{{ $primary[0] }}">
                                <button class="btn {{ $primary[3] }}" type="submit"><i class="bi {{ $primary[2] }}"></i> {{ $primary[1] }}</button>
                            </form>
                        @endif
                        @if($o->status === 'enviado')
                            <form method="POST" action="{{ route('lab-orders.status', $o) }}" class="m-0">
                                @csrf
                                <input type="hidden" name="status" value="recibido">
                                <button class="btn btn-primary" type="submit"><i class="bi bi-box-arrow-in-down"></i> Llegó del laboratorio</button>
                            </form>
                        @endif
                        @if($o->status === 'recibido')
                            <details class="ms-1">
                                <summary class="btn btn-light"><i class="bi bi-arrow-counterclockwise"></i> Devolver para ajuste</summary>
                                <form method="POST" action="{{ route('lab-orders.status', $o) }}" class="d-flex gap-2 mt-2">
                                    @csrf
                                    <input type="hidden" name="status" value="en_proceso">
                                    <input type="text" name="note" class="form-control" maxlength="255" placeholder="Qué hay que corregir: contacto alto, color…" required aria-label="Qué corregir">
                                    <button class="btn btn-warning text-nowrap" type="submit">Devolver</button>
                                </form>
                            </details>
                        @endif
                        @if(in_array('cancelado', $next, true))
                            <details class="ms-auto">
                                <summary class="btn btn-link text-danger">Cancelar orden</summary>
                                <form method="POST" action="{{ route('lab-orders.status', $o) }}" class="d-flex gap-2 mt-2">
                                    @csrf
                                    <input type="hidden" name="status" value="cancelado">
                                    <input type="text" name="note" class="form-control" maxlength="255" placeholder="Motivo" required aria-label="Motivo">
                                    <button class="btn btn-outline-danger" type="submit">Cancelar</button>
                                </form>
                            </details>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-gem"></i> Trabajo</div>
                <div class="card-body">
                    <dl class="info-list mb-0">
                        <dt>Paciente</dt><dd><a href="{{ route('patients.show', [$o->patient, 'tab' => 'tratamiento']) }}" class="text-decoration-none">{{ $o->patient?->full_name }}</a></dd>
                        <dt>Trabajo</dt><dd class="fw-semibold">{{ $o->work_label }}</dd>
                        @if($o->teeth)<dt>Pieza(s)</dt><dd>{{ $o->teeth }}</dd>@endif
                        @if($o->material)<dt>Material</dt><dd>{{ $o->material }}</dd>@endif
                        @if($o->shade)<dt>Color</dt><dd><span class="badge bg-light text-dark border fs-6">{{ $o->shade }}</span></dd>@endif
                        @if($o->professional)<dt>Lo pide</dt><dd>{{ $o->professional->full_name }}</dd>@endif
                        @if($o->planItem)<dt>Del plan</dt><dd><a href="{{ route('treatment-plans.show', $o->planItem->plan) }}" class="text-decoration-none">{{ $o->planItem->plan?->code }}</a> · {{ $o->planItem->name }}</dd>@endif
                        @if($o->instructions)<dt>Indicaciones</dt><dd style="white-space: pre-wrap;">{{ $o->instructions }}</dd>@endif
                        @if($o->notes)<dt>Notas</dt><dd>{{ $o->notes }}</dd>@endif
                    </dl>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-body">
                    <dl class="info-list mb-0">
                        <dt>Enviado</dt><dd>{{ $o->sent_at->format('d/m/Y') }}</dd>
                        <dt>Entrega</dt><dd class="{{ $o->isLate() ? 'text-danger fw-semibold' : '' }}">{{ $o->due_at?->format('d/m/Y') ?? '—' }}</dd>
                        @if($o->received_at)<dt>Llegó</dt><dd>{{ $o->received_at->format('d/m/Y') }}</dd>@endif
                        @if($o->installed_at)<dt>Instalado</dt><dd>{{ $o->installed_at->format('d/m/Y') }}</dd>@endif
                        @if($o->returns_count)<dt>Ajustes</dt><dd>{{ $o->returns_count }} {{ $o->returns_count === 1 ? 'devolución' : 'devoluciones' }}</dd>@endif
                        <dt>Costo</dt><dd class="fw-bold tabular">{{ (float) $o->cost ? $money($o->cost) : '—' }}</dd>
                        @if((float) $o->cost && $o->status !== 'cancelado')
                            <dt>Pago</dt>
                            <dd>
                                @if($o->expense)
                                    <span class="text-success"><i class="bi bi-check2"></i> Pagado</span>@if($can('expenses.view')) · <a href="{{ route('expenses.show', $o->expense) }}">{{ $o->expense->code }}</a>@endif
                                @else
                                    <span class="text-warning-emphasis">Por pagar</span>
                                    @if($can('lab_orders.edit'))· <a href="{{ route('lab-orders.index', ['filter' => 'pagar', 'lab' => $o->supplier_id]) }}">pagar al laboratorio</a>@endif
                                @endif
                            </dd>
                        @endif
                    </dl>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><i class="bi bi-clock-history"></i> Recorrido</div>
                <ul class="list-group list-group-flush">
                    @foreach($o->logs as $log)
                        <li class="list-group-item small">
                            <div>{{ $log->description }}</div>
                            <div class="text-muted">{{ collect([$log->created_at?->format('d/m/Y H:i'), $log->user?->name])->filter()->implode(' · ') }}</div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>
@push('styles')
<style>
    .lab-steps { display: flex; gap: .25rem; }
    .lab-step { flex: 1; display: flex; flex-direction: column; align-items: center; gap: .35rem; position: relative; color: var(--dx-muted, #6b7f85); text-align: center; }
    .lab-step::before { content: ''; position: absolute; top: 1.05rem; left: -50%; width: 100%; height: 3px; background: #e3eaec; z-index: 0; }
    .lab-step:first-child::before { display: none; }
    .lab-step.done::before { background: var(--brand-base); }
    .lab-step .dot { width: 2.1rem; height: 2.1rem; border-radius: 50%; display: grid; place-items: center; background: #eef3f4; position: relative; z-index: 1; }
    .lab-step.done .dot { background: var(--brand-base); color: #fff; }
    .lab-step.current { color: var(--brand-dark); font-weight: 600; }
    .lab-step.current .dot { box-shadow: 0 0 0 4px rgba(var(--brand-rgb), .2); }
    details > summary { list-style: none; }
    details > summary::-webkit-details-marker { display: none; }
</style>
@endpush
@endsection
