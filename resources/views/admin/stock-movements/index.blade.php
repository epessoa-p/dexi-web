@extends('layouts.app')

@section('title', 'Movimientos de inventario')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $num = fn ($v) => \App\Support\Stock::number($v);
@endphp
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h1><i class="bi bi-arrow-down-up"></i> Movimientos (kardex)</h1>
            <p class="page-subtitle">Cada entrada y salida queda registrada y no se borra: un error se corrige con otro movimiento.</p>
        </div>
        @if($can('inventory.create'))
            <div class="page-actions">
                @foreach(\App\Http\Controllers\Admin\StockMovementController::KINDS as $key => [$label, $icon, $color])
                    <a href="{{ route('stock-movements.create', ['kind' => $key]) }}" class="btn btn-light"><i class="bi {{ $icon }}" style="color: {{ $color }}"></i> {{ $label }}</a>
                @endforeach
            </div>
        @endif
    </div>

    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-2"><label class="form-label small mb-1">Desde</label><input type="date" name="from" value="{{ $from->toDateString() }}" class="form-control form-control-sm"></div>
            <div class="col-6 col-md-2"><label class="form-label small mb-1">Hasta</label><input type="date" name="to" value="{{ $to->toDateString() }}" class="form-control form-control-sm"></div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Tipo</label>
                <select name="type" class="form-select form-select-sm"><option value="">Todos</option>@foreach(\App\Models\StockMovement::TYPES as $k => [$l])<option value="{{ $k }}" @selected(request('type') === $k)>{{ $l }}</option>@endforeach</select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Motivo</label>
                <select name="reason" class="form-select form-select-sm"><option value="">Todos</option>@foreach(\App\Models\StockMovement::REASONS as $k => $l)<option value="{{ $k }}" @selected(request('reason') === $k)>{{ $l }}</option>@endforeach</select>
            </div>
            @if($branches->count() > 1)
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">Consultorio</label>
                    <select name="branch" class="form-select form-select-sm"><option value="">Todos</option>@foreach($branches as $b)<option value="{{ $b->id }}" @selected((string) request('branch') === (string) $b->id)>{{ $b->name }}</option>@endforeach</select>
                </div>
            @endif
            <div class="col"><label class="form-label small mb-1">Producto</label><input type="search" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Buscar…"></div>
            <div class="col-auto"><button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel"></i> Ver</button></div>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle table-stack mb-0">
                <thead><tr><th class="ps-3">Fecha</th><th>Producto</th><th>Movimiento</th><th>Detalle</th><th class="text-end">Cantidad</th><th class="text-end pe-3">Saldo</th></tr></thead>
                <tbody>
                    @forelse($movements as $m)
                        <tr>
                            <td class="ps-3 small text-nowrap" data-label="Fecha">{{ $m->created_at->format('d/m/Y H:i') }}</td>
                            <td class="stack-full"><a href="{{ route('products.show', $m->product) }}" class="fw-semibold text-decoration-none text-reset">{{ $m->product?->name }}</a></td>
                            <td data-label="Movimiento"><span class="badge bg-{{ $m->type_color }}-subtle text-{{ $m->type_color }}-emphasis">{{ $m->type_label }}</span> <span class="small">{{ $m->reason_label }}</span></td>
                            <td class="small text-muted" data-label="Detalle">{{ collect([$m->notes, $m->batch ? 'Lote ' . $m->batch->lot : null, $branches->count() > 1 ? $m->branch?->name : null, $m->user?->name])->filter()->implode(' · ') ?: '—' }}</td>
                            <td class="text-end tabular fw-semibold {{ (float) $m->quantity < 0 ? 'text-danger' : 'text-success' }}" data-label="Cantidad">{{ (float) $m->quantity > 0 ? '+' : '' }}{{ $num($m->quantity) }}</td>
                            <td class="text-end pe-3 tabular" data-label="Saldo">{{ $num($m->balance_after) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="stack-full">
                            <div class="empty-state">
                                <div class="empty-icon"><i class="bi bi-arrow-down-up"></i></div>
                                <h5 class="mb-1">Sin movimientos en el período</h5>
                                <p class="small mb-0">Aparecen las compras recibidas, ventas, insumos de procedimientos, ajustes y traslados.</p>
                            </div>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $movements->links() }}</div>
</div>
@endsection
