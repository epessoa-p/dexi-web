@extends('layouts.app')

@section('title', 'Cuentas por cobrar')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
    $clinic = $tenantCompany->displayName();
@endphp
<div class="container-fluid">
    @include('admin.finance.partials.errors')

    <div class="page-header">
        <div>
            <h1><i class="bi bi-hourglass-split"></i> Cuentas por cobrar</h1>
            <p class="page-subtitle">Quién debe, cuánto y desde cuándo. Un abono se aplica a sus deudas de la más antigua a la más nueva.</p>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-xl-4">
            <div class="card kpi-card"><div class="kpi-icon" style="--tone:#dc3545"><i class="bi bi-cash-stack"></i></div>
                <div><div class="kpi-label">Total por cobrar</div><div class="kpi-value">{{ $money($total) }}</div><div class="kpi-hint">{{ $rows->count() }} {{ $rows->count() === 1 ? 'paciente' : 'pacientes' }}</div></div></div>
        </div>
        <div class="col-sm-6 col-xl-4">
            <div class="card kpi-card"><div class="kpi-icon" style="--tone:#ea580c"><i class="bi bi-exclamation-triangle"></i></div>
                <div><div class="kpi-label">Con pago vencido</div><div class="kpi-value">{{ $overdue }}</div><div class="kpi-hint">pasaron la fecha acordada</div></div></div>
        </div>
    </div>

    <form method="GET" class="mb-3" role="search">
        <div class="search-hero"><i class="bi bi-search"></i><input type="search" name="q" value="{{ request('q') }}" class="form-control" placeholder="Buscar paciente…" aria-label="Buscar paciente"></div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle table-stack">
                <thead><tr><th class="ps-3">Paciente</th><th>Ventas</th><th>Desde</th><th class="text-end">Debe</th><th class="pe-3 text-end"></th></tr></thead>
                <tbody>
                    @forelse($rows as $row)
                        @php
                            $p = $row['patient'];
                            $link = $p?->whatsapp_link ? $p->whatsapp_link . '?text=' . rawurlencode("Hola {$p->first_name}, te escribimos de {$clinic}. Te recordamos que tienes un saldo pendiente de " . $money($row['balance']) . '. ¡Gracias!') : null;
                        @endphp
                        <tr>
                            <td class="ps-3 stack-full">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="avatar" style="--tone:#dc3545">{{ $p?->initials }}</span>
                                    <div><a href="{{ route('patients.show', [$p, 'tab' => 'pagos']) }}" class="fw-semibold text-decoration-none text-reset">{{ $p?->full_name }}</a>
                                        <div class="small text-muted">{{ $p?->phone ?: $p?->guardian_phone }}</div></div>
                                </div>
                            </td>
                            <td class="small" data-label="Ventas"><a href="{{ route('sales.index', ['filter' => 'saldo', 'q' => $p?->full_name]) }}">{{ $row['count'] }} con saldo</a></td>
                            <td class="small" data-label="Desde">{{ \Carbon\Carbon::parse($row['oldest'])->format('d/m/Y') }} @if($row['overdue'])<span class="badge bg-danger">Vencido</span>@endif</td>
                            <td class="text-end fw-bold tabular text-danger" data-label="Debe">{{ $money($row['balance']) }}</td>
                            <td class="pe-3 text-end text-nowrap">
                                @if($link)<a href="{{ $link }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success" title="Recordar por WhatsApp"><i class="bi bi-whatsapp"></i></a>@endif
                                @if($can('receivables.create'))
                                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#pay-{{ $p->id }}"><i class="bi bi-cash"></i> Abonar</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="stack-full">
                            <div class="empty-state">
                                <div class="empty-icon"><i class="bi bi-emoji-smile"></i></div>
                                <h5 class="mb-1">Nadie debe</h5>
                                <p class="small mb-0">Todas las ventas están pagadas.</p>
                            </div>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@if($can('receivables.create'))
    @foreach($rows as $row)
        <div class="modal fade" id="pay-{{ $row['patient']->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="POST" action="{{ route('receivables.pay', $row['patient']) }}">
                    @csrf
                    <div class="modal-header"><h5 class="modal-title">Abono de {{ $row['patient']->full_name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                    <div class="modal-body">
                        <p class="small text-muted">Debe {{ $money($row['balance']) }} en {{ $row['count'] }} {{ $row['count'] === 1 ? 'venta' : 'ventas' }}.</p>
                        @include('admin.sales.partials.payment-fields', ['prefix' => 'pay-' . $row['patient']->id, 'amount' => $row['balance']])
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Registrar abono</button></div>
                </form>
            </div>
        </div>
    @endforeach
@endif
@endsection
