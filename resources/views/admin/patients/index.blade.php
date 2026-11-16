@extends('layouts.app')

@section('title', 'Pacientes')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $chips = [
        null => ['Activos', 'bi-people', $counts['all']],
        'alerts' => ['Con alertas médicas', 'bi-exclamation-triangle', $counts['alerts']],
        'minors' => ['Menores de edad', 'bi-balloon', $counts['minors']],
        'birthday' => ['Cumplen años hoy', 'bi-cake2', $counts['birthday']],
        'inactive' => ['Inactivos', 'bi-archive', $counts['inactive']],
    ];
    $searching = filled(request('q'));
@endphp
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h1><i class="bi bi-people"></i> Pacientes</h1>
            <p class="page-subtitle">Busca por nombre, CI, teléfono o número de historia (HC-12).</p>
        </div>
        @if($can('patients.create'))
            <div class="page-actions">
                <a href="{{ route('patients.create') }}" class="btn btn-primary"><i class="bi bi-person-plus"></i> Nuevo paciente</a>
            </div>
        @endif
    </div>

    <form method="GET" class="mb-3" role="search">
        @if($filter)<input type="hidden" name="filter" value="{{ $filter }}">@endif
        <div class="search-hero">
            <i class="bi bi-search"></i>
            <input type="search" name="q" value="{{ request('q') }}" class="form-control" placeholder="Buscar paciente…" aria-label="Buscar paciente" autocomplete="off" @unless($searching) autofocus @endunless>
        </div>
    </form>

    <div class="d-flex align-items-center justify-content-between gap-2 mb-3 flex-wrap">
        <nav class="filter-chips" aria-label="Filtros">
            @foreach($chips as $key => [$label, $icon, $count])
                @continue($key === 'birthday' && ! $count)
                <a href="{{ route('patients.index', array_filter(['filter' => $key, 'q' => request('q')])) }}" class="filter-chip {{ (string) $filter === (string) $key ? 'active' : '' }}">
                    <i class="bi {{ $icon }}"></i> {{ $label }} <span class="count">{{ $count }}</span>
                </a>
            @endforeach
        </nav>
        <a href="{{ route('patients.index', array_filter(['filter' => $filter, 'q' => request('q'), 'sort' => request('sort') === 'recent' ? null : 'recent'])) }}" class="small text-decoration-none">
            <i class="bi bi-sort-down"></i> {{ request('sort') === 'recent' ? 'Orden alfabético' : 'Últimos registrados' }}
        </a>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle table-stack">
                <thead>
                    <tr><th class="ps-3">Paciente</th><th>Documento</th><th>Edad</th><th>Contacto</th><th>Alertas</th><th class="pe-3"></th></tr>
                </thead>
                <tbody>
                    @forelse($patients as $patient)
                        @php $alerts = $patient->medicalAlerts(); @endphp
                        <tr class="row-link" onclick="if (!event.target.closest('a,button')) location.href='{{ route('patients.show', $patient) }}'">
                            <td class="ps-3 stack-full">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="avatar {{ $alerts && $alerts[0]['critical'] ? 'is-alert' : '' }}">{{ $patient->initials }}</span>
                                    <div class="min-w-0">
                                        <a href="{{ route('patients.show', $patient) }}" class="fw-semibold text-decoration-none text-reset">{{ $patient->full_name }}</a>
                                        @if(\App\Models\Patient::birthdayIsToday($patient->birth_date))<span title="Cumple años hoy">🎂</span>@endif
                                        <div class="small text-muted">{{ $patient->code }}@unless($patient->active) · <span class="badge bg-secondary">Inactivo</span>@endunless</div>
                                    </div>
                                </div>
                            </td>
                            <td class="small" data-label="Documento">{{ $patient->document_label ?? '—' }}</td>
                            <td class="small text-nowrap" data-label="Edad">{{ $patient->age_label ?? '—' }}</td>
                            <td class="small text-nowrap" data-label="Contacto">
                                @if($patient->phone || $patient->whatsapp)
                                    {{ $patient->phone ?: $patient->whatsapp }}
                                    @if($patient->whatsapp_link)
                                        <a href="{{ $patient->whatsapp_link }}" target="_blank" rel="noopener" class="ms-1 text-success" title="Escribir por WhatsApp"><i class="bi bi-whatsapp"></i></a>
                                    @endif
                                @elseif($patient->guardian_phone)
                                    {{ $patient->guardian_phone }} <span class="text-muted">(tutor)</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td data-label="Alertas">
                                @if($alerts)
                                    <span class="alert-chip {{ $alerts[0]['critical'] ? 'critical' : '' }}" title="{{ collect($alerts)->pluck('label')->implode(' · ') }}">
                                        <i class="bi bi-exclamation-triangle-fill"></i> {{ \Illuminate\Support\Str::limit($alerts[0]['label'], 28) }}
                                    </span>
                                    @if(count($alerts) > 1)<span class="small text-muted">+{{ count($alerts) - 1 }}</span>@endif
                                @else
                                    <span class="small text-muted">—</span>
                                @endif
                            </td>
                            <td class="pe-3 text-end stack-hide">
                                <a href="{{ route('patients.show', $patient) }}" class="btn btn-sm btn-light" aria-label="Abrir ficha de {{ $patient->full_name }}"><i class="bi bi-chevron-right"></i></a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="stack-full">
                            <div class="empty-state">
                                @if($searching || $filter)
                                    <div class="empty-icon"><i class="bi bi-search"></i></div>
                                    <h5 class="mb-1">No encontramos pacientes</h5>
                                    <p class="small mb-3">Prueba con otro nombre, el CI o el teléfono.</p>
                                    <a href="{{ route('patients.index') }}" class="btn btn-light">Ver todos</a>
                                    @if($can('patients.create') && $searching)
                                        <a href="{{ route('patients.create', ['name' => request('q')]) }}" class="btn btn-primary ms-1"><i class="bi bi-person-plus"></i> Registrar «{{ \Illuminate\Support\Str::limit(request('q'), 24) }}»</a>
                                    @endif
                                @else
                                    <div class="empty-icon"><i class="bi bi-people"></i></div>
                                    <h5 class="mb-1">Todavía no hay pacientes</h5>
                                    <p class="small mb-3">Registra al primero: sus datos, sus antecedentes médicos y su consentimiento.</p>
                                    @if($can('patients.create'))<a href="{{ route('patients.create') }}" class="btn btn-primary"><i class="bi bi-person-plus"></i> Nuevo paciente</a>@endif
                                @endif
                            </div>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3 d-flex justify-content-center">{{ $patients->links() }}</div>
</div>
@endsection
