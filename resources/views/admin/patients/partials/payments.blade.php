{{-- Pestaña «Pagos» de la ficha: lo pendiente de cobro, la deuda y sus ventas. --}}
@php
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
    $pendingDone = $payments['pending']['consultation']->sum(fn ($cp) => $cp->subtotal)
        + $payments['pending']['plan']->where('status', 'realizado')->sum(fn ($i) => $i->subtotal);
    $pendingAdvance = $payments['pending']['plan']->where('status', 'pendiente')->sum(fn ($i) => $i->subtotal);
    $debt = $payments['sales']->filter(fn ($s) => ! $s->isCancelled())->sum(fn ($s) => (float) $s->balance);
    $paidTotal = $payments['sales']->filter(fn ($s) => ! $s->isCancelled())->sum(fn ($s) => (float) $s->paid);
@endphp
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card kpi-card"><div class="kpi-icon" style="--tone:#ea580c"><i class="bi bi-hourglass-split"></i></div>
            <div><div class="kpi-label">Realizado sin cobrar</div><div class="kpi-value">{{ $money($pendingDone) }}</div>
                <div class="kpi-hint">{{ $pendingAdvance > 0 ? '+ ' . $money($pendingAdvance) . ' del plan por hacer' : 'consultas y planes' }}</div></div></div>
    </div>
    <div class="col-md-4">
        <div class="card kpi-card"><div class="kpi-icon" style="--tone:#dc3545"><i class="bi bi-exclamation-circle"></i></div>
            <div><div class="kpi-label">Debe (saldos)</div><div class="kpi-value {{ $debt > 0 ? 'text-danger' : '' }}">{{ $money($debt) }}</div><div class="kpi-hint">de ventas a crédito</div></div></div>
    </div>
    <div class="col-md-4">
        <div class="card kpi-card"><div class="kpi-icon" style="--tone:#059669"><i class="bi bi-check2-circle"></i></div>
            <div><div class="kpi-label">Pagó en total</div><div class="kpi-value">{{ $money($paidTotal) }}</div><div class="kpi-hint">{{ $payments['sales']->count() }} {{ $payments['sales']->count() === 1 ? 'venta' : 'ventas' }}</div></div></div>
    </div>
</div>

@if($can('sales.create') && ($pendingDone > 0 || $pendingAdvance > 0 || $debt > 0))
    <div class="d-flex gap-2 flex-wrap mb-3">
        @if($pendingDone > 0 || $pendingAdvance > 0)<a href="{{ route('sales.create', ['patient_id' => $p->id]) }}" class="btn btn-primary"><i class="bi bi-cash-coin"></i> Cobrar lo pendiente</a>@endif
        @if($debt > 0 && Route::has('receivables.index'))<a href="{{ route('receivables.index', ['q' => $p->full_name]) }}" class="btn btn-outline-primary"><i class="bi bi-cash"></i> Registrar abono</a>@endif
    </div>
@endif

<div class="card">
    <div class="card-header d-flex align-items-center"><i class="bi bi-receipt me-2"></i> Ventas
        @if($can('sales.create'))<a href="{{ route('sales.create', ['patient_id' => $p->id]) }}" class="ms-auto small text-decoration-none fw-normal">+ Nuevo cobro</a>@endif</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle table-stack mb-0">
            <thead><tr><th class="ps-3">Venta</th><th>Pagos</th><th class="text-end">Total</th><th class="text-end">Saldo</th><th class="pe-3">Estado</th></tr></thead>
            <tbody>
                @forelse($payments['sales'] as $sale)
                    <tr class="row-link {{ $sale->isCancelled() ? 'text-muted' : '' }}" onclick="location.href='{{ route('sales.show', $sale) }}'">
                        <td class="ps-3 stack-full"><span class="fw-semibold">{{ $sale->code }}</span><div class="small text-muted">{{ $sale->created_at->format('d/m/Y') }}</div></td>
                        <td class="small text-muted" data-label="Pagos">{{ $sale->payments->pluck('method.name')->unique()->implode(', ') ?: 'Crédito' }}</td>
                        <td class="text-end tabular" data-label="Total">{{ $money($sale->total) }}</td>
                        <td class="text-end tabular {{ (float) $sale->balance > 0 ? 'text-danger fw-semibold' : 'text-muted' }}" data-label="Saldo">{{ (float) $sale->balance > 0 ? $money($sale->balance) : '—' }}</td>
                        <td class="pe-3" data-label="Estado">
                            @if($sale->isCancelled())<span class="badge bg-dark">Anulada</span>
                            @elseif((float) $sale->balance > 0)<span class="badge bg-warning text-dark">Con saldo</span>
                            @else<span class="badge bg-success-subtle text-success-emphasis">Pagada</span>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="stack-full small text-muted ps-3">Todavía no tiene ventas.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
