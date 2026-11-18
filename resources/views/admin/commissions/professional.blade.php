@extends('layouts.app')

@section('title', "Comisiones · {$professional->full_name}")

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
    $openSettle = request()->boolean('pay') || $errors->any();
@endphp
<div class="container-fluid" style="max-width: 1100px;">
    @include('admin.finance.partials.errors')

    <div class="page-header">
        <div class="d-flex align-items-center gap-3">
            <span class="avatar avatar-lg" style="--tone: {{ $professional->color }}">{{ mb_strtoupper(mb_substr($professional->full_name, 0, 1)) }}</span>
            <div>
                <a href="{{ route('commissions.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Comisiones</a>
                <h1 class="mt-1 mb-0">{{ $professional->full_name }}</h1>
                <p class="page-subtitle mb-0">{{ $professional->specialty?->name ?? 'Odontología' }}</p>
            </div>
        </div>
        <form method="GET" class="page-actions d-flex align-items-center gap-2 flex-wrap">
            <input type="date" name="from" value="{{ $from->toDateString() }}" class="form-control form-control-sm w-auto" aria-label="Desde">
            <input type="date" name="to" value="{{ $to->toDateString() }}" class="form-control form-control-sm w-auto" aria-label="Hasta">
            <button class="btn btn-sm btn-light" type="submit"><i class="bi bi-funnel"></i> Ver</button>
        </form>
    </div>

    @if($can('commissions.create') && $pendingAll > 0)
        <details class="card mb-3 settle-card" id="pagar" @if($openSettle) open @endif>
            <summary class="card-body d-flex align-items-center gap-2 flex-wrap">
                <span class="settle-icon"><i class="bi bi-cash-coin"></i></span>
                <span class="fw-semibold">Pagarle lo pendiente</span>
                <span class="small text-muted">{{ $pendingCount }} {{ $pendingCount === 1 ? 'comisión' : 'comisiones' }} desde el {{ \Carbon\Carbon::parse($settleFrom)->format('d/m/Y') }}</span>
                <span class="ms-auto fs-5 fw-bold tabular text-success">{{ $money($pendingAll) }}</span>
            </summary>
            <form method="POST" action="{{ route('commissions.settle', $professional) }}" class="card-body pt-0">
                @csrf
                <div class="row g-3">
                    <div class="col-sm-6 col-md-3">
                        <label class="form-label" for="period_start">Desde</label>
                        <input type="date" id="period_start" name="period_start" class="form-control @error('period_start') is-invalid @enderror" value="{{ old('period_start', $settleFrom) }}" required>
                        @error('period_start')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <label class="form-label" for="period_end">Hasta</label>
                        <input type="date" id="period_end" name="period_end" class="form-control @error('period_end') is-invalid @enderror" value="{{ old('period_end', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required>
                        @error('period_end')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="notes">Nota <span class="text-muted fw-normal">(opcional)</span></label>
                        <input type="text" id="notes" name="notes" class="form-control" value="{{ old('notes') }}" maxlength="1000" placeholder="Quincena, adelanto…">
                    </div>
                    <div class="col-12">
                        <div class="form-text mb-2">Se pagan las comisiones pendientes con fecha dentro del período; los ajustes negativos (ventas anuladas) se descuentan. Queda como un gasto de la categoría COMISIONES a su nombre.</div>
                        @include('admin.finance.partials.source-picker', ['source' => $source, 'id' => 'settle'])
                    </div>
                    <div class="col-12"><button class="btn btn-success" type="submit"><i class="bi bi-check2-circle"></i> Pagar liquidación</button></div>
                </div>
            </form>
        </details>
    @endif

    @include('admin.commissions.partials.rows', ['rows' => $rows, 'pendingAll' => $pendingAll, 'linkSales' => $can('sales.view')])
</div>
@include('admin.finance.partials.styles')
@push('styles')
<style>
    .settle-card summary { cursor: pointer; list-style: none; }
    .settle-card summary::-webkit-details-marker { display: none; }
    .settle-icon { width: 2.25rem; height: 2.25rem; border-radius: .7rem; display: grid; place-items: center; background: #e7f6ee; color: #059669; font-size: 1.1rem; }
</style>
@endpush
@endsection
