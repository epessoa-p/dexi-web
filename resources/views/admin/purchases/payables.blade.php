@extends('layouts.app')

@section('title', 'Cuentas por pagar')

@section('page')
@php $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany); @endphp
<div class="container-fluid" style="max-width: 1100px;">
    <div class="page-header">
        <div>
            <h1><i class="bi bi-hourglass-split"></i> Cuentas por pagar</h1>
            <p class="page-subtitle">A quién se le debe, cuánto y hasta cuándo. Cada pago sale de una cuenta o de la caja, como un gasto.</p>
        </div>
        <div class="page-actions"><a href="{{ route('purchases.index') }}" class="btn btn-light"><i class="bi bi-bag-check"></i> Compras</a></div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-xl-4">
            <div class="card kpi-card"><div class="kpi-icon" style="--tone:#dc3545"><i class="bi bi-cash-stack"></i></div>
                <div><div class="kpi-label">Total por pagar</div><div class="kpi-value">{{ $money($total) }}</div><div class="kpi-hint">{{ $rows->count() }} {{ $rows->count() === 1 ? 'proveedor' : 'proveedores' }}</div></div></div>
        </div>
        <div class="col-sm-6 col-xl-4">
            <div class="card kpi-card"><div class="kpi-icon" style="--tone:#ea580c"><i class="bi bi-exclamation-triangle"></i></div>
                <div><div class="kpi-label">Con pago vencido</div><div class="kpi-value">{{ $overdue }}</div><div class="kpi-hint">pasaron la fecha acordada</div></div></div>
        </div>
    </div>

    @forelse($rows as $row)
        <div class="card mb-3">
            <div class="card-header d-flex align-items-center gap-2 flex-wrap">
                <span class="fw-semibold"><i class="bi bi-truck"></i> {{ $row['supplier']?->name }}</span>
                @if($row['overdue'])<span class="badge bg-danger">Vencido</span>@endif
                <span class="ms-auto fw-bold tabular">{{ $money($row['balance']) }}</span>
            </div>
            <ul class="list-group list-group-flush">
                @foreach($row['purchases'] as $pu)
                    <li class="list-group-item d-flex align-items-center gap-2 flex-wrap">
                        <a href="{{ route('purchases.show', $pu) }}" class="fw-semibold text-decoration-none">{{ $pu->code }}</a>
                        <span class="small text-muted">{{ $pu->purchased_at->format('d/m/Y') }} · total {{ $money($pu->total) }}</span>
                        <span class="small {{ $pu->isOverdue() ? 'text-danger fw-semibold' : 'text-muted' }}">{{ $pu->due_date ? 'paga hasta ' . $pu->due_date->format('d/m/Y') : 'sin fecha' }}</span>
                        <span class="ms-auto tabular fw-semibold">{{ $money($pu->balance) }}</span>
                        <a href="{{ route('purchases.show', $pu) }}" class="btn btn-sm btn-primary">Pagar</a>
                    </li>
                @endforeach
            </ul>
        </div>
    @empty
        <div class="card">
            <div class="empty-state">
                <div class="empty-icon"><i class="bi bi-check2-circle"></i></div>
                <h5 class="mb-1">No se le debe nada a nadie</h5>
                <p class="small mb-0">Las compras a crédito aparecen aquí hasta que se terminan de pagar.</p>
            </div>
        </div>
    @endforelse
</div>
@endsection
