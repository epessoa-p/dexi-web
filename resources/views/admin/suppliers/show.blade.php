@extends('layouts.app')

@section('title', $supplier->name)

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
    $canPay = $can('expenses.create') && Route::has('expenses.create') && $supplier->active;
@endphp
<div class="container-fluid">
    <div class="page-header">
        <div class="min-w-0">
            <a href="{{ route('suppliers.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Proveedores</a>
            <h1 class="mt-1">{{ $supplier->name }} @unless($supplier->active)<span class="badge bg-secondary fs-6 align-middle">Inactivo</span>@endunless</h1>
            <p class="page-subtitle">
                {{ collect([$supplier->tax_id ? "NIT / RUC {$supplier->tax_id}" : null, $supplier->contact_name, $supplier->phone, $supplier->email])->filter()->implode(' · ') ?: 'Sin datos de contacto' }}
            </p>
            @if($supplier->address)<div class="small text-muted"><i class="bi bi-geo-alt"></i> {{ $supplier->address }}</div>@endif
        </div>
        <div class="page-actions">
            @if($canPay)
                <a href="{{ route('expenses.create', ['group' => 'proveedor', 'supplier_id' => $supplier->id]) }}" class="btn btn-primary"><i class="bi bi-cash"></i> Registrar pago</a>
            @endif
            @if($can('suppliers.edit'))
                <a href="{{ route('suppliers.edit', $supplier) }}" class="btn btn-outline-primary" title="Editar"><i class="bi bi-pencil"></i> Editar</a>
            @endif
            @if($can('suppliers.delete'))
                <form action="{{ route('suppliers.destroy', $supplier) }}" method="POST" class="m-0" onsubmit="return confirm('¿Eliminar el proveedor? Sus pagos se conservan.')">
                    @csrf @method('DELETE')
                    <button class="btn btn-outline-danger" type="submit" title="Eliminar"><i class="bi bi-trash"></i></button>
                </form>
            @endif
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-4">
            <div class="card kpi-card">
                <div class="kpi-icon" style="--tone:#0d9488"><i class="bi bi-cash-stack"></i></div>
                <div><div class="kpi-label">Pagado (histórico)</div><div class="kpi-value">{{ $money($paidTotal) }}</div></div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-4">
            <div class="card kpi-card">
                <div class="kpi-icon" style="--tone:#2563eb"><i class="bi bi-calendar3"></i></div>
                <div><div class="kpi-label">Pagado en {{ now()->year }}</div><div class="kpi-value">{{ $money($paidThisYear) }}</div></div>
            </div>
        </div>
        @if($supplier->notes)
            <div class="col-lg-4"><div class="card h-100"><div class="card-body small"><span class="text-muted">Observaciones:</span> {{ $supplier->notes }}</div></div></div>
        @endif
    </div>

    <div class="card">
        <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-receipt"></i> Pagos a este proveedor</div>
        <div class="table-responsive">
            <table class="table table-hover align-middle table-stack">
                <thead><tr><th class="ps-3">Gasto</th><th>Fecha</th><th>Descripción</th><th>Salió de</th><th class="text-end pe-3">Importe</th></tr></thead>
                <tbody>
                    @forelse($payments as $payment)
                        <tr class="{{ $payment->isCancelled() ? 'text-decoration-line-through text-muted' : '' }}">
                            <td class="ps-3" data-label="Gasto"><a href="{{ route('expenses.show', $payment) }}" class="fw-semibold text-decoration-none">{{ $payment->code }}</a></td>
                            <td class="small" data-label="Fecha">{{ $payment->spent_at->format('d/m/Y') }}</td>
                            <td class="small" data-label="Descripción">{{ $payment->concept }}</td>
                            <td class="small text-muted" data-label="Salió de">{{ $payment->source_label }}</td>
                            <td class="text-end pe-3 fw-semibold tabular" data-label="Importe">{{ $money($payment->amount) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="stack-full">
                            <div class="empty-state py-4">
                                <div class="empty-icon"><i class="bi bi-receipt"></i></div>
                                <h5 class="mb-1">Sin pagos todavía</h5>
                                <p class="small mb-0">Los pagos que registres en Gastos › Proveedores aparecen aquí.</p>
                            </div>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3 d-flex justify-content-center">{{ $payments->links() }}</div>
</div>
@endsection
