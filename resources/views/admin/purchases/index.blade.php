@extends('layouts.app')

@section('title', 'Compras')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
@endphp
<div class="container-fluid">
    @include('admin.finance.partials.errors')

    <div class="page-header">
        <div>
            <h1><i class="bi bi-bag-check"></i> Compras</h1>
            <p class="page-subtitle">Pedidos a proveedores: lo que falta recibir entra al stock al llegar; lo que se debe se paga en cuotas.</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('purchases.payables') }}" class="btn btn-light"><i class="bi bi-hourglass-split"></i> Cuentas por pagar</a>
            @if($can('purchases.create'))<a href="{{ route('purchases.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nueva compra</a>@endif
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-xl-4">
            <a href="{{ route('purchases.payables') }}" class="card kpi-card text-decoration-none text-reset"><div class="kpi-icon" style="--tone:#dc2626"><i class="bi bi-cash-stack"></i></div>
                <div><div class="kpi-label">Por pagar a proveedores</div><div class="kpi-value">{{ $money($payable) }}</div><div class="kpi-hint">{{ $overdue ? $overdue . ' vencidas' : 'nada vencido' }}</div></div></a>
        </div>
        <div class="col-sm-6 col-xl-4">
            <a href="{{ route('purchases.index', ['filter' => 'recibir']) }}" class="card kpi-card text-decoration-none text-reset"><div class="kpi-icon" style="--tone:#ca8a04"><i class="bi bi-truck"></i></div>
                <div><div class="kpi-label">Por recibir</div><div class="kpi-value">{{ $counts['recibir'] }}</div><div class="kpi-hint">pedidos en camino</div></div></a>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <nav class="filter-chips" aria-label="Filtros">
            @foreach(\App\Http\Controllers\Admin\PurchaseController::FILTERS as $key => [$label, $icon])
                <a href="{{ route('purchases.index', array_filter(['filter' => $key, 'supplier' => request('supplier')])) }}" class="filter-chip {{ $filter === $key ? 'active' : '' }}">
                    <i class="bi {{ $icon }}"></i> {{ $label }} <span class="count">{{ $counts[$key] }}</span>
                </a>
            @endforeach
        </nav>
        <form method="GET" class="ms-auto">
            <input type="hidden" name="filter" value="{{ $filter }}">
            <select name="supplier" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Proveedor">
                <option value="">Todos los proveedores</option>
                @foreach($suppliers as $s)<option value="{{ $s->id }}" @selected((string) request('supplier') === (string) $s->id)>{{ $s->name }}</option>@endforeach
            </select>
        </form>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle table-stack mb-0">
                <thead><tr><th class="ps-3">Compra</th><th>Proveedor</th><th>Estado</th><th class="text-end">Total</th><th class="text-end pe-3">Saldo</th></tr></thead>
                <tbody>
                    @forelse($purchases as $pu)
                        <tr class="{{ $pu->isCancelled() ? 'text-muted' : '' }}">
                            <td class="ps-3 stack-full">
                                <a href="{{ route('purchases.show', $pu) }}" class="fw-semibold text-decoration-none">{{ $pu->code }}</a>
                                <div class="small text-muted">{{ $pu->purchased_at->format('d/m/Y') }}@if($pu->document_number) · Fact. {{ $pu->document_number }}@endif</div>
                            </td>
                            <td data-label="Proveedor">{{ $pu->supplier?->name }}</td>
                            <td data-label="Estado"><span class="badge bg-{{ $pu->status_color }}{{ $pu->status_color === 'warning' ? ' text-dark' : '' }}">{{ $pu->status_label }}</span></td>
                            <td class="text-end tabular" data-label="Total">{{ $money($pu->total) }}</td>
                            <td class="text-end tabular pe-3 fw-semibold {{ $pu->isOverdue() ? 'text-danger' : ((float) $pu->balance > 0 ? 'text-warning-emphasis' : 'text-success') }}" data-label="Saldo">
                                {{ (float) $pu->balance > 0 ? $money($pu->balance) : ($pu->isCancelled() ? '—' : 'Pagada') }}
                                @if($pu->isOverdue())<div class="small fw-normal">venció {{ $pu->due_date->format('d/m') }}</div>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="stack-full">
                            <div class="empty-state">
                                <div class="empty-icon"><i class="bi bi-bag-check"></i></div>
                                <h5 class="mb-1">Sin compras aquí</h5>
                                <p class="small mb-3">Registra lo que pides a tus proveedores: al recibirlo entra al stock y, si es a crédito, queda en cuentas por pagar.</p>
                                @if($can('purchases.create'))<a href="{{ route('purchases.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nueva compra</a>@endif
                            </div>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $purchases->links() }}</div>
</div>
@endsection
