@extends('layouts.app')

@section('title', "Insumos · {$procedure->name}")

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $num = fn ($v) => \App\Support\Stock::number($v);
@endphp
<div class="container-fluid" style="max-width: 820px;">
    @include('admin.finance.partials.errors')

    <div class="page-header">
        <div>
            <a href="{{ route('procedures.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Tarifario</a>
            <h1 class="mt-1"><i class="bi bi-box-seam"></i> Insumos de «{{ $procedure->name }}»</h1>
            <p class="page-subtitle">Lo que se gasta cada vez que se hace. Se descuenta solo del stock del consultorio al registrarlo en la consulta.</p>
        </div>
    </div>

    <div class="card">
        <ul class="list-group list-group-flush">
            @forelse($supplies as $s)
                <li class="list-group-item d-flex align-items-center gap-2">
                    <a href="{{ route('products.show', $s->product) }}" class="flex-fill text-decoration-none text-reset fw-semibold">{{ $s->product?->label }}</a>
                    <span class="tabular">{{ $num($s->quantity) }} {{ mb_strtolower($s->product?->unit_label ?? '') }}</span>
                    @if($can('inventory.create'))
                        <form method="POST" action="{{ route('procedure-supplies.destroy', [$procedure, $s]) }}" class="m-0">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-light text-danger" type="submit" title="Quitar"><i class="bi bi-x-lg"></i></button>
                        </form>
                    @endif
                </li>
            @empty
                <li class="list-group-item">
                    <div class="empty-state py-4">
                        <div class="empty-icon"><i class="bi bi-box-seam"></i></div>
                        <h5 class="mb-1">Sin insumos</h5>
                        <p class="small mb-0">Ejemplo para una resina: 1 cartucho de anestesia, 1 par de guantes, 1 dique de goma.</p>
                    </div>
                </li>
            @endforelse
        </ul>
        @if($can('inventory.create'))
            <form method="POST" action="{{ route('procedure-supplies.store', $procedure) }}" class="card-body border-top row g-2 align-items-end">
                @csrf
                <div class="col-md-7">
                    <label class="form-label small" for="product_id">Insumo</label>
                    <select id="product_id" name="product_id" class="form-select" required>
                        <option value="">Elige…</option>
                        @foreach($products as $p)<option value="{{ $p->id }}">{{ $p->presentation ? "{$p->name} — {$p->presentation}" : $p->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small" for="quantity">Cantidad</label>
                    <input type="number" id="quantity" name="quantity" class="form-control" step="0.01" min="0.01" value="1" required>
                </div>
                <div class="col-6 col-md-2"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-plus-lg"></i> Agregar</button></div>
                @if($products->isEmpty())<div class="col-12 small text-muted">Primero registra productos en <a href="{{ route('products.create') }}">Inventario</a>.</div>@endif
            </form>
        @endif
    </div>
</div>
@endsection
