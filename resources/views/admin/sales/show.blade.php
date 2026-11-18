@extends('layouts.app')

@section('title', "Venta {$sale->code}")

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
    $s = $sale;
    $p = $s->patient;
@endphp
<div class="container-fluid" style="max-width: 1100px;">
    @include('admin.finance.partials.errors')

    <div class="page-header">
        <div>
            <a href="{{ route('sales.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Ventas</a>
            <h1 class="mt-1">Venta {{ $s->code }}</h1>
            <p class="page-subtitle">
                @if($s->isCancelled())
                    <span class="badge bg-dark">Anulada</span>
                @elseif((float) $s->balance > 0)
                    <span class="badge bg-{{ $s->isOverdue() ? 'danger' : 'warning text-dark' }}">{{ $s->isOverdue() ? 'Saldo vencido' : 'Con saldo' }}</span>
                @else
                    <span class="badge bg-success">Pagada</span>
                @endif
                <span class="ms-1">{{ collect([$s->created_at->format('d/m/Y H:i'), $s->user?->name])->filter()->implode(' · ') }}</span>
            </p>
        </div>
        <div class="page-actions">
            <a href="{{ route('sales.receipt', $s) }}" target="_blank" class="btn btn-light"><i class="bi bi-printer"></i> Recibo</a>
            @if($p && $can('sales.create'))<a href="{{ route('sales.create', ['patient_id' => $p->id]) }}" class="btn btn-light"><i class="bi bi-cart-plus"></i> Otro cobro</a>@endif
            @if(! $s->isCancelled() && $can('sales.delete'))
                <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal"><i class="bi bi-x-circle"></i> Anular</button>
            @endif
        </div>
    </div>

    @if($s->isCancelled())
        <div class="alert alert-secondary"><i class="bi bi-x-octagon"></i> Anulada el {{ $s->cancelled_at?->format('d/m/Y H:i') }}@if($s->canceller) por {{ $s->canceller->name }}@endif · {{ $s->cancel_reason }}</div>
    @endif

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card mb-3">
                @if($p)
                    <div class="card-body d-flex align-items-center gap-2 border-bottom">
                        <span class="avatar">{{ $p->initials }}</span>
                        <a href="{{ route('patients.show', [$p, 'tab' => 'pagos']) }}" class="fw-semibold text-decoration-none text-reset flex-fill">{{ $p->full_name }} <span class="small text-muted fw-normal">{{ $p->code }}</span></a>
                    </div>
                @endif
                <div class="table-responsive">
                    <table class="table align-middle table-stack mb-0">
                        <thead><tr><th class="ps-3">Concepto</th><th class="text-end">Cant.</th><th class="text-end">Precio</th><th class="text-end">Desc.</th><th class="text-end pe-3">Total</th></tr></thead>
                        <tbody>
                            @foreach($s->items as $item)
                                <tr>
                                    <td class="ps-3 stack-full">{{ $item->description }}@if($item->origin || $item->professional)<div class="small text-muted">{{ collect([$item->origin, $item->professional?->full_name])->filter()->implode(" · ") }}</div>@endif</td>
                                    <td class="text-end tabular" data-label="Cantidad">{{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }}</td>
                                    <td class="text-end tabular" data-label="Precio">{{ $money($item->unit_price) }}</td>
                                    <td class="text-end tabular" data-label="Descuento">{{ (float) $item->discount ? '− ' . $money($item->discount) : '—' }}</td>
                                    <td class="text-end pe-3 tabular fw-semibold" data-label="Total">{{ $money($item->total) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($s->notes)<div class="card-body border-top small"><span class="text-muted">Nota:</span> {{ $s->notes }}</div>@endif
            </div>

            <div class="card">
                <div class="card-header"><i class="bi bi-cash-coin"></i> Pagos</div>
                <ul class="list-group list-group-flush">
                    @forelse($s->payments as $payment)
                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <div>
                                <span class="fw-semibold">{{ $payment->method?->name }}</span>
                                <span class="badge bg-light text-dark border ms-1">{{ $payment->kind === 'abono' ? 'Abono' : 'Al cobrar' }}</span>
                                <div class="small text-muted">{{ collect([$payment->paid_at?->format('d/m/Y H:i'), $payment->user?->name, $payment->reference ? 'Ref. ' . $payment->reference : null])->filter()->implode(' · ') }}</div>
                            </div>
                            <span class="tabular fw-semibold">{{ $money($payment->amount) }}</span>
                        </li>
                    @empty
                        <li class="list-group-item small text-muted">Sin pagos: se registró a crédito.</li>
                    @endforelse
                </ul>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-body">
                    <dl class="info-list mb-0">
                        <dt>Subtotal</dt><dd class="text-end tabular">{{ $money($s->subtotal) }}</dd>
                        @if((float) $s->discount)<dt>Descuento</dt><dd class="text-end tabular">− {{ $money($s->discount) }}</dd>@endif
                        <dt class="fw-bold text-dark">Total</dt><dd class="text-end tabular fs-5 fw-bold">{{ $money($s->total) }}</dd>
                        <dt>Pagado</dt><dd class="text-end tabular text-success">{{ $money($s->paid) }}</dd>
                        @unless($s->isCancelled())
                            <dt>Saldo</dt><dd class="text-end tabular fw-bold {{ (float) $s->balance > 0 ? 'text-danger' : '' }}">{{ $money($s->balance) }}</dd>
                            @if($s->due_date)<dt>Paga hasta</dt><dd class="text-end {{ $s->isOverdue() ? 'text-danger fw-semibold' : '' }}">{{ $s->due_date->format('d/m/Y') }}</dd>@endif
                        @endunless
                    </dl>
                </div>
            </div>

            @if(! $s->isCancelled() && (float) $s->balance > 0 && $can('receivables.create'))
                <form method="POST" action="{{ route('sales.payment', $s) }}" class="card">
                    @csrf
                    <div class="card-header"><i class="bi bi-plus-circle"></i> Registrar abono</div>
                    <div class="card-body">
                        @include('admin.sales.partials.payment-fields', ['prefix' => 'abono', 'amount' => (float) $s->balance])
                        <button class="btn btn-primary w-100 mt-3" type="submit"><i class="bi bi-check2-circle"></i> Registrar abono</button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</div>

@if(! $s->isCancelled() && $can('sales.delete'))
    <div class="modal fade" id="cancelModal" tabindex="-1" aria-hidden="true" @if($errors->hasAny(['cancel_reason', 'payment_method_id'])) data-reopen @endif>
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="POST" action="{{ route('sales.cancel', $s) }}">
                @csrf
                <div class="modal-header"><h5 class="modal-title">Anular la venta {{ $s->code }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                <div class="modal-body d-flex flex-column gap-3">
                    <p class="small text-muted mb-0">Lo atendido vuelve a quedar pendiente de cobro{{ (float) $s->paid > 0 ? ' y se devuelven ' . $money($s->paid) . ' (sale de la caja)' : '' }}.</p>
                    <div>
                        <label class="form-label" for="cancel_reason">Motivo</label>
                        <input type="text" id="cancel_reason" name="cancel_reason" class="form-control" maxlength="255" placeholder="Error al cobrar, se cobró dos veces…" required>
                    </div>
                    @if((float) $s->paid > 0)
                        <div>
                            <label class="form-label" for="refund-method">Cómo se devuelve el dinero</label>
                            <select name="payment_method_id" id="refund-method" class="form-select" required>
                                @foreach($methods as $method)<option value="{{ $method->id }}">{{ $method->name }}</option>@endforeach
                            </select>
                        </div>
                        @if($cashEnabled && $sessions->count() > 1)
                            <select name="cash_session_id" class="form-select" aria-label="Caja">@foreach($sessions as $session)<option value="{{ $session->id }}">{{ $session->caja?->name }}</option>@endforeach</select>
                        @elseif($cashEnabled && $sessions->count() === 1)
                            <input type="hidden" name="cash_session_id" value="{{ $sessions->first()->id }}">
                        @endif
                    @endif
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Volver</button><button class="btn btn-danger" type="submit">Anular venta</button></div>
            </form>
        </div>
    </div>
@endif
@include('admin.finance.partials.styles')
@endsection
