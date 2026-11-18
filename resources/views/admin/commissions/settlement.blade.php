@extends('layouts.app')

@section('title', "Liquidación {$settlement->code}")

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
    $s = $settlement;
@endphp
<div class="container-fluid" style="max-width: 900px;">
    @include('admin.finance.partials.errors')

    <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
        <a href="{{ route('commissions.index') }}#liquidaciones" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Comisiones</a>
        <button class="btn btn-light" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir comprobante</button>
    </div>

    <div class="card mb-3">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between flex-wrap gap-3 mb-3">
                <div>
                    <div class="text-muted small">{{ $tenantCompany->displayName() }}</div>
                    <h1 class="h3 mb-1">Liquidación de comisiones {{ $s->code }}</h1>
                    @if($s->isCancelled())<span class="badge bg-dark">Anulada</span>@else<span class="badge bg-success">Pagada</span>@endif
                </div>
                <div class="text-end">
                    <div class="text-muted small">Total pagado</div>
                    <div class="display-6 fw-bold tabular {{ $s->isCancelled() ? 'text-decoration-line-through text-muted' : '' }}">{{ $money($s->total) }}</div>
                </div>
            </div>
            <dl class="info-list mb-0">
                <dt>Profesional</dt><dd class="fw-semibold">{{ $s->professional?->full_name }}</dd>
                <dt>Período</dt><dd>{{ $s->period_start->format('d/m/Y') }} – {{ $s->period_end->format('d/m/Y') }}</dd>
                <dt>Pagó</dt><dd>{{ collect([$s->payer?->name, $s->paid_at?->format('d/m/Y H:i')])->filter()->implode(' · ') }}</dd>
                @if($s->expense)
                    <dt>Salió de</dt>
                    <dd>{{ $s->expense->source_label }}@if($can('expenses.view')) · <a href="{{ route('expenses.show', $s->expense) }}" class="d-print-none">gasto {{ $s->expense->code }}</a>@endif</dd>
                @endif
                @if($s->notes)<dt>Nota</dt><dd>{{ $s->notes }}</dd>@endif
            </dl>
        </div>
        <div class="table-responsive">
            <table class="table align-middle table-stack mb-0">
                <thead><tr><th class="ps-4">Fecha</th><th>Concepto</th><th class="text-end">Base</th><th class="text-end">Tasa</th><th class="text-end pe-4">Comisión</th></tr></thead>
                <tbody>
                    @foreach($s->commissions->sortBy('earned_at') as $c)
                        <tr>
                            <td class="ps-4 small text-nowrap" data-label="Fecha">{{ $c->earned_at->format('d/m/Y') }}</td>
                            <td class="stack-full">{{ $c->description }} @if($c->isAdjustment())<span class="badge bg-danger-subtle text-danger-emphasis">Ajuste</span>@endif<div class="small text-muted">{{ $c->sale?->code }}</div></td>
                            <td class="text-end small tabular" data-label="Base">{{ $money($c->base) }}</td>
                            <td class="text-end small text-nowrap" data-label="Tasa">{{ $c->rate_label }}</td>
                            <td class="text-end pe-4 fw-semibold tabular {{ (float) $c->amount < 0 ? 'text-danger' : '' }}" data-label="Comisión">{{ $money($c->amount) }}</td>
                        </tr>
                    @endforeach
                    @if($s->isCancelled())
                        <tr><td colspan="5" class="text-center text-muted small py-3 stack-full">Al anular la liquidación, sus comisiones volvieron a quedar pendientes.</td></tr>
                    @endif
                </tbody>
                <tfoot><tr class="fw-bold"><td colspan="4" class="ps-4 text-end">Total</td><td class="text-end pe-4 tabular">{{ $money($s->total) }}</td></tr></tfoot>
            </table>
        </div>
        <div class="card-body d-none d-print-flex justify-content-around pt-5 mt-4">
            <div class="text-center border-top pt-2" style="width: 40%">Recibí conforme · {{ $s->professional?->full_name }}</div>
            <div class="text-center border-top pt-2" style="width: 40%">Entregué · {{ $s->payer?->name }}</div>
        </div>
    </div>

    @if($s->isCancelled())
        <div class="alert alert-secondary"><i class="bi bi-x-octagon"></i> Anulada el {{ $s->cancelled_at?->format('d/m/Y H:i') }}@if($s->canceller) por {{ $s->canceller->name }}@endif · {{ $s->cancel_reason }}</div>
    @elseif($can('commissions.delete'))
        <details class="card d-print-none">
            <summary class="card-body text-danger fw-semibold"><i class="bi bi-x-octagon"></i> Anular liquidación</summary>
            <form method="POST" action="{{ route('commissions.settlement.cancel', $s) }}" class="card-body pt-0 d-flex flex-column gap-2"
                  onsubmit="return confirm('¿Anular la liquidación {{ $s->code }}? El dinero vuelve a donde salió y las comisiones quedan pendientes otra vez.')">
                @csrf
                <input type="text" name="cancel_reason" class="form-control @error('cancel_reason') is-invalid @enderror" placeholder="Motivo (obligatorio)" maxlength="255" required>
                @error('cancel_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <button class="btn btn-outline-danger align-self-start" type="submit">Anular</button>
            </form>
        </details>
    @endif
</div>
@endsection
