@extends('layouts.app')

@section('title', 'Mensajes')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $editable = $can('communications.edit');
    $clinic = $tenantCompany->displayName();
    // Valores de ejemplo para la vista previa.
    $sample = [
        '{nombre}' => $sample?->first_name ?? 'María',
        '{paciente}' => $sample?->full_name ?? 'María Pérez',
        '{clinica}' => $clinic,
        '{telefono}' => (string) settings()->get('company.phone', null, $tenantCompany->id),
        '{fecha}' => now()->addDay()->locale('es')->translatedFormat('l j \d\e F'),
        '{hora}' => '09:30',
        '{profesional}' => 'Dra. Ana Rojas',
        '{motivo}' => 'control de 6 meses',
        '{saldo}' => \App\Support\Money::format(150, $tenantCompany),
    ];
@endphp
<div class="container-fluid" style="max-width: 1100px;">
    @include('admin.finance.partials.errors')

    <div class="page-header">
        <div>
            <a href="{{ route('communications.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Para enviar hoy</a>
            <h1 class="mt-1"><i class="bi bi-chat-square-text"></i> Mensajes</h1>
            <p class="page-subtitle">El texto de cada mensaje. Las palabras entre llaves se reemplazan por los datos de cada paciente.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('communications.templates.save') }}">
        @csrf
        <div class="d-flex flex-column gap-3">
            @foreach($kinds as $kind => [$label, $icon, $color, $module, $default])
                <div class="card" id="t-{{ $kind }}">
                    <div class="card-header d-flex align-items-center gap-2">
                        <i class="bi {{ $icon }}" style="color: {{ $color }}"></i> {{ $label }}
                        @if($bodies[$kind] !== $default)<span class="badge bg-light text-dark border ms-1">Personalizado</span>@endif
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-lg-6">
                                <textarea name="templates[{{ $kind }}]" class="form-control js-template" rows="5" maxlength="1000" data-default="{{ $default }}" @disabled(! $editable)>{{ old("templates.{$kind}", $bodies[$kind]) }}</textarea>
                                @if($editable)
                                    <div class="d-flex flex-wrap gap-1 mt-2">
                                        @foreach(\App\Support\Communications::VARIABLES as $var => $hint)
                                            <button type="button" class="btn btn-sm btn-light py-0 px-2 js-var" data-var="{{ $var }}" title="{{ $hint }}">{{ $var }}</button>
                                        @endforeach
                                        <button type="button" class="btn btn-sm btn-link py-0 ms-auto js-reset">Volver al sugerido</button>
                                    </div>
                                @endif
                            </div>
                            <div class="col-lg-6">
                                <div class="small text-muted mb-1">Así lo verá el paciente:</div>
                                <div class="wa-bubble js-preview"></div>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        @if($editable)
            <div class="sticky-actions mt-3">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Guardar mensajes</button>
            </div>
        @endif
    </form>
</div>
@include('admin.finance.partials.styles')
@push('styles')
<style>
    .wa-bubble { background: #dcf8c6; border-radius: .9rem .9rem .9rem .2rem; padding: .7rem .9rem; white-space: pre-wrap; font-size: .92rem;
        box-shadow: 0 1px 1px rgba(0,0,0,.08); position: relative; min-height: 3rem; }
    .wa-bubble::after { content: '✓✓'; display: block; text-align: right; color: #34b7f1; font-size: .7rem; margin-top: .25rem; }
</style>
@endpush
@push('scripts')
<script>
    (() => {
        const sample = {!! json_encode($sample, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) !!};
        const render = (text) => text.replace(/\{[a-z]+\}/g, (v) => sample[v] ?? v).replace(/[ \t]{2,}/g, ' ').trim();
        document.querySelectorAll('.js-template').forEach((area) => {
            const card = area.closest('.card');
            const preview = card.querySelector('.js-preview');
            const update = () => { preview.textContent = render(area.value) || '—'; };
            area.addEventListener('input', update);
            update();
            card.querySelectorAll('.js-var').forEach((btn) => btn.addEventListener('click', () => {
                const start = area.selectionStart ?? area.value.length;
                const end = area.selectionEnd ?? start;
                area.setRangeText(btn.dataset.var, start, end, 'end');
                area.focus();
                update();
            }));
            card.querySelector('.js-reset')?.addEventListener('click', () => { area.value = area.dataset.default; update(); });
        });
    })();
</script>
@endpush
@endsection
