@extends('layouts.app')

@section('title', 'Disponibilidad')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $canEdit = $can('schedules.edit');
    $current = $team->firstWhere('id', (int) $selected) ?? $team->first();
    $weekdays = \App\Models\Schedule::WEEKDAYS;
    $byDay = $current ? $current->schedules->groupBy('weekday') : collect();
    $summary = fn ($person) => $person->schedules->groupBy('weekday')->map(fn ($blocks, $day) => mb_substr($weekdays[$day], 0, 3))->implode(' · ');
@endphp
<div class="container-fluid">
    @include('admin.finance.partials.errors')

    <div class="page-header">
        <div>
            <h1><i class="bi bi-clock-history"></i> Disponibilidad</h1>
            <p class="page-subtitle">Quién atiende pacientes, en qué horario, y los días bloqueados (vacaciones, feriados).</p>
        </div>
    </div>

    @if($team->isEmpty())
        <div class="card"><div class="empty-state">
            <div class="empty-icon"><i class="bi bi-person-vcard"></i></div>
            <h5 class="mb-1">Primero, tu equipo</h5>
            <p class="small mb-3">Agrega a los odontólogos en Equipo › Odontólogos y personal.</p>
            <a href="{{ route('personal.create') }}" class="btn btn-primary"><i class="bi bi-person-plus"></i> Agregar al equipo</a>
        </div></div>
    @else
    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">Equipo</div>
                <div class="list-group list-group-flush">
                    @foreach($team as $person)
                        <a href="{{ route('availability.index', ['personal' => $person->id]) }}" class="list-group-item list-group-item-action d-flex gap-2 align-items-center {{ $current?->id === $person->id ? 'active-person' : '' }}">
                            <span class="avatar" style="--tone: {{ $person->color }}">{{ $person->initials }}</span>
                            <span class="flex-fill min-w-0">
                                <span class="d-block fw-semibold text-truncate">{{ $person->full_name }}</span>
                                <small class="text-muted d-block text-truncate">
                                    @if($person->attends_patients)
                                        {{ $person->schedules->isNotEmpty() ? $summary($person) : 'Sin horario cargado' }}
                                    @else
                                        No atiende pacientes · {{ $person->cargo?->name }}
                                    @endif
                                </small>
                            </span>
                            @if($person->attends_patients)<i class="bi bi-calendar-check text-success" title="En la agenda"></i>@endif
                        </a>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            @if($current)
                <form method="POST" action="{{ route('availability.update', $current) }}" class="card mb-3" id="schedule-form">
                    @csrf @method('PUT')
                    <div class="card-header d-flex align-items-center gap-2">
                        <span class="avatar" style="--tone: {{ $current->color }}">{{ $current->initials }}</span>
                        <span class="flex-fill">{{ $current->full_name }}</span>
                    </div>
                    <div class="card-body">
                        <fieldset @disabled(! $canEdit)>
                            <div class="row g-3 align-items-end mb-3">
                                <div class="col-md-5">
                                    <div class="form-check form-switch fs-6">
                                        <input type="hidden" name="attends_patients" value="0">
                                        <input class="form-check-input" type="checkbox" role="switch" id="attends" name="attends_patients" value="1" @checked($current->attends_patients)>
                                        <label class="form-check-label fw-semibold" for="attends">Atiende pacientes</label>
                                    </div>
                                    <div class="form-text">Aparece en la agenda y se le pueden asignar citas.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="specialty_id">Especialidad</label>
                                    <select id="specialty_id" name="specialty_id" class="form-select">
                                        <option value="">—</option>
                                        @foreach($specialties as $specialty)
                                            <option value="{{ $specialty->id }}" @selected($current->specialty_id === $specialty->id)>{{ $specialty->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="agenda_color">Color</label>
                                    <input type="color" id="agenda_color" name="agenda_color" class="form-control form-control-color w-100" value="{{ $current->color }}">
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                <h2 class="h6 fw-bold mb-0">Horario semanal</h2>
                                @if($canEdit)
                                    <div class="d-flex flex-wrap gap-1">
                                        <button type="button" class="btn btn-sm btn-light" data-preset="partido">L–V 08–12 y 14–19</button>
                                        <button type="button" class="btn btn-sm btn-light" data-preset="corrido">L–V 09–17</button>
                                        <button type="button" class="btn btn-sm btn-light" data-preset="sabado">+ Sábado 08–12</button>
                                        <button type="button" class="btn btn-sm btn-light" data-preset="copy">Copiar lunes a L–V</button>
                                        <button type="button" class="btn btn-sm btn-light text-danger" data-preset="clear">Vaciar</button>
                                    </div>
                                @endif
                            </div>

                            <div class="week-grid" id="week">
                                @foreach($weekdays as $day => $name)
                                    <div class="week-row" data-day="{{ $day }}">
                                        <div class="week-day">{{ $name }}</div>
                                        <div class="week-blocks">
                                            @foreach($byDay->get($day, collect()) as $block)
                                                <span class="week-block">
                                                    <input type="time" value="{{ $block->start_label }}" data-start aria-label="{{ $name }}: desde"> –
                                                    <input type="time" value="{{ $block->end_label }}" data-end aria-label="{{ $name }}: hasta">
                                                    @if($canEdit)<button type="button" class="btn-close btn-sm" data-remove aria-label="Quitar"></button>@endif
                                                </span>
                                            @endforeach
                                            @if($canEdit)<button type="button" class="btn btn-sm btn-link text-decoration-none" data-add>+ bloque</button>@endif
                                            <span class="week-off small text-muted">No atiende</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <div id="schedule-inputs"></div>
                            <p class="form-text mb-0">Sin horario, se usa el horario general de la clínica. Fuera del horario solo se agenda como sobreturno.</p>
                        </fieldset>
                    </div>
                    @if($canEdit)
                        <div class="card-footer d-flex justify-content-end">
                            <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Guardar</button>
                        </div>
                    @endif
                </form>
            @endif

            <div class="card">
                <div class="card-header"><i class="bi bi-slash-circle"></i> Bloqueos próximos</div>
                <ul class="list-group list-group-flush">
                    @forelse($blocks as $block)
                        <li class="list-group-item d-flex align-items-center gap-2">
                            <div class="flex-fill">
                                <div class="fw-semibold">{{ $block->reason }}</div>
                                <div class="small text-muted">
                                    {{ $block->personal?->full_name ?? 'Toda la clínica' }} ·
                                    @if($block->starts_at->format('H:i') === '00:00' && $block->ends_at->format('H:i') === '23:59')
                                        {{ $block->starts_at->format('d/m/Y') }}{{ $block->starts_at->isSameDay($block->ends_at) ? '' : ' al ' . $block->ends_at->format('d/m/Y') }}
                                    @else
                                        {{ $block->starts_at->format('d/m/Y H:i') }} – {{ $block->ends_at->format($block->starts_at->isSameDay($block->ends_at) ? 'H:i' : 'd/m/Y H:i') }}
                                    @endif
                                </div>
                            </div>
                            @if($canEdit)
                                <form method="POST" action="{{ route('availability.blocks.destroy', $block) }}" onsubmit="return confirm('¿Quitar este bloqueo?')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-light text-danger" title="Quitar"><i class="bi bi-trash"></i></button>
                                </form>
                            @endif
                        </li>
                    @empty
                        <li class="list-group-item small text-muted">No hay bloqueos. Agrega vacaciones, congresos o feriados para que no se agende en esos días.</li>
                    @endforelse
                </ul>
                @if($canEdit)
                    <form method="POST" action="{{ route('availability.blocks.store') }}" class="card-body border-top row g-2 align-items-end">
                        @csrf
                        <div class="col-md-4">
                            <label class="form-label small" for="b-reason">Motivo</label>
                            <input type="text" id="b-reason" name="reason" class="form-control" maxlength="120" placeholder="Vacaciones, feriado…" list="block-reasons" required>
                            <datalist id="block-reasons"><option value="Vacaciones"><option value="Feriado"><option value="Congreso / capacitación"><option value="Licencia médica"><option value="Mantenimiento de equipos"></datalist>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small" for="b-personal">Para</label>
                            <select id="b-personal" name="personal_id" class="form-select">
                                <option value="">Toda la clínica</option>
                                @foreach($team->where('attends_patients', true) as $person)
                                    <option value="{{ $person->id }}" @selected($current?->id === $person->id)>{{ $person->full_name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label small" for="b-from">Desde</label>
                            <input type="date" id="b-from" name="from" class="form-control" value="{{ now()->toDateString() }}" required>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label small" for="b-to">Hasta</label>
                            <input type="date" id="b-to" name="to" class="form-control" value="{{ now()->toDateString() }}" required>
                        </div>
                        <div class="col-md-8 d-flex align-items-center gap-2 flex-wrap">
                            <div class="form-check form-switch me-2">
                                <input type="hidden" name="all_day" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" id="b-all" name="all_day" value="1" checked>
                                <label class="form-check-label small" for="b-all">Todo el día</label>
                            </div>
                            <span id="b-hours" class="d-flex align-items-center gap-1" hidden>
                                <input type="time" name="from_time" class="form-control form-control-sm" value="13:00" aria-label="Desde la hora"> –
                                <input type="time" name="to_time" class="form-control form-control-sm" value="15:00" aria-label="Hasta la hora">
                            </span>
                        </div>
                        <div class="col-md-4 text-end">
                            <button class="btn btn-outline-primary" type="submit"><i class="bi bi-plus-lg"></i> Agregar bloqueo</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </div>
    @endif
</div>

@push('styles')
<style>
    .active-person { background: var(--brand-lightest) !important; }
    .week-grid { border: 1px solid var(--dx-border); border-radius: 12px; overflow: hidden; }
    .week-row { display: flex; align-items: center; gap: .75rem; padding: .5rem .75rem; border-bottom: 1px solid var(--dx-border); flex-wrap: wrap; }
    .week-row:last-child { border-bottom: 0; }
    .week-day { width: 6.5rem; font-weight: 600; font-size: .88rem; }
    .week-blocks { display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; flex: 1; }
    .week-block { display: inline-flex; align-items: center; gap: .3rem; background: var(--brand-lightest); border: 1px solid var(--brand-light); border-radius: 9px; padding: .2rem .35rem .2rem .5rem; }
    .week-block input { border: 0; background: transparent; font-size: .85rem; font-variant-numeric: tabular-nums; width: 5.6rem; }
    .week-block input:focus { outline: 2px solid var(--brand-base); border-radius: 5px; }
    .week-blocks:has(.week-block) .week-off { display: none; }
</style>
@endpush

<script>
(function () {
    const week = document.getElementById('week');
    const form = document.getElementById('schedule-form');
    const allDay = document.getElementById('b-all');
    allDay?.addEventListener('change', () => { document.getElementById('b-hours').hidden = allDay.checked; });
    if (!week || !form) return;

    const blockHtml = (from, to) => {
        const span = document.createElement('span');
        span.className = 'week-block';
        span.innerHTML = `<input type="time" value="${from}" data-start> – <input type="time" value="${to}" data-end> <button type="button" class="btn-close btn-sm" data-remove aria-label="Quitar"></button>`;
        return span;
    };
    const add = (day, from, to) => {
        const row = week.querySelector(`[data-day="${day}"] .week-blocks`);
        row.insertBefore(blockHtml(from, to), row.querySelector('[data-add]'));
    };
    const clear = (days) => days.forEach((d) => week.querySelectorAll(`[data-day="${d}"] .week-block`).forEach((b) => b.remove()));
    const weekdays = [1, 2, 3, 4, 5];

    week.addEventListener('click', (e) => {
        if (e.target.matches('[data-remove]')) e.target.closest('.week-block').remove();
        if (e.target.matches('[data-add]')) {
            const blocks = e.target.parentElement.querySelectorAll('.week-block');
            const last = blocks[blocks.length - 1]?.querySelector('[data-end]')?.value;
            add(e.target.closest('.week-row').dataset.day, last || '08:00', last ? '19:00' : '12:00');
        }
    });

    document.querySelectorAll('[data-preset]').forEach((button) => button.addEventListener('click', () => {
        const preset = button.dataset.preset;
        if (preset === 'partido') { clear(weekdays); weekdays.forEach((d) => { add(d, '08:00', '12:00'); add(d, '14:00', '19:00'); }); }
        if (preset === 'corrido') { clear(weekdays); weekdays.forEach((d) => add(d, '09:00', '17:00')); }
        if (preset === 'sabado') { clear([6]); add(6, '08:00', '12:00'); }
        if (preset === 'clear') { clear([1, 2, 3, 4, 5, 6, 7]); }
        if (preset === 'copy') {
            const monday = [...week.querySelectorAll('[data-day="1"] .week-block')].map((b) => [b.querySelector('[data-start]').value, b.querySelector('[data-end]').value]);
            clear([2, 3, 4, 5]);
            [2, 3, 4, 5].forEach((d) => monday.forEach(([f, t]) => add(d, f, t)));
        }
        if (!document.getElementById('attends').checked && preset !== 'clear') document.getElementById('attends').checked = true;
    }));

    // Al guardar, los bloques visibles se convierten en schedule[i][…].
    form.addEventListener('submit', () => {
        const box = document.getElementById('schedule-inputs');
        box.innerHTML = '';
        let i = 0;
        week.querySelectorAll('.week-row').forEach((row) => row.querySelectorAll('.week-block').forEach((b) => {
            const from = b.querySelector('[data-start]').value, to = b.querySelector('[data-end]').value;
            if (!from || !to) return;
            [['weekday', row.dataset.day], ['starts_at', from], ['ends_at', to]].forEach(([k, v]) => {
                const input = document.createElement('input');
                input.type = 'hidden'; input.name = `schedule[${i}][${k}]`; input.value = v;
                box.appendChild(input);
            });
            i++;
        }));
    });
})();
</script>
@endsection
