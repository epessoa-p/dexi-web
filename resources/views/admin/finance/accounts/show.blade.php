@extends('layouts.app')

@section('title', $account->name)

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
    $symbol = \App\Support\Money::symbol($tenantCompany);
    $canMove = $can('account_movements.create') && $account->active;
    $transferTab = old('to_account_id') !== null;
@endphp
<div class="container-fluid">
    @include('admin.finance.partials.errors')
    <div class="page-header">
        <a href="{{ route('finance-accounts.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Cuentas</a>
        <div class="d-flex gap-2">
            @if($can('accounts.edit'))
                <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editAccount"><i class="bi bi-pencil"></i> Editar</button>
            @endif
            @if($can('accounts.delete'))
                <form method="POST" action="{{ route('finance-accounts.destroy', $account) }}" onsubmit="return confirm('¿Eliminar la cuenta {{ $account->name }}? Su historial se conserva.')">
                    @csrf @method('DELETE')
                    <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-trash"></i></button>
                </form>
            @endif
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <div class="d-flex gap-3 align-items-center mb-3">
                        <div class="account-icon account-{{ $account->type }}"><i class="bi {{ $account->icon }}"></i></div>
                        <div class="min-w-0">
                            <h1 class="h4 mb-0 text-truncate">{{ $account->name }}</h1>
                            <div class="small text-muted">
                                {{ $account->type_label }}@if($account->bank_name) · {{ $account->bank_name }}@endif
                                @if($account->account_number) · Nº {{ $account->account_number }}@endif
                            </div>
                        </div>
                    </div>
                    <div class="text-muted small">Saldo actual</div>
                    <div class="display-6 fw-bold {{ (float) $account->balance < 0 ? 'text-danger' : '' }}">{{ $money($account->balance) }}</div>
                    @unless($account->active)<span class="badge bg-secondary mt-2">Inactiva</span>@endunless
                    @if($account->branch)<div class="small text-muted mt-2"><i class="bi bi-diagram-2"></i> {{ $account->branch->name }}</div>@endif
                    @if($account->notes)<div class="small text-muted mt-2">{{ $account->notes }}</div>@endif
                </div>
            </div>

            @if($canMove)
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <ul class="nav nav-pills nav-fill mb-3" role="tablist">
                            <li class="nav-item"><button class="nav-link {{ $transferTab ? '' : 'active' }}" data-bs-toggle="pill" data-bs-target="#pane-move" type="button"><i class="bi bi-arrow-down-up"></i> Ingreso / egreso</button></li>
                            <li class="nav-item"><button class="nav-link {{ $transferTab ? 'active' : '' }}" data-bs-toggle="pill" data-bs-target="#pane-transfer" type="button" @disabled($others->isEmpty())><i class="bi bi-arrow-left-right"></i> Transferir</button></li>
                        </ul>
                        <div class="tab-content">
                            <form class="tab-pane fade {{ $transferTab ? '' : 'show active' }} row g-2" id="pane-move" method="POST" action="{{ route('finance-accounts.move', $account) }}">
                                @csrf
                                <div class="col-12">
                                    <div class="btn-group w-100" role="group">
                                        <input type="radio" class="btn-check" name="type" id="type-in" value="ingreso" @checked(old('type', 'ingreso') === 'ingreso')>
                                        <label class="btn btn-outline-success" for="type-in"><i class="bi bi-plus-lg"></i> Ingreso</label>
                                        <input type="radio" class="btn-check" name="type" id="type-out" value="egreso" @checked(old('type') === 'egreso')>
                                        <label class="btn btn-outline-danger" for="type-out"><i class="bi bi-dash-lg"></i> Egreso</label>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">Importe *</label>
                                    <div class="input-group"><span class="input-group-text">{{ $symbol }}</span><input type="number" name="amount" step="0.01" min="0.01" class="form-control" value="{{ $transferTab ? '' : old('amount') }}" required></div>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">Fecha</label>
                                    <input type="date" name="occurred_on" class="form-control" value="{{ old('occurred_on', now()->toDateString()) }}" max="{{ now()->toDateString() }}">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small mb-1">Concepto *</label>
                                    <input type="text" name="concept" class="form-control" value="{{ $transferTab ? '' : old('concept') }}" maxlength="255" placeholder="Aporte de capital, depósito del día, comisión del banco…" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small mb-1">Referencia</label>
                                    <input type="text" name="reference" class="form-control" value="{{ old('reference') }}" maxlength="80">
                                </div>
                                <div class="col-12"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-check-lg"></i> Registrar</button></div>
                            </form>

                            <form class="tab-pane fade {{ $transferTab ? 'show active' : '' }} row g-2" id="pane-transfer" method="POST" action="{{ route('finance-accounts.transfer', $account) }}">
                                @csrf
                                <div class="col-12">
                                    <label class="form-label small mb-1">Hacia la cuenta *</label>
                                    <select name="to_account_id" class="form-select" required>
                                        @foreach($others as $other)
                                            <option value="{{ $other->id }}" @selected((string) old('to_account_id') === (string) $other->id)>{{ $other->name }} · {{ $money($other->balance) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">Importe *</label>
                                    <div class="input-group"><span class="input-group-text">{{ $symbol }}</span><input type="number" name="amount" step="0.01" min="0.01" max="{{ max(0, (float) $account->balance) }}" class="form-control" value="{{ $transferTab ? old('amount') : '' }}" required></div>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">Fecha</label>
                                    <input type="date" name="occurred_on" class="form-control" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small mb-1">Concepto</label>
                                    <input type="text" name="concept" class="form-control" value="{{ $transferTab ? old('concept') : '' }}" maxlength="200" placeholder="Transferencia">
                                </div>
                                <div class="col-12"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-arrow-left-right"></i> Transferir</button></div>
                            </form>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 pt-3"><h6 class="fw-bold mb-0"><i class="bi bi-journal-text"></i> Extracto</h6></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr><th class="ps-3">Fecha</th><th>Concepto</th><th>Origen</th><th class="text-end">Ingreso</th><th class="text-end">Egreso</th><th class="text-end">Saldo</th><th class="pe-3"></th></tr>
                            </thead>
                            <tbody>
                                @forelse($movements as $m)
                                    <tr class="{{ $m->isCancelled() ? 'movement-cancelled' : '' }}">
                                        <td class="ps-3 small text-nowrap">{{ $m->occurred_on->format('d/m/Y') }}</td>
                                        <td>
                                            @if($m->origin === 'gasto' && $m->source)
                                                <a href="{{ route('expenses.show', $m->source) }}" class="text-decoration-none">{{ $m->concept }}</a>
                                            @else
                                                {{ $m->concept }}
                                            @endif
                                            <div class="small text-muted">{{ $m->creator?->name }}@if($m->reference) · Ref. {{ $m->reference }}@endif</div>
                                        </td>
                                        <td class="small"><span class="badge bg-light text-dark border">{{ $m->origin_label }}</span></td>
                                        <td class="text-end text-success fw-semibold text-nowrap">{{ $m->isIncome() ? $money($m->amount) : '' }}</td>
                                        <td class="text-end text-danger fw-semibold text-nowrap">{{ $m->isIncome() ? '' : $money($m->amount) }}</td>
                                        <td class="text-end fw-semibold text-nowrap">{{ $money($m->balance_after) }}</td>
                                        <td class="pe-3 text-end no-strike">
                                            @if($m->isCancelled())
                                                <span class="badge bg-secondary">Anulado</span>
                                            @elseif($m->isCancellable() && $can('account_movements.delete'))
                                                <form method="POST" action="{{ route('finance-accounts.movements.cancel', $m) }}" class="d-inline"
                                                      onsubmit="return confirm('¿Anular este movimiento? Se escribe el movimiento contrario{{ $m->origin === 'transferencia' ? ' en las dos cuentas' : '' }}.')">
                                                    @csrf
                                                    <button class="btn btn-sm btn-outline-danger" type="submit" title="Anular"><i class="bi bi-x-lg"></i></button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-center text-muted py-5">Sin movimientos todavía.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="mt-3 d-flex justify-content-center">{{ $movements->links() }}</div>
        </div>
    </div>
</div>

@if($can('accounts.edit'))
    @include('admin.finance.accounts.form-modal', ['account' => $account, 'modalId' => 'editAccount'])
@endif
@include('admin.finance.partials.styles')
@endsection
