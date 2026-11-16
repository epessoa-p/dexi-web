@extends('layouts.app')

@section('title', 'Métodos de pago')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $icons = ['cash' => 'bi-cash', 'transfer' => 'bi-bank', 'qr' => 'bi-qr-code', 'card' => 'bi-credit-card', 'other' => 'bi-three-dots'];
    $modals = $methods->map(fn ($m) => ['id' => "pm-{$m->id}", 'method' => $m])->prepend(['id' => 'newMethod', 'method' => null]);
@endphp
<div class="container-fluid" style="max-width: 860px;">
    <div class="page-header">
        <div>
            <h1><i class="bi bi-credit-card"></i> Métodos de pago</h1>
            <p class="page-subtitle">Con qué pagan los pacientes y con qué paga la clínica. Solo el <strong>efectivo</strong> entra al arqueo de la caja.</p>
        </div>
        @if($can('payment_methods.create'))
            <div class="page-actions">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newMethod"><i class="bi bi-plus-lg"></i> Nuevo método</button>
            </div>
        @endif
    </div>

    @include('admin.finance.partials.errors')

    <div class="card">
        <ul class="list-group list-group-flush">
            @foreach($methods as $method)
                <li class="list-group-item d-flex align-items-center gap-3 py-3 {{ $method->active ? '' : 'text-muted' }}">
                    <div class="kpi-icon" style="--tone: {{ $method->isCash() ? '#059669' : '#0284c7' }}; width:2.4rem; height:2.4rem; font-size:1.05rem;"><i class="bi {{ $icons[$method->type] ?? 'bi-wallet2' }}"></i></div>
                    <div class="flex-fill min-w-0">
                        <div class="fw-semibold">{{ $method->name }}</div>
                        <div class="small text-muted">
                            {{ $method->type_label }}
                            @if($method->isCash()) · <span class="text-success">cuenta para el arqueo</span>@endif
                            @if($method->requires_reference) · pide referencia @endif
                        </div>
                    </div>
                    @unless($method->active)<span class="badge bg-secondary">Inactivo</span>@endunless
                    @if($can('payment_methods.edit'))
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#pm-{{ $method->id }}" title="Editar"><i class="bi bi-pencil"></i></button>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
</div>

@foreach($modals as $modal)
    @continue($modal["method"] ? ! $can("payment_methods.edit") : ! $can("payment_methods.create"))
    @php
        $m = $modal['method'];

        $reopen = $errors->any() && old('_modal') === $modal['id'];
        $val = fn (string $field, $default = null) => $reopen ? old($field, $default) : ($m?->{$field} ?? $default);
    @endphp
    <div class="modal fade" id="{{ $modal['id'] }}" tabindex="-1" aria-hidden="true" @if($reopen) data-reopen @endif>
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="POST" action="{{ $m ? route('payment-methods.update', $m) : route('payment-methods.store') }}">
                @csrf
                @if($m) @method('PUT') @endif
                <input type="hidden" name="_modal" value="{{ $modal['id'] }}">
                <div class="modal-header"><h5 class="modal-title">{{ $m ? 'Editar método' : 'Nuevo método de pago' }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                <div class="modal-body">
                    <label class="form-label" for="{{ $modal['id'] }}-name">Nombre <span class="text-danger">*</span></label>
                    <input type="text" id="{{ $modal['id'] }}-name" name="name" class="form-control mb-3" maxlength="60" value="{{ $val('name') }}" placeholder="QR Banco Unión, Tigo Money…" required>
                    <label class="form-label">Tipo</label>
                    <div class="row g-2 mb-3">
                        @foreach(\App\Models\PaymentMethod::TYPES as $key => $label)
                            <div class="col-6">
                                <input type="radio" class="btn-check" name="type" id="{{ $modal['id'] }}-type-{{ $key }}" value="{{ $key }}" @checked($val('type', 'qr') === $key)>
                                <label class="btn btn-outline-primary w-100 text-start" for="{{ $modal['id'] }}-type-{{ $key }}"><i class="bi {{ $icons[$key] }}"></i> {{ $label }}</label>
                            </div>
                        @endforeach
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="requires_reference" value="1" id="{{ $modal['id'] }}-ref" @checked($val('requires_reference'))>
                        <label class="form-check-label" for="{{ $modal['id'] }}-ref">Pide nº de referencia (comprobante, voucher)</label>
                    </div>
                    @if($m)
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" name="active" value="1" id="{{ $modal['id'] }}-active" @checked($val('active', true))>
                            <label class="form-check-label" for="{{ $modal['id'] }}-active">Activo</label>
                        </div>
                    @endif
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Guardar</button></div>
            </form>
        </div>
    </div>
@endforeach
@include('admin.finance.partials.styles')
@endsection
