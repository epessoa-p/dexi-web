{{-- Comisiones de un profesional en el período (detalle y «Mis comisiones»). --}}
@php
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
    $earned = round((float) $rows->sum('amount'), 2);
    $settled = round((float) $rows->whereNotNull('commission_settlement_id')->sum('amount'), 2);
    $linkSales = $linkSales ?? false;
@endphp
<div class="row g-3 mb-3">
    <div class="col-sm-4">
        <div class="card kpi-card"><div class="kpi-icon" style="--tone:#4f46e5"><i class="bi bi-percent"></i></div>
            <div><div class="kpi-label">Ganado en el período</div><div class="kpi-value">{{ $money($earned) }}</div><div class="kpi-hint">{{ $rows->where('kind', 'devengada')->count() }} comisiones</div></div></div>
    </div>
    <div class="col-sm-4">
        <div class="card kpi-card"><div class="kpi-icon" style="--tone:#059669"><i class="bi bi-check2-all"></i></div>
            <div><div class="kpi-label">Ya pagado</div><div class="kpi-value">{{ $money($settled) }}</div><div class="kpi-hint">del período</div></div></div>
    </div>
    <div class="col-sm-4">
        <div class="card kpi-card"><div class="kpi-icon" style="--tone:#ca8a04"><i class="bi bi-hourglass-split"></i></div>
            <div><div class="kpi-label">Por pagar</div><div class="kpi-value">{{ $money($pendingAll) }}</div><div class="kpi-hint">de cualquier fecha</div></div></div>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle table-stack mb-0">
            <thead><tr><th class="ps-3">Fecha</th><th>Concepto</th><th class="text-end">Base</th><th class="text-end">Tasa</th><th class="text-end">Comisión</th><th class="pe-3">Estado</th></tr></thead>
            <tbody>
                @forelse($rows as $c)
                    <tr>
                        <td class="ps-3 small text-nowrap" data-label="Fecha">{{ $c->earned_at->format('d/m/Y H:i') }}</td>
                        <td class="stack-full">
                            {{ $c->description }} @if($c->isAdjustment())<span class="badge bg-danger-subtle text-danger-emphasis">Ajuste</span>@endif
                            <div class="small text-muted">
                                @if($c->sale)
                                    @if($linkSales)<a href="{{ route('sales.show', $c->sale) }}" class="text-decoration-none">{{ $c->sale->code }}</a>@else{{ $c->sale->code }}@endif
                                    @if($c->sale->patient) · {{ $c->sale->patient->full_name }}@endif
                                @endif
                            </div>
                        </td>
                        <td class="text-end tabular small" data-label="Base">{{ $money($c->base) }}</td>
                        <td class="text-end small text-nowrap" data-label="Tasa">{{ $c->rate_label }}</td>
                        <td class="text-end tabular fw-semibold {{ (float) $c->amount < 0 ? 'text-danger' : '' }}" data-label="Comisión">{{ $money($c->amount) }}</td>
                        <td class="pe-3" data-label="Estado">
                            @if($c->settlement)
                                <span class="badge bg-success-subtle text-success-emphasis"><i class="bi bi-check2"></i> {{ $c->settlement->code }}</span>
                            @else
                                <span class="badge bg-warning-subtle text-warning-emphasis">Por pagar</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="stack-full">
                        <div class="empty-state">
                            <div class="empty-icon"><i class="bi bi-percent"></i></div>
                            <h5 class="mb-1">Sin comisiones en el período</h5>
                            <p class="small mb-0">Se generan al cobrar lo que atendió, según las reglas de la clínica.</p>
                        </div>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
