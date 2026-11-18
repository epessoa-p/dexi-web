@extends('layouts.app')

@section('title', "Compra {$purchase->code}")

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
    $num = fn ($v) => \App\Support\Stock::number($v);
    $pu = $purchase;
    $validPayments = $pu->payments->where('status', 'pagado');
@endphp
<div class="container-fluid" style="max-width: 1150px;">
    @include('admin.finance.partials.errors')

    <div class="page-header">
        <div>
            <a href="{{ route('purchases.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Compras</a>
            <h1 class="mt-1">Compra {{ $pu->code }}</h1>
            <p class="page-subtitle">
                <span class="badge bg-{{ $pu->status_color }}{{ $pu->status_color === 'warning' ? ' text-dark' : '' }}">{{ $pu->status_label }}</span>
                @if((float) $pu->balance > 0)<span class="badge bg-{{ $pu->isOverdue() ? 'danger' : 'light text-dark border' }}">{{ $pu->isOverdue() ? 'Pago vencido' : 'Con saldo' }}</span>@elseif(! $pu->isCancelled())<span class="badge bg-success">Pagada</span>@endif
                <span class="ms-1">{{ $pu->supplier?->name }} · {{ $pu->purchased_at->format('d/m/Y') }}@if($pu->document_number) · Fact. {{ $pu->document_number }}@endif</span>
            </p>
        </div>
        @if(! $pu->isCancelled() && $can('purchases.delete') && $pu->receptions->isEmpty() && $validPayments->isEmpty())
            <div class="page-actions"><button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancel-purchase"><i class="bi bi-x-circle"></i> Anular</button></div>
        @endif
    </div>

    @if($pu->isCancelled())<div class="alert alert-secondary"><i class="bi bi-x-octagon"></i> Anulada el {{ $pu->cancelled_at?->format('d/m/Y H:i') }} · {{ $pu->cancel_reason }}</div>@endif
    @if($pu->closing_notes)<div class="alert alert-info"><i class="bi bi-info-circle"></i> Cerrada con lo recibido: {{ $pu->closing_notes }}</div>@endif

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-box-seam"></i> Productos</div>
                <div class="table-responsive">
                    <table class="table align-middle table-stack mb-0">
                        <thead><tr><th class="ps-3">Producto</th><th class="text-end">Pedido</th><th class="text-end">Recibido</th><th class="text-end">Costo</th><th class="text-end pe-3">Total</th></tr></thead>
                        <tbody>
                            @foreach($pu->items as $item)
                                <tr>
                                    <td class="ps-3 stack-full"><a href="{{ route('products.show', $item->product) }}" class="text-decoration-none text-reset fw-semibold">{{ $item->product?->label }}</a></td>
                                    <td class="text-end tabular" data-label="Pedido">{{ $num($item->quantity) }}</td>
                                    <td class="text-end tabular {{ $item->pendingQuantity() > 0 && ! $pu->isCancelled() ? 'text-warning-emphasis fw-semibold' : 'text-success' }}" data-label="Recibido">{{ $num($item->received_quantity) }}</td>
                                    <td class="text-end tabular small" data-label="Costo">{{ $money($item->unit_cost) }}@if((float) $item->discount)<div class="text-muted">− {{ $money($item->discount) }}</div>@endif</td>
                                    <td class="text-end tabular pe-3 fw-semibold" data-label="Total">{{ $money($item->total) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($pu->notes)<div class="card-body border-top small"><span class="text-muted">Nota:</span> {{ $pu->notes }}</div>@endif
            </div>

            @if($pu->isPending() && $can('purchases.edit'))
                <form method="POST" action="{{ route('purchases.receive', $pu) }}" class="card mb-3 border-warning">
                    @csrf
                    <div class="card-header bg-warning-subtle"><i class="bi bi-truck"></i> Recibir mercadería <span class="small text-muted">· entra al stock de {{ $pu->branch?->name }}</span></div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead><tr><th class="ps-3">Producto</th><th style="width: 8rem">Llegó</th><th>Lote / vence</th></tr></thead>
                            <tbody>
                                @foreach($pu->items->filter(fn ($i) => $i->pendingQuantity() > 0) as $item)
                                    <tr>
                                        <td class="ps-3">{{ $item->product?->name }}<div class="small text-muted">faltan {{ $num($item->pendingQuantity()) }}</div></td>
                                        <td><input type="number" name="lines[{{ $item->id }}][quantity]" class="form-control form-control-sm" step="0.01" min="0" max="{{ $item->pendingQuantity() }}" value="{{ $item->pendingQuantity() }}" aria-label="Cantidad recibida"></td>
                                        <td>
                                            @if($item->product?->track_batches)
                                                <div class="d-flex gap-1">
                                                    <input type="text" name="lines[{{ $item->id }}][lot]" class="form-control form-control-sm" placeholder="Lote" maxlength="60">
                                                    <input type="date" name="lines[{{ $item->id }}][expires_at]" class="form-control form-control-sm" title="Vence">
                                                </div>
                                            @else<span class="small text-muted">—</span>@endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="card-body border-top d-flex gap-2 flex-wrap">
                        <input type="text" name="notes" class="form-control" maxlength="255" placeholder="Nota (opcional): llegó incompleto, caja dañada…" style="max-width: 28rem" aria-label="Nota">
                        <button class="btn btn-warning ms-auto" type="submit"><i class="bi bi-box-arrow-in-down"></i> Registrar recepción</button>
                    </div>
                </form>
                @if($pu->status === 'parcial')
                    <details class="card mb-3">
                        <summary class="card-body small fw-semibold"><i class="bi bi-slash-circle"></i> No va a llegar lo que falta: cerrar con lo recibido</summary>
                        <form method="POST" action="{{ route('purchases.close', $pu) }}" class="card-body pt-0 d-flex gap-2">
                            @csrf
                            <input type="text" name="closing_notes" class="form-control" maxlength="255" placeholder="Motivo (el proveedor no tenía stock…)" required aria-label="Motivo">
                            <button class="btn btn-outline-dark text-nowrap" type="submit">Cerrar compra</button>
                        </form>
                    </details>
                @endif
            @endif

            @if($pu->receptions->isNotEmpty())
                <div class="card mb-3">
                    <div class="card-header"><i class="bi bi-box-arrow-in-down"></i> Recepciones</div>
                    <ul class="list-group list-group-flush">
                        @foreach($pu->receptions as $r)
                            <li class="list-group-item small">
                                <div class="d-flex justify-content-between"><span class="fw-semibold">{{ $r->received_at->format('d/m/Y H:i') }}</span><span class="text-muted">{{ $r->user?->name }}</span></div>
                                <div class="text-muted">{{ $r->items->map(fn ($ri) => $num($ri->quantity) . ' × ' . $ri->item?->product?->name . ($ri->lot ? " (lote {$ri->lot})" : ''))->implode(' · ') }}</div>
                                @if($r->notes)<div>{{ $r->notes }}</div>@endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-body">
                    <dl class="info-list mb-0">
                        <dt>Subtotal</dt><dd class="text-end tabular">{{ $money($pu->subtotal) }}</dd>
                        @if((float) $pu->discount)<dt>Descuento</dt><dd class="text-end tabular">− {{ $money($pu->discount) }}</dd>@endif
                        <dt class="fw-bold text-dark">Total</dt><dd class="text-end tabular fs-5 fw-bold">{{ $money($pu->total) }}</dd>
                        <dt>Pagado</dt><dd class="text-end tabular text-success">{{ $money($pu->paid) }}</dd>
                        @unless($pu->isCancelled())
                            <dt>Saldo</dt><dd class="text-end tabular fw-bold {{ (float) $pu->balance > 0 ? 'text-danger' : '' }}">{{ $money($pu->balance) }}</dd>
                            @if($pu->due_date && (float) $pu->balance > 0)<dt>Paga hasta</dt><dd class="text-end {{ $pu->isOverdue() ? 'text-danger fw-semibold' : '' }}">{{ $pu->due_date->format('d/m/Y') }}</dd>@endif
                        @endunless
                    </dl>
                </div>
            </div>

            @if(! $pu->isCancelled() && (float) $pu->balance > 0 && $can('purchases.edit'))
                <form method="POST" action="{{ route('purchases.pay', $pu) }}" class="card mb-3">
                    @csrf
                    <div class="card-header"><i class="bi bi-cash-coin"></i> Registrar pago</div>
                    <div class="card-body d-flex flex-column gap-2">
                        <div class="input-group"><span class="input-group-text">{{ \App\Support\Money::symbol($tenantCompany) }}</span><input type="number" name="amount" class="form-control" step="0.01" min="0.01" max="{{ (float) $pu->balance }}" value="{{ old('amount', (float) $pu->balance) }}" required aria-label="Monto"></div>
                        <input type="text" name="reference" class="form-control form-control-sm" maxlength="80" placeholder="Referencia / Nº de transferencia (opcional)" value="{{ old('reference') }}" aria-label="Referencia">
                        @include('admin.finance.partials.source-picker', ['source' => $source, 'id' => 'pay'])
                        <button class="btn btn-primary w-100" type="submit"><i class="bi bi-check2-circle"></i> Registrar pago</button>
                    </div>
                </form>
            @endif

            <div class="card">
                <div class="card-header"><i class="bi bi-receipt"></i> Pagos</div>
                <ul class="list-group list-group-flush">
                    @forelse($pu->payments as $pay)
                        <li class="list-group-item {{ $pay->isCancelled() ? 'text-muted' : '' }}">
                            <div class="d-flex justify-content-between gap-2">
                                <span class="fw-semibold {{ $pay->isCancelled() ? 'text-decoration-line-through' : '' }}">{{ $money($pay->amount) }}</span>
                                <span class="small">{{ $pay->paid_at->format('d/m/Y') }}</span>
                            </div>
                            <div class="small text-muted">{{ collect([$pay->source_label, $pay->reference ? 'Ref. ' . $pay->reference : null, $pay->user?->name])->filter()->implode(' · ') }}</div>
                            @if($pay->isCancelled())
                                <div class="small">Anulado: {{ $pay->cancel_reason }}</div>
                            @elseif($can('purchases.delete'))
                                <details class="small mt-1">
                                    <summary class="text-danger">Anular pago</summary>
                                    <form method="POST" action="{{ route('purchases.payment.cancel', [$pu, $pay]) }}" class="d-flex gap-1 mt-1">
                                        @csrf
                                        <input type="text" name="cancel_reason" class="form-control form-control-sm" placeholder="Motivo" maxlength="255" required aria-label="Motivo">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Anular</button>
                                    </form>
                                </details>
                            @endif
                        </li>
                    @empty
                        <li class="list-group-item small text-muted">Sin pagos: es una compra a crédito.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>

@if(! $pu->isCancelled() && $can('purchases.delete'))
    <div class="modal fade" id="cancel-purchase" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="POST" action="{{ route('purchases.cancel', $pu) }}">
                @csrf
                <div class="modal-header"><h5 class="modal-title">Anular la compra {{ $pu->code }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                <div class="modal-body"><input type="text" name="cancel_reason" class="form-control" maxlength="255" placeholder="Motivo" required aria-label="Motivo"></div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Volver</button><button class="btn btn-danger" type="submit">Anular compra</button></div>
            </form>
        </div>
    </div>
@endif
@include('admin.finance.partials.styles')
@endsection
