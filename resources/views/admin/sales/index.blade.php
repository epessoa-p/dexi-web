@extends('layouts.app')

@section('title', 'Ventas')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
    $byMethod = fn ($payments) => $payments->groupBy(fn ($p) => $p->method?->name ?? '—')->map(fn ($g) => $g->sum('amount'));
@endphp
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h1><i class="bi bi-receipt"></i> Ventas</h1>
            <p class="page-subtitle">Lo cobrado, por método de pago, y lo que queda por cobrar.</p>
        </div>
        @if($can('sales.create'))
            <div class="page-actions"><a href="{{ route('sales.create') }}" class="btn btn-primary"><i class="bi bi-cart-plus"></i> Cobrar</a></div>
        @endif
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card kpi-card">
                <div class="kpi-icon" style="--tone:#059669"><i class="bi bi-cash-coin"></i></div>
                <div class="min-w-0">
                    <div class="kpi-label">Cobrado hoy</div>
                    <div class="kpi-value">{{ $money($today->sum('amount')) }}</div>
                    <div class="kpi-hint text-truncate">{{ $byMethod($today)->map(fn ($v, $k) => $k . ' ' . $money($v))->implode(' · ') ?: 'Sin cobros todavía' }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card kpi-card">
                <div class="kpi-icon" style="--tone:#2563eb"><i class="bi bi-calendar3"></i></div>
                <div class="min-w-0">
                    <div class="kpi-label">Cobrado en {{ now()->locale('es')->translatedFormat('F') }}</div>
                    <div class="kpi-value">{{ $money($month->sum('amount')) }}</div>
                    <div class="kpi-hint text-truncate">{{ $byMethod($month)->map(fn ($v, $k) => $k . ' ' . $money($v))->implode(' · ') }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <a href="{{ route('receivables.index') }}" class="card kpi-card text-decoration-none text-reset">
                <div class="kpi-icon" style="--tone:#dc3545"><i class="bi bi-hourglass-split"></i></div>
                <div><div class="kpi-label">Por cobrar</div><div class="kpi-value">{{ $money($receivable) }}</div><div class="kpi-hint">Ver cuentas por cobrar</div></div>
            </a>
        </div>
    </div>

    <form method="GET" class="mb-3" role="search">
        <input type="hidden" name="filter" value="{{ $filter }}">
        <div class="search-hero"><i class="bi bi-search"></i><input type="search" name="q" value="{{ request('q') }}" class="form-control" placeholder="Buscar por paciente…" aria-label="Buscar por paciente"></div>
    </form>
    <nav class="filter-chips mb-3" aria-label="Filtros">
        @foreach(\App\Http\Controllers\Admin\SaleController::FILTERS as $key => $label)
            <a href="{{ route('sales.index', array_filter(['filter' => $key, 'q' => request('q')])) }}" class="filter-chip {{ $filter === $key ? 'active' : '' }}">{{ $label }}</a>
        @endforeach
    </nav>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle table-stack">
                <thead><tr><th class="ps-3">Venta</th><th>Paciente</th><th>Pagos</th><th class="text-end">Total</th><th class="text-end">Saldo</th><th class="pe-3">Estado</th></tr></thead>
                <tbody>
                    @forelse($sales as $sale)
                        <tr class="row-link {{ $sale->isCancelled() ? 'text-muted' : '' }}" onclick="location.href='{{ route('sales.show', $sale) }}'">
                            <td class="ps-3 stack-full"><a href="{{ route('sales.show', $sale) }}" class="fw-semibold text-decoration-none text-reset">{{ $sale->code }}</a><div class="small text-muted">{{ $sale->created_at->format('d/m/Y H:i') }}</div></td>
                            <td class="small" data-label="Paciente">{{ $sale->patient?->full_name ?? 'Mostrador' }}</td>
                            <td class="small text-muted" data-label="Pagos">{{ $sale->payments->pluck('method.name')->unique()->implode(', ') ?: 'Crédito' }}</td>
                            <td class="text-end tabular fw-semibold" data-label="Total">{{ $money($sale->total) }}</td>
                            <td class="text-end tabular {{ (float) $sale->balance > 0 ? 'text-danger fw-semibold' : 'text-muted' }}" data-label="Saldo">{{ (float) $sale->balance > 0 ? $money($sale->balance) : '—' }}</td>
                            <td class="pe-3" data-label="Estado">
                                @if($sale->isCancelled())<span class="badge bg-dark">Anulada</span>
                                @elseif((float) $sale->balance > 0)<span class="badge bg-{{ $sale->isOverdue() ? 'danger' : 'warning text-dark' }}">{{ $sale->isOverdue() ? 'Vencida' : 'Con saldo' }}</span>
                                @else<span class="badge bg-success-subtle text-success-emphasis">Pagada</span>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="stack-full">
                            <div class="empty-state">
                                <div class="empty-icon"><i class="bi bi-receipt"></i></div>
                                <h5 class="mb-1">Sin ventas {{ $filter === 'hoy' ? 'hoy' : 'aquí' }}</h5>
                                <p class="small mb-0">Cobra lo realizado desde la consulta, la ficha del paciente o con «Cobrar».</p>
                            </div>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3 d-flex justify-content-center">{{ $sales->links() }}</div>
</div>
@endsection
