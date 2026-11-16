@extends('layouts.app')

@section('title', 'Proveedores')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
@endphp
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h1 class="mb-1"><i class="bi bi-truck"></i> Proveedores</h1>
            <p class="text-muted mb-0">Depósitos dentales, laboratorios, técnicos de equipos y demás proveedores.</p>
        </div>
        @if($can('suppliers.create'))
            <a href="{{ route('suppliers.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nuevo proveedor</a>
        @endif
    </div>

    <form method="GET" class="card border-0 shadow-sm mb-3">
        <div class="card-body row g-2 align-items-end">
            <div class="col-md-7">
                <input type="search" name="q" value="{{ request('q') }}" class="form-control" placeholder="Nombre, NIT / RUC o contacto" autofocus>
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">Activos</option>
                    <option value="inactive" @selected(request('status') === 'inactive')>Inactivos</option>
                </select>
            </div>
            <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-search"></i> Buscar</button></div>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th class="ps-3">Proveedor</th><th>NIT / RUC</th><th>Contacto</th><th class="text-center">Pagos</th><th class="text-end">Pagado</th><th class="pe-3"></th></tr></thead>
                    <tbody>
                        @forelse($suppliers as $supplier)
                            <tr>
                                <td class="ps-3"><a href="{{ route('suppliers.show', $supplier) }}" class="fw-semibold text-decoration-none">{{ $supplier->name }}</a></td>
                                <td>{{ $supplier->tax_id ?: '—' }}</td>
                                <td class="small">{{ $supplier->contact_name }}@if($supplier->phone)<div class="text-muted">{{ $supplier->phone }}</div>@endif</td>
                                <td class="text-center">{{ $supplier->expenses_count }}</td>
                                <td class="text-end tabular">{{ $supplier->paid > 0 ? $money($supplier->paid) : '—' }}</td>
                                <td class="pe-3 text-end text-nowrap">
                                    @if($can('expenses.create') && Route::has('expenses.create'))
                                        <a href="{{ route('expenses.create', ['group' => 'proveedor', 'supplier_id' => $supplier->id]) }}" class="btn btn-sm btn-outline-success" title="Registrar un pago"><i class="bi bi-cash"></i></a>
                                    @endif
                                    @if($can('suppliers.edit'))
                                        <a href="{{ route('suppliers.edit', $supplier) }}" class="btn btn-sm btn-outline-primary" title="Editar"><i class="bi bi-pencil"></i></a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><div class="empty-state py-4"><div class="empty-icon"><i class="bi bi-truck"></i></div><h5 class="mb-1">No hay proveedores</h5><p class="small mb-0">Agrega el depósito dental o el laboratorio con el que trabajas.</p></div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="mt-4 d-flex justify-content-center">{{ $suppliers->links() }}</div>
</div>
@endsection
