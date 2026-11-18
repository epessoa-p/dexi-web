@extends('layouts.app')

@section('title', 'Nuevo plan · ' . $patient->full_name)

@section('page')
@php
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
    $withProcedure = $suggestions->filter(fn ($s) => $s['procedure']);
@endphp
<div class="container-fluid" style="max-width: 900px;">
    @include('admin.finance.partials.errors')

    <div class="page-header">
        <div>
            <a href="{{ $consultation ? route('consultations.show', $consultation) : route('patients.show', [$patient, 'tab' => 'tratamiento']) }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> {{ $consultation ? 'Consulta' : $patient->full_name }}</a>
            <h1 class="mt-1">Nuevo plan de tratamiento</h1>
            <p class="page-subtitle">{{ $patient->full_name }} · {{ $patient->code }}</p>
        </div>
    </div>

    <form method="POST" action="{{ route('treatment-plans.store') }}">
        @csrf
        <input type="hidden" name="patient_id" value="{{ $patient->id }}">
        @if($consultation)<input type="hidden" name="consultation_id" value="{{ $consultation->id }}">@endif

        <section class="card form-section">
            <div class="card-header"><span class="section-icon" style="--tone:#dc3545"><i class="bi bi-grid-3x3"></i></span> Desde el odontograma
                <span class="section-hint">{{ $suggestions->count() }} {{ $suggestions->count() === 1 ? 'hallazgo pendiente' : 'hallazgos pendientes' }}</span></div>
            @if($suggestions->isEmpty())
                <div class="card-body text-muted small">El odontograma no tiene hallazgos pendientes. Crea el plan y agrega los procedimientos a mano.</div>
            @else
                <ul class="list-group list-group-flush">
                    @foreach($suggestions as $s)
                        <li class="list-group-item">
                            <label class="d-flex align-items-center gap-3 mb-0 {{ $s['procedure'] ? '' : 'opacity-75' }}" style="cursor: {{ $s['procedure'] ? 'pointer' : 'default' }}">
                                <input class="form-check-input mt-0" type="checkbox" name="suggestions[]" value="{{ $s['key'] }}" @checked($s['procedure']) @disabled(! $s['procedure'])>
                                <span class="badge bg-danger-subtle text-danger-emphasis tabular" style="min-width: 3.4rem">{{ $s['tooth'] }}{{ $s['surfaces'] ? ' ' . $s['surfaces'] : '' }}</span>
                                <span class="flex-fill">
                                    <span class="fw-semibold">{{ $s['finding'] }}</span>
                                    <i class="bi bi-arrow-right text-muted mx-1"></i>
                                    @if($s['procedure'])
                                        {{ $s['procedure']->name }}
                                    @else
                                        <span class="text-muted">sin procedimiento sugerido (agrégalo a mano)</span>
                                    @endif
                                    <span class="d-block small text-muted">Fase {{ $s['phase'] }} · {{ $s['phase'] === 1 ? 'urgente: dolor o infección' : 'restaurador' }}</span>
                                </span>
                                @if($s['procedure'])<span class="tabular fw-semibold small">{{ $s['procedure']->hasPrice() ? $money($s['procedure']->price) : 'Sin precio' }}</span>@endif
                            </label>
                        </li>
                    @endforeach
                </ul>
                @if($withProcedure->count() < $suggestions->count())
                    <div class="card-body border-top small text-muted py-2">Los hallazgos sin sugerencia aparecen porque falta ese procedimiento en el <a href="{{ route('procedures.index') }}">tarifario</a> (por ejemplo, carga el catálogo sugerido).</div>
                @endif
            @endif
        </section>

        <section class="card form-section">
            <div class="card-header"><span class="section-icon"><i class="bi bi-card-heading"></i></span> Datos del plan</div>
            <div class="card-body row g-3">
                <div class="col-md-8">
                    <label class="form-label" for="title">Título</label>
                    <input type="text" id="title" name="title" class="form-control" maxlength="150" value="{{ old('title', 'Plan de tratamiento') }}" list="plan-titles">
                    <datalist id="plan-titles"><option value="Plan de tratamiento"><option value="Rehabilitación integral"><option value="Tratamiento de conductos"><option value="Ortodoncia"><option value="Estética dental"><option value="Implantes"></datalist>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="valid_until">Válido hasta</label>
                    <input type="date" id="valid_until" name="valid_until" class="form-control" value="{{ old('valid_until', now()->addDays(30)->toDateString()) }}" min="{{ now()->toDateString() }}">
                </div>
                <div class="col-md-8">
                    <label class="form-label" for="personal_id">Profesional responsable</label>
                    <select id="personal_id" name="personal_id" class="form-select">
                        @foreach($professionals as $pro)
                            <option value="{{ $pro->id }}" @selected((string) old('personal_id', $consultation?->personal_id ?? $defaultProfessional) === (string) $pro->id)>{{ $pro->full_name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </section>

        <div class="sticky-actions">
            <a href="{{ $consultation ? route('consultations.show', $consultation) : route('patients.show', [$patient, 'tab' => 'tratamiento']) }}" class="btn btn-light">Cancelar</a>
            <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Crear plan</button>
        </div>
    </form>
</div>
@endsection
