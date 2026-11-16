@extends('layouts.app')

@section('title', 'Cuentas')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
@endphp
<div class="container-fluid">
    @include('admin.finance.partials.errors')
    <div class="page-header">
        <div>
            <h1 class="mb-1"><i class="bi bi-bank"></i> Cuentas</h1>
            <p class="text-muted mb-0">Dónde está el dinero de la clínica: banco, caja chica, billeteras…</p>
        </div>
        @if($can('accounts.create'))
            <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#accountModal">
                <i class="bi bi-plus-lg"></i> Nueva cuenta
            </button>
        @endif
    </div>

    <div class="finance-total card border-0 shadow-sm mb-4">
        <div class="card-body d-flex align-items-center gap-3">
            <div class="finance-total-icon"><i class="bi bi-safe2"></i></div>
            <div>
                <div class="text-muted small">Total en cuentas activas</div>
                <div class="fs-3 fw-bold">{{ $money($total) }}</div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        @forelse($accounts as $account)
            <div class="col-sm-6 col-xl-4">
                <a href="{{ route('finance-accounts.show', $account) }}" class="account-card card border-0 shadow-sm h-100 text-decoration-none {{ $account->active ? '' : 'opacity-50' }}">
                    <div class="card-body d-flex gap-3 align-items-start">
                        <div class="account-icon account-{{ $account->type }}"><i class="bi {{ $account->icon }}"></i></div>
                        <div class="flex-fill min-w-0">
                            <div class="fw-semibold text-dark text-truncate">{{ $account->name }}</div>
                            <div class="small text-muted">
                                {{ $account->type_label }}@if($account->bank_name) · {{ $account->bank_name }}@endif
                                @if($account->branch) · {{ $account->branch->name }}@endif
                                @unless($account->active)<span class="badge bg-secondary ms-1">Inactiva</span>@endunless
                            </div>
                            <div class="fs-4 fw-bold mt-2 {{ (float) $account->balance < 0 ? 'text-danger' : 'text-dark' }}">{{ $money($account->balance) }}</div>
                        </div>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </div>
                </a>
            </div>
        @empty
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center text-muted py-5">
                        <i class="bi bi-bank fs-1 d-block mb-2"></i>
                        Todavía no hay cuentas. Crea la primera (por ejemplo, la cuenta del banco o la caja chica) con su saldo actual.
                    </div>
                </div>
            </div>
        @endforelse
    </div>
</div>

@if($can('accounts.create'))
    @include('admin.finance.accounts.form-modal', ['account' => null, 'modalId' => 'accountModal'])
@endif

@include('admin.finance.partials.styles')
@endsection
