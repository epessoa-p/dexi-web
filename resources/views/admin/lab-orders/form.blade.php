@extends('layouts.app')

@section('title', $order ? "Editar {$order->code}" : 'Nueva orden de laboratorio')

@section('page')
@php
    $o = $order;
    $val = fn (string $field, $default = null) => old($field, $o?->{$field} ?? $default);
    $err = fn (string $field) => $errors->has($field) ? 'is-invalid' : '';
    $symbol = \App\Support\Money::symbol($tenantCompany);
    $sortedLabs = $labs->sortBy(fn ($l) => [in_array($l->id, $usedLabs) ? 0 : 1, $l->name]);
    $preItem = (string) old('treatment_plan_item_id', $o?->treatment_plan_item_id ?? request('treatment_plan_item_id'));
@endphp
<div class="container-fluid" style="max-width: 960px;">
    <div class="page-header">
        <div>
            <a href="{{ $o ? route('lab-orders.show', $o) : route('patients.show', [$patient, 'tab' => 'tratamiento']) }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> {{ $o ? $o->code : $patient->full_name }}</a>
            <h1 class="mt-1">{{ $o ? "Editar {$o->code}" : 'Nueva orden de laboratorio' }}</h1>
            <p class="page-subtitle">{{ $patient->full_name }} · {{ $patient->code }}</p>
        </div>
    </div>

    @include('admin.finance.partials.errors')

    <form method="POST" action="{{ $o ? route('lab-orders.update', $o) : route('lab-orders.store') }}" novalidate>
        @csrf
        @if($o) @method('PUT') @else <input type="hidden" name="patient_id" value="{{ $patient->id }}"> @endif
        <input type="hidden" name="consultation_id" value="{{ old('consultation_id', $o?->consultation_id ?? request('consultation_id')) }}">

        <section class="card form-section mb-3">
            <div class="card-header"><span class="section-icon" style="--tone:#7c3aed"><i class="bi bi-gem"></i></span> Trabajo</div>
            <div class="card-body row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="work_type">Qué se pide <span class="text-danger">*</span></label>
                    <select id="work_type" name="work_type" class="form-select {{ $err('work_type') }}" required>
                        @foreach(\App\Models\LabOrder::WORK_TYPES as $key => $label)<option value="{{ $key }}" @selected($val('work_type', 'corona') === $key)>{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="teeth">Pieza(s)</label>
                    <input type="text" id="teeth" name="teeth" class="form-control" maxlength="120" value="{{ $val('teeth') }}" placeholder="16 · 11, 21 · arcada superior">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="material">Material</label>
                    <input type="text" id="material" name="material" class="form-control" maxlength="40" value="{{ $val('material') }}" list="materials" placeholder="Zirconio, metal-porcelana…">
                    <datalist id="materials">@foreach(\App\Models\LabOrder::MATERIALS as $m)<option value="{{ $m }}">@endforeach</datalist>
                </div>
                <div class="col-md-6">
                    <label class="form-label d-block">Color</label>
                    <div class="d-flex flex-wrap gap-1">
                        @foreach(\App\Models\LabOrder::SHADES as $shade)
                            <input type="radio" class="btn-check" name="shade" id="shade-{{ $loop->index }}" value="{{ $shade }}" @checked($val('shade') === $shade)>
                            <label class="btn btn-sm btn-outline-secondary px-2 py-0" for="shade-{{ $loop->index }}">{{ $shade }}</label>
                        @endforeach
                        <input type="radio" class="btn-check" name="shade" id="shade-none" value="" @checked(! $val('shade'))>
                        <label class="btn btn-sm btn-outline-secondary px-2 py-0" for="shade-none">—</label>
                    </div>
                </div>
                @if($planItems->isNotEmpty())
                    <div class="col-12">
                        <label class="form-label" for="treatment_plan_item_id">Del plan de tratamiento <span class="text-muted fw-normal">(enlaza el costo con lo que se cobra)</span></label>
                        <select id="treatment_plan_item_id" name="treatment_plan_item_id" class="form-select {{ $err('treatment_plan_item_id') }}">
                            <option value="">— Ninguno —</option>
                            @foreach($planItems as $item)
                                <option value="{{ $item->id }}" @selected($preItem === (string) $item->id)>{{ $item->plan?->code }} · {{ $item->name }}{{ $item->tooth ? ' · pieza ' . $item->tooth : '' }}</option>
                            @endforeach
                        </select>
                        @error('treatment_plan_item_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                @endif
                <div class="col-12">
                    <label class="form-label" for="instructions">Indicaciones para el laboratorio</label>
                    <textarea id="instructions" name="instructions" class="form-control" rows="3" maxlength="2000" placeholder="Hombro en 360°, contacto oclusal leve, adjunto modelo y registro de mordida…">{{ $val('instructions') }}</textarea>
                </div>
            </div>
        </section>

        <section class="card form-section mb-3">
            <div class="card-header"><span class="section-icon" style="--tone:#2563eb"><i class="bi bi-building"></i></span> Laboratorio y fechas</div>
            <div class="card-body row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="supplier_id">Laboratorio <span class="text-danger">*</span></label>
                    <select id="supplier_id" name="supplier_id" class="form-select {{ $err('supplier_id') }}" required>
                        <option value="">Elige…</option>
                        @foreach($sortedLabs as $lab)<option value="{{ $lab->id }}" @selected((string) $val('supplier_id') === (string) $lab->id)>{{ $lab->name }}</option>@endforeach
                    </select>
                    <div class="form-text">Los laboratorios son proveedores. <a href="{{ route('suppliers.create') }}" target="_blank">Agregar uno</a></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="personal_id">Lo pide</label>
                    <select id="personal_id" name="personal_id" class="form-select">
                        <option value="">—</option>
                        @foreach($professionals as $pro)<option value="{{ $pro->id }}" @selected((string) $val('personal_id', $defaultProfessional) === (string) $pro->id)>{{ $pro->full_name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label" for="sent_at">Enviado el</label>
                    <input type="date" id="sent_at" name="sent_at" class="form-control {{ $err('sent_at') }}" value="{{ old('sent_at', $o?->sent_at?->toDateString() ?? today()->toDateString()) }}" max="{{ today()->toDateString() }}">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label" for="due_at">Entrega</label>
                    <input type="date" id="due_at" name="due_at" class="form-control {{ $err('due_at') }}" value="{{ old('due_at', $o?->due_at?->toDateString() ?? today()->addDays(7)->toDateString()) }}">
                    @error('due_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="cost">Costo del laboratorio</label>
                    <div class="input-group"><span class="input-group-text">{{ $symbol }}</span><input type="number" id="cost" name="cost" class="form-control {{ $err('cost') }}" step="0.01" min="0" value="{{ $val('cost') }}" @disabled($o?->isPaid())></div>
                    @if($o?->isPaid())<input type="hidden" name="cost" value="{{ (float) $o->cost }}"><div class="form-text">Ya se pagó: no se puede cambiar.</div>@else<div class="form-text">Se paga después, junto con otros trabajos del mismo laboratorio.</div>@endif
                    @error('cost')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <label class="form-label" for="notes">Notas internas</label>
                    <input type="text" id="notes" name="notes" class="form-control" maxlength="1000" value="{{ $val('notes') }}">
                </div>
            </div>
        </section>

        <div class="sticky-actions">
            <a href="{{ $o ? route('lab-orders.show', $o) : route('lab-orders.index') }}" class="btn btn-light">Cancelar</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> {{ $o ? 'Guardar cambios' : 'Registrar orden' }}</button>
        </div>
    </form>
</div>
@endsection
