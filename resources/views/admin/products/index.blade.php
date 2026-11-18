@extends('layouts.app')

@section('title', 'Productos e insumos')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
    $num = fn ($v) => \App\Support\Stock::number($v);
    $purchases = $tenantCompany->planAllows('compras') && $can('purchases.create');
@endphp
<div class="container-fluid">
    @include('admin.finance.partials.errors')

    <div class="page-header">
        <div>
            <h1><i class="bi bi-box-seam"></i> Productos e insumos</h1>
            <p class="page-subtitle">Lo que hay en cada consultorio, lo que falta y lo que está por vencer.</p>
        </div>
        <div class="page-actions">
            @if($can('inventory.create'))<a href="{{ route('stock-movements.create') }}" class="btn btn-light"><i class="bi bi-arrow-down-up"></i> Movimiento</a>@endif
            @if($can('products.create'))<button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#categories"><i class="bi bi-tags"></i> Categorías</button>@endif
            @if($can('products.create'))<a href="{{ route('products.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nuevo producto</a>@endif
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-sm-4">
            <div class="card kpi-card"><div class="kpi-icon" style="--tone:#2563eb"><i class="bi bi-box-seam"></i></div>
                <div><div class="kpi-label">Productos activos</div><div class="kpi-value">{{ $counts['todos'] }}</div><div class="kpi-hint">{{ $counts['venta'] }} a la venta</div></div></div>
        </div>
        <a href="{{ route('products.index', ['filter' => 'bajo']) }}" class="col-sm-4 text-decoration-none text-reset">
            <div class="card kpi-card"><div class="kpi-icon" style="--tone:{{ $counts['bajo'] ? '#dc2626' : '#059669' }}"><i class="bi bi-exclamation-triangle"></i></div>
                <div><div class="kpi-label">Bajo el mínimo</div><div class="kpi-value">{{ $counts['bajo'] }}</div><div class="kpi-hint">{{ $counts['vencer'] }} con lotes por vencer</div></div></div>
        </a>
        <div class="col-sm-4">
            <div class="card kpi-card"><div class="kpi-icon" style="--tone:#ca8a04"><i class="bi bi-cash-coin"></i></div>
                <div><div class="kpi-label">Valor del inventario</div><div class="kpi-value">{{ $money($value) }}</div><div class="kpi-hint">al último costo</div></div></div>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <nav class="filter-chips" aria-label="Filtros">
            @foreach(\App\Http\Controllers\Admin\ProductController::FILTERS as $key => [$label, $icon])
                <a href="{{ route('products.index', array_filter(['filter' => $key, 'q' => request('q'), 'category' => request('category')])) }}" class="filter-chip {{ $filter === $key ? 'active' : '' }}">
                    <i class="bi {{ $icon }}"></i> {{ $label }} @isset($counts[$key])<span class="count">{{ $counts[$key] }}</span>@endisset
                </a>
            @endforeach
        </nav>
        <form method="GET" class="ms-auto d-flex gap-2">
            <input type="hidden" name="filter" value="{{ $filter }}">
            <select name="category" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Categoría">
                <option value="">Todas las categorías</option>
                @foreach($categories as $cat)<option value="{{ $cat->id }}" @selected((string) request('category') === (string) $cat->id)>{{ $cat->name }}</option>@endforeach
            </select>
            <input type="search" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Buscar nombre, código, marca…" aria-label="Buscar">
        </form>
    </div>

    @if($filter === 'bajo' && $products->isNotEmpty() && $purchases)
        <div class="alert alert-warning d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span><i class="bi bi-cart-plus"></i> Arma el pedido con los que faltan.</span>
            <a href="{{ route('purchases.create', ['products' => $products->pluck('id')->all()]) }}" class="btn btn-sm btn-dark">Nueva compra con estos</a>
        </div>
    @endif

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle table-stack mb-0">
                <thead><tr><th class="ps-3">Producto</th><th>Categoría</th><th class="text-end">Existencia</th><th class="text-end">Mínimo</th><th class="text-end">Costo</th><th class="text-end pe-3">Venta</th></tr></thead>
                <tbody>
                    @forelse($products as $product)
                        @php $stock = $product->stockIn(); $low = $product->isLowStock(); @endphp
                        <tr>
                            <td class="ps-3 stack-full">
                                <a href="{{ route('products.show', $product) }}" class="fw-semibold text-decoration-none text-reset">{{ $product->name }}</a>
                                @if(in_array($product->id, $expiringIds))<span class="badge bg-warning-subtle text-warning-emphasis ms-1"><i class="bi bi-hourglass-bottom"></i> Por vencer</span>@endif
                                @if($product->for_sale)<span class="badge bg-info-subtle text-info-emphasis ms-1">Venta</span>@endif
                                <div class="small text-muted">{{ collect([$product->code, $product->brand, $product->presentation])->filter()->implode(' · ') ?: $product->unit_label }}</div>
                            </td>
                            <td class="small" data-label="Categoría">{{ $product->category?->name ?? '—' }}</td>
                            <td class="text-end tabular fw-semibold {{ $stock < 0 ? 'text-danger' : ($low ? 'text-warning-emphasis' : '') }}" data-label="Existencia">
                                {{ $num($stock) }} <span class="small text-muted fw-normal">{{ mb_strtolower($product->unit_label) }}</span>
                                @if($low)<i class="bi bi-exclamation-triangle-fill text-warning" title="En o bajo el mínimo"></i>@endif
                            </td>
                            <td class="text-end tabular small text-muted" data-label="Mínimo">{{ (float) $product->min_stock ? $num($product->min_stock) : '—' }}</td>
                            <td class="text-end tabular small" data-label="Costo">{{ (float) $product->purchase_price ? $money($product->purchase_price) : '—' }}</td>
                            <td class="text-end tabular small pe-3" data-label="Venta">{{ $product->for_sale ? $money($product->sale_price) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="stack-full">
                            <div class="empty-state">
                                <div class="empty-icon"><i class="bi bi-box-seam"></i></div>
                                <h5 class="mb-1">{{ $filter === 'todos' && ! request('q') ? 'Todavía no hay productos' : 'Nada por aquí' }}</h5>
                                <p class="small mb-3">Registra tus insumos (anestesia, resinas, guantes…) con su existencia inicial y su mínimo, y Dexi te avisa cuando falten.</p>
                                @if($can('products.create') && $filter === 'todos')<a href="{{ route('products.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nuevo producto</a>@endif
                            </div>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $products->links() }}</div>
</div>

@if($can('products.create'))
    <div class="modal fade" id="categories" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Categorías de productos</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                <div class="modal-body">
                    <form method="POST" action="{{ route('product-categories.store') }}" class="d-flex gap-2 mb-3">
                        @csrf
                        <input type="text" name="name" class="form-control" placeholder="Nueva categoría" maxlength="80" required aria-label="Nueva categoría">
                        <button class="btn btn-primary" type="submit">Agregar</button>
                    </form>
                    <ul class="list-group">
                        @foreach($categories as $cat)
                            <li class="list-group-item">
                                <form method="POST" action="{{ route('product-categories.update', $cat) }}" class="d-flex gap-2 align-items-center">
                                    @csrf @method('PUT')
                                    <input type="text" name="name" value="{{ $cat->name }}" class="form-control form-control-sm" maxlength="80" aria-label="Nombre">
                                    <input type="hidden" name="active" value="1">
                                    <button class="btn btn-sm btn-light" type="submit" title="Guardar"><i class="bi bi-check2"></i></button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>
@endif
@endsection
