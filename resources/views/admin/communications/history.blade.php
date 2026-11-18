@extends('layouts.app')

@section('title', 'Mensajes enviados')

@section('page')
@php $kinds = \App\Support\Communications::KINDS; @endphp
<div class="container-fluid" style="max-width: 1100px;">
    <div class="page-header">
        <div>
            <a href="{{ route('communications.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Para enviar hoy</a>
            <h1 class="mt-1"><i class="bi bi-clock-history"></i> Enviados</h1>
            <p class="page-subtitle">Todo lo que se abrió en WhatsApp para enviar, con quién lo hizo y el texto exacto.</p>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <nav class="filter-chips" aria-label="Tipo">
            <a href="{{ route('communications.history', array_filter(['q' => request('q')])) }}" class="filter-chip {{ request('tipo') ? '' : 'active' }}">Todos</a>
            @foreach($kinds as $key => [$label, $icon])
                <a href="{{ route('communications.history', array_filter(['tipo' => $key, 'q' => request('q')])) }}" class="filter-chip {{ request('tipo') === $key ? 'active' : '' }}"><i class="bi {{ $icon }}"></i> {{ $label }}</a>
            @endforeach
        </nav>
        <form method="GET" class="ms-auto">
            @if(request('tipo'))<input type="hidden" name="tipo" value="{{ request('tipo') }}">@endif
            <input type="search" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Buscar paciente…" aria-label="Buscar paciente">
        </form>
    </div>

    <div class="card">
        <ul class="list-group list-group-flush">
            @forelse($communications as $c)
                <li class="list-group-item">
                    <div class="d-flex align-items-start gap-2">
                        <span class="avatar" style="--tone: {{ $kinds[$c->kind][2] ?? 'var(--brand-base)' }}"><i class="bi {{ $kinds[$c->kind][1] ?? 'bi-chat' }}"></i></span>
                        <div class="flex-fill">
                            <div class="d-flex flex-wrap gap-2 align-items-baseline">
                                <a href="{{ route('patients.show', [$c->patient, 'tab' => 'recordatorios']) }}" class="fw-semibold text-decoration-none text-reset">{{ $c->patient?->full_name }}</a>
                                <span class="badge bg-light text-dark border">{{ $c->kind_label }}</span>
                                <span class="small text-muted ms-auto">{{ collect([$c->created_at->format('d/m/Y H:i'), $c->user?->name])->filter()->implode(' · ') }}</span>
                            </div>
                            <div class="small text-muted mt-1" style="white-space: pre-wrap;">{{ $c->message }}</div>
                        </div>
                    </div>
                </li>
            @empty
                <li class="list-group-item">
                    <div class="empty-state">
                        <div class="empty-icon"><i class="bi bi-whatsapp"></i></div>
                        <h5 class="mb-1">Todavía no se envió nada</h5>
                        <p class="small mb-0">Los mensajes se envían desde <a href="{{ route('communications.index') }}">Para enviar hoy</a>, desde la cita o desde la ficha del paciente.</p>
                    </div>
                </li>
            @endforelse
        </ul>
    </div>
    <div class="mt-3">{{ $communications->links() }}</div>
</div>
@endsection
