@extends('layouts.app')

@section('title', "Gasto {$expense->code}")

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
    $e = $expense;
    $group = $e->category?->group ?? 'general';
    $rows = array_filter([
        'Categoría' => $e->category ? $e->category->name . ' · ' . $e->category->type_label : null,
        'Proveedor' => $e->supplier?->name,
        'Personal' => $e->personal?->full_name,
        'Fecha' => $e->spent_at->format('d/m/Y'),
        'Salió de' => $e->source_label . ($e->paymentMethod && $e->source === 'cash' ? " ({$e->paymentMethod->name})" : ''),
        'Sucursal' => $e->branch?->name,
        'Referencia' => $e->reference,
        'Registró' => $e->user ? $e->user->name . ' · ' . $e->created_at->format('d/m/Y H:i') : null,
        'Notas' => $e->notes,
    ]);
@endphp
<div class="container-fluid" style="max-width: 760px;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="{{ route('expenses.index', ['tab' => $group]) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Gastos</a>
        @if($e->isCancelled())<span class="badge bg-secondary fs-6">Anulado</span>@endif
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body p-4">
            <div class="text-muted small">{{ $e->code }}</div>
            <h1 class="h3 mb-1 {{ $e->isCancelled() ? 'text-decoration-line-through text-muted' : '' }}">{{ $e->concept }}</h1>
            <div class="display-6 fw-bold mb-3 {{ $e->isCancelled() ? 'text-muted' : '' }}">{{ $money($e->amount) }}</div>
            <dl class="row mb-0">
                @foreach($rows as $label => $value)
                    <dt class="col-sm-4 text-muted fw-normal">{{ $label }}</dt>
                    <dd class="col-sm-8">
                        @if($label === 'Salió de' && $e->source === 'account' && $e->account && $can('accounts.view'))
                            <a href="{{ route('finance-accounts.show', $e->account) }}">{{ $value }}</a>
                        @else
                            {{ $value }}
                        @endif
                    </dd>
                @endforeach
            </dl>
            @if($e->receipt_url)
                <a href="{{ $e->receipt_url }}" target="_blank" class="btn btn-sm btn-outline-secondary mt-2"><i class="bi bi-paperclip"></i> Ver comprobante</a>
            @endif
        </div>
    </div>

    @if($e->isCancelled())
        <div class="alert alert-secondary">
            <i class="bi bi-x-octagon"></i> Anulado el {{ $e->cancelled_at?->format('d/m/Y H:i') }}@if($e->canceller) por {{ $e->canceller->name }}@endif.
            <div class="small">Motivo: {{ $e->cancel_reason }}</div>
        </div>
    @elseif($e->commission_settlement_id)
        <div class="alert alert-info">
            <i class="bi bi-person-check"></i> Es el pago de una liquidación de comisiones: se anula desde Comisiones.
        </div>
    @elseif($can('expenses.delete'))
        <details class="card border-0 shadow-sm">
            <summary class="card-body text-danger fw-semibold"><i class="bi bi-x-octagon"></i> Anular gasto</summary>
            <form method="POST" action="{{ route('expenses.cancel', $e) }}" class="card-body pt-0 d-flex flex-column gap-2"
                  onsubmit="return confirm('¿Anular el gasto {{ $e->code }}? El dinero vuelve a {{ $e->source === 'external' ? 'ningún lado (se pagó por fuera)' : 'donde salió' }}.')">
                @csrf
                <input type="text" name="cancel_reason" class="form-control @error('cancel_reason') is-invalid @enderror" placeholder="Motivo (obligatorio)" maxlength="255" required>
                @error('cancel_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="small text-muted">
                    @if($e->source === 'account') Se devuelve {{ $money($e->amount) }} a {{ $e->account?->name }}.
                    @elseif($e->source === 'cash') Se devuelve {{ $money($e->amount) }} a la caja (al mismo turno si sigue abierto; si no, a otro abierto).
                    @endif
                    Un gasto no se edita: para corregirlo, anúlalo y regístralo de nuevo.
                </div>
                <button class="btn btn-outline-danger align-self-start" type="submit">Anular</button>
            </form>
        </details>
    @endif
</div>
@endsection
