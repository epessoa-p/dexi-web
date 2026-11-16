@extends('layouts.app')

@section('title', \App\Http\Controllers\Admin\ExpenseController::CREATE_LABELS[$group])

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $title = \App\Http\Controllers\Admin\ExpenseController::CREATE_LABELS[$group];
    $value = fn (string $field, $default = null) => old($field, $preset[$field] ?? null) ?? $default;
    // Lo que se completará solo si no escriben la descripción.
    $placeholder = match ($group) {
        'proveedor' => 'Pago a (el proveedor elegido)',
        'personal' => 'Pago a (la persona elegida)',
        'basico' => 'Servicio · ' . now()->locale('es')->translatedFormat('F Y'),
        default => 'En qué se gastó',
    };
    $intro = [
        'general' => 'Comida, pasajes, envíos, limpieza… lo que no es un servicio fijo ni un pago a una persona.',
        'proveedor' => 'Un pago a un depósito dental, laboratorio, técnico de equipos… (las compras con ingreso de insumos llegarán con Compras).',
        'personal' => 'Sueldos, adelantos u honorarios del personal.',
        'basico' => 'Luz, agua, alquiler, internet… el control del mes los marca como pagados.',
    ][$group];
@endphp
<div class="container-fluid" style="max-width: 860px;">
    <div class="page-header">
        <div>
            <h1 class="mb-1">{{ $title }}</h1>
            <p class="text-muted mb-0">{{ $intro }}</p>
        </div>
        <a href="{{ route('expenses.index', ['tab' => $group]) }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>

    <form action="{{ route('expenses.store') }}" method="POST" enctype="multipart/form-data" class="card border-0 shadow-sm">
        @csrf
        <input type="hidden" name="group" value="{{ $group }}">
        <div class="card-body p-4 row g-3">
            @if($group === 'proveedor' || $group === 'personal')
                <div class="col-md-{{ $categories->count() > 1 ? 7 : 12 }}">
                    @if($group === 'proveedor')
                        <label class="form-label">Proveedor <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <select name="supplier_id" class="form-select @error('supplier_id') is-invalid @enderror" required>
                                <option value="">Elige…</option>
                                @foreach($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}" @selected((string) $value('supplier_id') === (string) $supplier->id)>{{ $supplier->name }}</option>
                                @endforeach
                            </select>
                            @if($can('suppliers.create'))
                                <a href="{{ route('suppliers.create', ['return' => 'expense']) }}" class="btn btn-outline-secondary" title="Al guardarlo vuelves aquí con el proveedor elegido"><i class="bi bi-plus-lg"></i> Nuevo</a>
                            @endif
                        </div>
                        @error('supplier_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        @if($suppliers->isEmpty())<div class="form-text">Todavía no hay proveedores.</div>@endif
                    @else
                        <label class="form-label">Personal <span class="text-danger">*</span></label>
                        <select name="personal_id" class="form-select @error('personal_id') is-invalid @enderror" required>
                            <option value="">Elige…</option>
                            @foreach($people as $person)
                                <option value="{{ $person->id }}" @selected((string) $value('personal_id') === (string) $person->id)>{{ $person->full_name }}</option>
                            @endforeach
                        </select>
                        @error('personal_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        @if($people->isEmpty())<div class="form-text">No hay personal activo: agrégalo en Equipo › Odontólogos y personal.</div>@endif
                    @endif
                </div>
                {{-- La categoría se elige sola; si hay más de una del grupo, se puede cambiar. --}}
                @if($categories->count() > 1)
                    <div class="col-md-5">
                        <label class="form-label">Concepto</label>
                        <select name="expense_category_id" class="form-select">
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" @selected((string) $value('expense_category_id') === (string) $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            @else
                <div class="col-md-{{ $group === 'basico' ? 12 : 5 }}">
                    <label class="form-label">{{ $group === 'basico' ? 'Servicio' : 'Categoría' }} <span class="text-danger">*</span></label>
                    <select name="expense_category_id" class="form-select @error('expense_category_id') is-invalid @enderror" required>
                        <option value="">Elige…</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" @selected((string) $value('expense_category_id') === (string) $category->id)>{{ $group === 'basico' ? $category->service_label : $category->name }}</option>
                        @endforeach
                    </select>
                    @error('expense_category_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
            @endif

            <div class="col-md-{{ $group === 'general' ? 7 : 12 }}">
                <label class="form-label">Descripción @if($group === 'general')<span class="text-danger">*</span>@else<small class="text-muted">(opcional)</small>@endif</label>
                <input type="text" name="concept" class="form-control @error('concept') is-invalid @enderror" value="{{ old('concept') }}" maxlength="255"
                       placeholder="{{ $placeholder }}" @required($group === 'general')>
                @error('concept')<div class="invalid-feedback">{{ $message }}</div>@enderror
                @if($group !== 'general')<div class="form-text">Si la dejas vacía, se completa sola.</div>@endif
            </div>

            <div class="col-md-4">
                <label class="form-label">Importe <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text">{{ \App\Support\Money::symbol($tenantCompany) }}</span>
                    <input type="number" name="amount" step="0.01" min="0.01" class="form-control @error('amount') is-invalid @enderror" value="{{ $value('amount') }}" required>
                </div>
                @error('amount')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label">Fecha <span class="text-danger">*</span></label>
                <input type="date" name="spent_at" class="form-control @error('spent_at') is-invalid @enderror" value="{{ old('spent_at', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required>
                @error('spent_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            @if($branches->count() > 1)
                <div class="col-md-4">
                    <label class="form-label">Sucursal</label>
                    <select name="branch_id" class="form-select">
                        <option value="">La de la caja o cuenta</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((string) old('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="col-12">
                @include('admin.finance.partials.source-picker', ['source' => $source, 'id' => 'expense'])
            </div>

            <div class="col-md-6">
                <label class="form-label">Referencia <small class="text-muted">(nº de factura o recibo)</small></label>
                <input type="text" name="reference" class="form-control" value="{{ old('reference') }}" maxlength="80">
            </div>
            <div class="col-md-6">
                <label class="form-label">Comprobante <small class="text-muted">(foto o PDF)</small></label>
                <input type="file" name="receipt" accept="image/*,application/pdf" class="form-control @error('receipt') is-invalid @enderror">
                @error('receipt')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12">
                <label class="form-label">Notas</label>
                <textarea name="notes" rows="2" class="form-control">{{ old('notes') }}</textarea>
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Registrar</button>
                <a href="{{ route('expenses.index', ['tab' => $group]) }}" class="btn btn-light border">Cancelar</a>
            </div>
        </div>
    </form>
</div>
@endsection
