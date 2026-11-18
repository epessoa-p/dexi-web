@extends('layouts.app')

@section('title', $product->name)

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
    $num = fn ($v) => \App\Support\Stock::number($v);
    $p = $product;
    $total = $p->stockIn();
    $unit = mb_strtolower($p->unit_label);
@endphp
<div class="container-fluid" style="max-width: 1150px;">
    @include('admin.finance.partials.errors')

    <div class="page-header">
        <div>
            <a href="{{ route('products.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Productos</a>
            <h1 class="mt-1">{{ $p->name }} @unless($p->active)<span class="badge bg-secondary fs-6 align-middle">Inactivo</span>@endunless</h1>
            <p class="page-subtitle">{{ collect([$p->category?->name, $p->brand, $p->presentation, $p->code ? 'Cód. ' . $p->code : null])->filter()->implode(' · ') ?: $p->unit_label }}</p>
        </div>
        <div class="page-actions">
            @if($can('inventory.create'))
                <div class="btn-group">
                    <a href="{{ route('stock-movements.create', ['kind' => 'entrada', 'product' => $p->id]) }}" class="btn btn-light"><i class="bi bi-box-arrow-in-down text-success"></i> Entrada</a>
                    <a href="{{ route('stock-movements.create', ['kind' => 'salida', 'product' => $p->id]) }}" class="btn btn-light"><i class="bi bi-box-arrow-up text-danger"></i> Salida</a>
                    <a href="{{ route('stock-movements.create', ['kind' => 'ajuste', 'product' => $p->id]) }}" class="btn btn-light"><i class="bi bi-clipboard-check"></i> Conteo</a>
                </div>
            @endif
            @if($can('products.edit'))<a href="{{ route('products.edit', $p) }}" class="btn btn-light"><i class="bi bi-pencil"></i> Editar</a>@endif
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-xl-3">
            <div class="card kpi-card"><div class="kpi-icon" style="--tone:{{ $p->isLowStock() ? '#dc2626' : '#2563eb' }}"><i class="bi bi-box-seam"></i></div>
                <div><div class="kpi-label">Existencia</div><div class="kpi-value {{ $total < 0 ? 'text-danger' : '' }}">{{ $num($total) }} <span class="fs-6 fw-normal text-muted">{{ $unit }}</span></div>
                    <div class="kpi-hint">{{ (float) $p->min_stock ? 'mínimo ' . $num($p->min_stock) : 'sin mínimo' }}@if($p->isLowStock()) · <span class="text-danger fw-semibold">reponer</span>@endif</div></div></div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card kpi-card"><div class="kpi-icon" style="--tone:#ca8a04"><i class="bi bi-receipt"></i></div>
                <div><div class="kpi-label">Último costo</div><div class="kpi-value">{{ $money($p->purchase_price) }}</div><div class="kpi-hint">valor {{ $money(max($total, 0) * (float) $p->purchase_price) }}</div></div></div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card kpi-card"><div class="kpi-icon" style="--tone:#0e9f9a"><i class="bi bi-cart"></i></div>
                <div><div class="kpi-label">Precio de venta</div><div class="kpi-value">{{ $p->for_sale ? $money($p->sale_price) : '—' }}</div><div class="kpi-hint">{{ $p->for_sale ? 'se ofrece en Cobrar' : 'solo uso interno' }}</div></div></div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card kpi-card"><div class="kpi-icon" style="--tone:#7c3aed"><i class="bi bi-clipboard2-pulse"></i></div>
                <div><div class="kpi-label">Lo gastan</div><div class="kpi-value">{{ $supplies->count() }}</div><div class="kpi-hint">{{ $supplies->count() === 1 ? 'procedimiento' : 'procedimientos' }}</div></div></div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-diagram-2"></i> Por consultorio</div>
                <ul class="list-group list-group-flush">
                    @forelse($p->stocks->sortBy('branch.name') as $stock)
                        <li class="list-group-item d-flex justify-content-between"><span>{{ $stock->branch?->name }}</span><span class="tabular fw-semibold {{ (float) $stock->quantity < 0 ? 'text-danger' : '' }}">{{ $num($stock->quantity) }} {{ $unit }}</span></li>
                    @empty
                        <li class="list-group-item small text-muted">Sin existencia registrada.</li>
                    @endforelse
                </ul>
                @if($supplies->isNotEmpty())
                    <div class="card-body border-top small">
                        <div class="text-muted mb-1">Se descuenta al registrar:</div>
                        @foreach($supplies as $s)
                            <a href="{{ route('procedure-supplies.edit', $s->procedure) }}" class="badge bg-light text-dark border text-decoration-none me-1 mb-1">{{ $s->procedure?->name }} · {{ $num($s->quantity) }}</a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-upc"></i> Lotes con existencia</div>
                @if($p->track_batches)
                    <div class="table-responsive">
                        <table class="table align-middle table-stack mb-0">
                            <thead><tr><th class="ps-3">Lote</th><th>Consultorio</th><th>Vence</th><th class="text-end pe-3">Cantidad</th></tr></thead>
                            <tbody>
                                @forelse($batches as $batch)
                                    <tr>
                                        <td class="ps-3 fw-semibold stack-full">{{ $batch->lot }}</td>
                                        <td class="small" data-label="Consultorio">{{ $batch->branch?->name }}</td>
                                        <td data-label="Vence" class="{{ $batch->isExpired() ? 'text-danger fw-semibold' : ($batch->isExpiringSoon() ? 'text-warning-emphasis fw-semibold' : '') }}">
                                            {{ $batch->expires_at?->format('d/m/Y') ?? '—' }}
                                            @if($batch->isExpired()) <span class="badge bg-danger">Vencido</span>@elseif($batch->isExpiringSoon()) <span class="badge bg-warning text-dark">Por vencer</span>@endif
                                        </td>
                                        <td class="text-end pe-3 tabular" data-label="Cantidad">{{ $num($batch->quantity) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="small text-muted ps-3 stack-full">Sin lotes con existencia.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="card-body small text-muted">Este producto no se controla por lote. Actívalo en «Editar» si necesitas seguir vencimientos (anestesia, medicamentos).</div>
                @endif
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex align-items-center gap-2 flex-wrap">
            <span><i class="bi bi-journal-text"></i> Kardex</span>
            @if($branches->count() > 1)
                <form method="GET" class="ms-auto">
                    <select name="branch" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Consultorio">
                        <option value="">Todos los consultorios</option>
                        @foreach($branches as $b)<option value="{{ $b->id }}" @selected((string) request('branch') === (string) $b->id)>{{ $b->name }}</option>@endforeach
                    </select>
                </form>
            @endif
        </div>
        <div class="table-responsive">
            <table class="table align-middle table-stack mb-0">
                <thead><tr><th class="ps-3">Fecha</th><th>Movimiento</th><th>Detalle</th><th class="text-end">Cantidad</th><th class="text-end pe-3">Saldo</th></tr></thead>
                <tbody>
                    @forelse($movements as $m)
                        <tr>
                            <td class="ps-3 small text-nowrap" data-label="Fecha">{{ $m->created_at->format('d/m/Y H:i') }}</td>
                            <td data-label="Movimiento"><span class="badge bg-{{ $m->type_color }}-subtle text-{{ $m->type_color }}-emphasis">{{ $m->type_label }}</span> <span class="small">{{ $m->reason_label }}</span></td>
                            <td class="small text-muted stack-full">{{ collect([$m->notes, $m->batch ? 'Lote ' . $m->batch->lot : null, $branches->count() > 1 ? $m->branch?->name : null, $m->user?->name])->filter()->implode(' · ') }}</td>
                            <td class="text-end tabular fw-semibold {{ (float) $m->quantity < 0 ? 'text-danger' : 'text-success' }}" data-label="Cantidad">{{ (float) $m->quantity > 0 ? '+' : '' }}{{ $num($m->quantity) }}</td>
                            <td class="text-end pe-3 tabular" data-label="Saldo">{{ $num($m->balance_after) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="small text-muted ps-3 stack-full">Todavía no hay movimientos.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $movements->links() }}</div>

    @if($can('products.delete'))
        <form method="POST" action="{{ route('products.destroy', $p) }}" class="mt-3 text-end" onsubmit="return confirm('¿Eliminar «{{ $p->name }}»? Si tiene historial quedará inactivo.')">
            @csrf @method('DELETE')
            <button class="btn btn-link text-danger btn-sm" type="submit"><i class="bi bi-trash"></i> Eliminar producto</button>
        </form>
    @endif
</div>
@endsection
