@extends('layouts.app')

@section('title', $patient->full_name)

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $p = $patient;
    $alerts = $p->medicalAlerts();
    $tabs = \App\Http\Controllers\Admin\PatientController::TABS;
@endphp
<div class="container-fluid">
    @include('admin.finance.partials.errors')

    {{-- Cabecera de la ficha --}}
    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap gap-3 align-items-center">
            <span class="avatar avatar-lg {{ $alerts && $alerts[0]['critical'] ? 'is-alert' : '' }}">{{ $p->initials }}</span>
            <div class="flex-fill min-w-0">
                <a href="{{ route('patients.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Pacientes</a>
                <h1 class="mb-1 mt-1 text-truncate">
                    {{ $p->full_name }}
                    @if(\App\Models\Patient::birthdayIsToday($p->birth_date))<span title="¡Cumple años hoy!">🎂</span>@endif
                    @unless($p->active)<span class="badge bg-secondary fs-6 align-middle">Inactivo</span>@endunless
                </h1>
                <div class="text-muted small d-flex flex-wrap column-gap-3 row-gap-1">
                    <span><i class="bi bi-hash"></i>{{ $p->code }}</span>
                    @if($p->age_label)<span><i class="bi bi-calendar3"></i> {{ $p->age_label }}</span>@endif
                    @if($p->document_label)<span><i class="bi bi-person-vcard"></i> {{ $p->document_label }}</span>@endif
                    @if($p->phone)<span><i class="bi bi-telephone"></i> <a href="tel:{{ $p->phone }}" class="text-reset">{{ $p->phone }}</a></span>@endif
                    @if($p->insurance)<span><i class="bi bi-shield-check"></i> {{ $p->insurance }}</span>@endif
                </div>
            </div>
            <div class="page-actions">
                @if($p->whatsapp_link)
                    <a href="{{ $p->whatsapp_link }}" target="_blank" rel="noopener" class="btn btn-outline-success"><i class="bi bi-whatsapp"></i> WhatsApp</a>
                @endif
                @if($clinical && $p->active && $can('consultations.create'))
                    <form method="POST" action="{{ route('consultations.start') }}" class="m-0">
                        @csrf
                        <input type="hidden" name="patient_id" value="{{ $p->id }}">
                        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-clipboard2-pulse"></i> Atender</button>
                    </form>
                @endif
                @if($agenda && $p->active && $can('appointments.create'))
                    <a href="{{ route('appointments.create', ['patient_id' => $p->id]) }}" class="btn btn-primary"><i class="bi bi-calendar-plus"></i> Agendar</a>
                @endif
                @if($can('patients.edit'))
                    <a href="{{ route('patients.edit', $p) }}" class="btn btn-light"><i class="bi bi-pencil"></i> Editar</a>
                @endif
                @if($can('patients.delete'))
                    <div class="dropdown">
                        <button class="btn btn-light" data-bs-toggle="dropdown" aria-label="Más acciones"><i class="bi bi-three-dots"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <form method="POST" action="{{ route('patients.destroy', $p) }}" onsubmit="return confirm('{{ $p->documents_count ? '¿Desactivar a ' . e($p->full_name) . '? Tiene documentos clínicos, así que no se elimina.' : '¿Eliminar a ' . e($p->full_name) . '?' }}')">
                                    @csrf @method('DELETE')
                                    <button class="dropdown-item text-danger" type="submit"><i class="bi bi-trash"></i> {{ $p->documents_count ? 'Desactivar paciente' : 'Eliminar paciente' }}</button>
                                </form>
                            </li>
                        </ul>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Alertas médicas: siempre visibles, en todas las pestañas --}}
    @if($alerts)
        <div class="medical-banner" role="alert">
            <i class="bi bi-exclamation-octagon-fill"></i>
            <div>
                <div class="fw-bold mb-1">Antes de atender</div>
                <div class="d-flex flex-wrap gap-1">
                    @foreach($alerts as $alert)
                        <span class="alert-chip {{ $alert['critical'] ? 'critical' : '' }}">{{ $alert['label'] }}</span>
                    @endforeach
                </div>
                @if($p->medications)<div class="small mt-1"><strong>Medicación:</strong> {{ $p->medications }}</div>@endif
            </div>
        </div>
    @endif

    {{-- Pestañas --}}
    <nav class="tabs-scroll" aria-label="Secciones de la ficha">
        @foreach($tabs as $key => [$label, $icon, $ready])
            <a href="{{ route('patients.show', [$p, 'tab' => $key]) }}" class="{{ $tab === $key ? 'active' : '' }}" @if($tab === $key) aria-current="page" @endif>
                <i class="bi {{ $icon }}"></i> {{ $label }}
                @if($key === 'documentos' && $p->documents_count)<span class="badge rounded-pill bg-light text-dark border">{{ $p->documents_count }}</span>@endif
                @unless($ready && ($key !== 'citas' || $agenda) && (! in_array($key, ['odontograma', 'historia'], true) || $clinical))<span class="soon">pronto</span>@endunless
            </a>
        @endforeach
    </nav>

    @if($tab === 'resumen')
        @include('admin.patients.partials.summary')
    @elseif($tab === 'odontograma' && $clinical && $can('odontogram.view'))
        <div class="card"><div class="card-body">
            @include('admin.odontogram.chart', ['patient' => $p, 'editable' => $can('odontogram.edit')])
        </div></div>
    @elseif($tab === 'historia' && $clinical && $can('consultations.view'))
        @include('admin.patients.partials.history')
    @elseif($tab === 'citas' && $agenda)
        @include('admin.patients.partials.appointments')
    @elseif($tab === 'documentos')
        @include('admin.patients.partials.documents')
    @else
        @include('admin.patients.partials.soon', ['key' => $tab])
    @endif
</div>
@endsection
