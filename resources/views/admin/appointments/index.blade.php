@extends('layouts.app')

@section('title', 'Agenda')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $canEdit = $can('appointments.edit');
    $statuses = \App\Models\Appointment::STATUSES;
@endphp
<div class="container-fluid">
    @include('admin.finance.partials.errors')

    <div class="page-header">
        <div>
            <h1><i class="bi bi-calendar-week"></i> Agenda</h1>
            <p class="page-subtitle">Toca un espacio libre para agendar. Arrastra una cita para reprogramarla.</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('appointments.today') }}" class="btn btn-light"><i class="bi bi-hourglass-split"></i> Hoy y sala de espera</a>
            @if($can('appointments.create'))
                <a href="{{ route('appointments.create') }}" class="btn btn-primary" id="newAppointmentBtn"><i class="bi bi-calendar-plus"></i> Nueva cita</a>
            @endif
        </div>
    </div>

    @if($professionals->isEmpty())
        <div class="card"><div class="empty-state">
            <div class="empty-icon"><i class="bi bi-person-badge"></i></div>
            <h5 class="mb-1">Falta decir quién atiende</h5>
            <p class="small mb-3">Marca a tus odontólogos como «Atiende pacientes» y carga su horario. Después ya puedes agendar.</p>
            @if($can('schedules.edit'))<a href="{{ route('availability.index') }}" class="btn btn-primary"><i class="bi bi-clock-history"></i> Configurar disponibilidad</a>@endif
        </div></div>
    @else
        <div class="row g-3">
            <div class="col-lg-3 order-2 order-lg-1">
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center gap-2">
                        <span>Profesionales</span>
                        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" id="allProfessionals">Todos</button>
                    </div>
                    <div class="card-body d-flex flex-column gap-2">
                        @foreach($professionals as $professional)
                            <label class="pro-filter" style="--pro: {{ $professional->color }}">
                                <input type="checkbox" value="{{ $professional->id }}" checked data-professional>
                                <span class="pro-dot"></span>
                                <span class="min-w-0">
                                    <span class="d-block text-truncate fw-semibold">{{ $professional->full_name }}</span>
                                    @if($professional->specialty)<small class="text-muted d-block text-truncate">{{ $professional->specialty->name }}</small>@endif
                                </span>
                            </label>
                        @endforeach
                        <hr class="my-1">
                        <div class="form-check form-switch small">
                            <input class="form-check-input" type="checkbox" id="showCancelled">
                            <label class="form-check-label" for="showCancelled">Ver canceladas y no asistidas</label>
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header">Estados</div>
                    <div class="card-body small d-flex flex-column gap-1">
                        @foreach($statuses as $key => [$label, $color, $icon])
                            <span><i class="bi {{ $icon }} text-{{ $color }}"></i> {{ $label }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="col-lg-9 order-1 order-lg-2">
                <div class="card">
                    <div class="card-body p-2 p-md-3">
                        <div id="calendar" aria-label="Calendario de citas"></div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

{{-- Detalle rápido de una cita --}}
<div class="modal fade" id="apptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div class="min-w-0">
                    <h5 class="modal-title text-truncate" id="apptPatient"></h5>
                    <div class="small text-muted" id="apptWhen"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <span class="badge" id="apptStatus"></span>
                    <span class="alert-chip critical d-none" id="apptAlert"><i class="bi bi-exclamation-triangle-fill"></i> Tiene alertas médicas</span>
                    <span class="badge bg-warning-subtle text-warning-emphasis d-none" id="apptOver">Sobreturno</span>
                </div>
                <div><i class="bi bi-clipboard2-pulse text-muted"></i> <span id="apptProcedure"></span></div>
                <div><i class="bi bi-person-badge text-muted"></i> <span id="apptProfessional"></span></div>
                @if($canEdit)
                    <div class="mt-3 d-flex flex-wrap gap-2" id="apptActions"></div>
                @endif
            </div>
            <div class="modal-footer justify-content-between">
                <a href="#" class="btn btn-light" id="apptOpen"><i class="bi bi-box-arrow-up-right"></i> Ver cita</a>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

@push('styles')
<style>
    #calendar { --fc-border-color: var(--dx-border); --fc-today-bg-color: rgba(var(--brand-rgb), .06); --fc-now-indicator-color: #dc3545;
        --fc-button-bg-color: #fff; --fc-button-border-color: var(--dx-border-strong); --fc-button-text-color: #33444a;
        --fc-button-hover-bg-color: #f3f7f8; --fc-button-hover-border-color: var(--dx-border-strong);
        --fc-button-active-bg-color: var(--brand-base); --fc-button-active-border-color: var(--brand-base); font-size: .85rem; min-height: 640px; }
    #calendar .fc-button { border-radius: 9px; font-weight: 600; text-transform: none; box-shadow: none !important; }
    #calendar .fc-button-active { color: #fff; }
    #calendar .fc-toolbar-title { font-size: 1.15rem; font-weight: 700; text-transform: capitalize; }
    #calendar .fc-col-header-cell-cushion { text-transform: capitalize; color: var(--dx-text); text-decoration: none; font-weight: 600; }
    #calendar .fc-timegrid-slot { height: 2.1rem; }
    #calendar .fc-event { border-radius: 7px; border-width: 0 0 0 4px; cursor: pointer; }
    #calendar .appt .fc-event-main { padding: 2px 5px; color: #fff; }
    #calendar .appt-confirmada { filter: saturate(1.1); }
    #calendar .appt-en_espera { box-shadow: inset 0 0 0 2px #ffc107; }
    #calendar .appt-en_atencion { box-shadow: inset 0 0 0 2px #0dcaf0; }
    #calendar .appt-atendida { opacity: .55; }
    #calendar .appt-cancelada, #calendar .appt-no_asistio { opacity: .4; text-decoration: line-through; }
    #calendar .agenda-block { background: repeating-linear-gradient(45deg, #eef2f3, #eef2f3 6px, #e2e8ea 6px, #e2e8ea 12px); opacity: 1; }
    #calendar .agenda-block .fc-event-title { color: #6b7c81; font-size: .72rem; padding: 2px 4px; font-style: normal; }
    .ev-time { font-size: .7rem; opacity: .9; }
    .ev-title { font-weight: 700; font-size: .78rem; line-height: 1.15; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ev-sub { font-size: .7rem; opacity: .9; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .pro-filter { display: flex; align-items: center; gap: .55rem; cursor: pointer; padding: .3rem .4rem; border-radius: 9px; font-size: .85rem; }
    .pro-filter:hover { background: #f3f7f8; }
    .pro-filter input { position: absolute; opacity: 0; }
    .pro-dot { width: 1rem; height: 1rem; border-radius: 5px; border: 2px solid var(--pro); background: var(--pro); flex-shrink: 0; }
    .pro-filter input:not(:checked) ~ .pro-dot { background: transparent; }
    .pro-filter input:not(:checked) ~ span { opacity: .55; }
    .pro-filter input:focus-visible ~ .pro-dot { outline: 2px solid var(--brand-base); outline-offset: 2px; }
</style>
@endpush

@if($professionals->isNotEmpty())
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@fullcalendar/core@6.1.15/locales/es.global.min.js"></script>
<script>
(function () {
    const csrf = {!! json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP) !!};
    const canEdit = {!! json_encode($canEdit, JSON_HEX_TAG | JSON_HEX_AMP) !!};
    const canCreate = {!! json_encode($can('appointments.create'), JSON_HEX_TAG | JSON_HEX_AMP) !!};
    const createUrl = {!! json_encode(route('appointments.create'), JSON_HEX_TAG | JSON_HEX_AMP) !!};
    const statuses = {!! json_encode(collect($statuses)->map(fn ($s) => ['label' => $s[0], 'color' => $s[1], 'icon' => $s[2]]), JSON_HEX_TAG | JSON_HEX_AMP) !!};
    const transitions = {!! json_encode(\App\Models\Appointment::TRANSITIONS, JSON_HEX_TAG | JSON_HEX_AMP) !!};
    const KEY = 'dexi.agenda';
    const saved = (() => { try { return JSON.parse(localStorage.getItem(KEY)) || {}; } catch (e) { return {}; } })();
    const remember = (patch) => { try { localStorage.setItem(KEY, JSON.stringify(Object.assign(saved, patch))); } catch (e) {} };

    const checks = [...document.querySelectorAll('[data-professional]')];
    if (Array.isArray(saved.professionals) && saved.professionals.length) {
        checks.forEach((c) => { c.checked = saved.professionals.includes(c.value); });
    }
    const selected = () => checks.filter((c) => c.checked).map((c) => c.value);
    const cancelled = document.getElementById('showCancelled');

    const mobile = window.matchMedia('(max-width: 767.98px)').matches;
    const calendar = new FullCalendar.Calendar(document.getElementById('calendar'), {
        locale: 'es',
        timeZone: 'UTC',                       // «hora de pared» de la clínica (el servidor manda la hora sin zona)
        now: {!! json_encode(now()->format('Y-m-d\TH:i:s'), JSON_HEX_TAG | JSON_HEX_AMP) !!},
        initialDate: {!! json_encode(request('date') ?: now()->toDateString(), JSON_HEX_TAG | JSON_HEX_AMP) !!},
        initialView: mobile ? 'timeGridDay' : (saved.view || 'timeGridWeek'),
        headerToolbar: mobile
            ? { left: 'prev,next today', center: 'title', right: 'timeGridDay,listWeek' }
            : { left: 'prev,next today', center: 'title', right: 'timeGridDay,timeGridWeek,dayGridMonth,listWeek' },
        buttonText: { today: 'Hoy', day: 'Día', week: 'Semana', month: 'Mes', list: 'Lista' },
        slotMinTime: {!! json_encode(substr($opensAt, 0, 5) . ':00', JSON_HEX_TAG | JSON_HEX_AMP) !!},
        slotMaxTime: {!! json_encode(substr($closesAt, 0, 5) . ':00', JSON_HEX_TAG | JSON_HEX_AMP) !!},
        slotDuration: '00:15:00',
        slotLabelInterval: '01:00',
        slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
        eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
        hiddenDays: {!! json_encode($hiddenDays, JSON_HEX_TAG | JSON_HEX_AMP) !!},
        allDaySlot: false,
        nowIndicator: true,
        expandRows: true,
        height: 'auto',
        selectable: canCreate,
        selectMirror: true,
        editable: canEdit,
        eventDurationEditable: canEdit,
        businessHours: { daysOfWeek: [1, 2, 3, 4, 5, 6], startTime: {!! json_encode(substr($opensAt, 0, 5), JSON_HEX_TAG | JSON_HEX_AMP) !!}, endTime: {!! json_encode(substr($closesAt, 0, 5), JSON_HEX_TAG | JSON_HEX_AMP) !!} },
        events: (info, success, failure) => {
            const params = new URLSearchParams({ start: info.startStr, end: info.endStr, cancelled: cancelled.checked ? 1 : 0 });
            selected().forEach((id) => params.append('professionals[]', id));
            if (!selected().length) { success([]); return; }
            fetch({!! json_encode(route('appointments.events'), JSON_HEX_TAG | JSON_HEX_AMP) !!} + '?' + params, { headers: { Accept: 'application/json' } })
                .then((r) => r.json()).then(success).catch(failure);
        },
        datesSet: (info) => remember({ view: info.view.type }),
        eventContent: (arg) => {
            if (arg.event.display === 'background') return { html: `<div class="fc-event-title">${escapeHtml(arg.event.title)}</div>` };
            const p = arg.event.extendedProps;
            const icon = statuses[p.status]?.icon || 'bi-calendar';
            const alert = p.alert ? '<i class="bi bi-exclamation-triangle-fill" title="Alertas médicas"></i> ' : '';
            if (arg.view.type.startsWith('list')) {
                return { html: `${alert}<strong>${escapeHtml(arg.event.title)}</strong> · ${escapeHtml(p.procedure)} <span class="text-muted">· ${escapeHtml(p.professional)} · ${escapeHtml(p.statusLabel)}</span>` };
            }
            return { html: `<div class="ev-time"><i class="bi ${icon}"></i> ${arg.timeText}${p.overbooked ? ' · sobreturno' : ''}</div>`
                + `<div class="ev-title">${alert}${escapeHtml(arg.event.title)}</div><div class="ev-sub">${escapeHtml(p.procedure)}</div>` };
        },
        // Espacio libre: ir al alta con la hora (y el profesional, si se ve uno solo).
        select: (info) => {
            const params = new URLSearchParams({ start: info.startStr.replace('Z', '').slice(0, 16) });
            if (selected().length === 1) params.set('personal_id', selected()[0]);
            window.location = createUrl + '?' + params;
        },
        eventClick: (info) => { info.jsEvent.preventDefault(); if (info.event.display !== 'background') openModal(info.event); },
        eventDrop: (info) => move(info),
        eventResize: (info) => move(info),
    });
    calendar.render();
    // Recalcula el ancho cuando terminan de cargar fuentes y estilos.
    window.addEventListener('load', () => calendar.updateSize());
    document.fonts?.ready.then(() => calendar.updateSize());

    const refetch = () => { remember({ professionals: selected() }); calendar.refetchEvents(); };
    checks.forEach((c) => c.addEventListener('change', refetch));
    cancelled.addEventListener('change', refetch);
    document.getElementById('allProfessionals').addEventListener('click', () => { checks.forEach((c) => { c.checked = true; }); refetch(); });

    async function move(info, force = false) {
        const body = new URLSearchParams({ _method: 'PATCH', start: info.event.startStr.replace('Z', ''), end: (info.event.endStr || '').replace('Z', '') });
        if (force) body.set('force', 1);
        const response = await fetch(info.event.extendedProps.url.replace(/\/citas\/(\d+)$/, '/citas/$1/mover'), {
            method: 'POST', body, headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
        });
        const data = await response.json().catch(() => ({}));
        if (response.ok) { window.dexiToast?.(data.message, 'success'); return; }
        const errors = data.errors || {};
        if (errors.force && !force && confirm(errors.force[0].replace('Marca «Agendar como sobreturno» para agendar igual.', '¿Agendar igual como sobreturno?'))) {
            return move(info, true);
        }
        info.revert();
        if (!errors.force) window.dexiToast?.(Object.values(errors)[0]?.[0] || 'No se pudo reprogramar.', 'error');
    }

    // Modal con acciones de estado.
    const modalEl = document.getElementById('apptModal');
    const modal = () => bootstrap.Modal.getOrCreateInstance(modalEl);
    const actionLabels = { confirmada: ['Confirmar', 'bi-check2-circle', 'primary'], en_espera: ['Llegó', 'bi-door-open', 'warning'],
        en_atencion: ['Pasar a atención', 'bi-activity', 'info'], atendida: ['Terminar', 'bi-check2-all', 'success'],
        no_asistio: ['No asistió', 'bi-person-x', 'outline-danger'], cancelada: ['Cancelar', 'bi-x-circle', 'outline-dark'],
        agendada: ['Volver a agendada', 'bi-arrow-counterclockwise', 'light'] };

    function openModal(event) {
        const p = event.extendedProps;
        const fmt = (d) => d.toLocaleString('es', { timeZone: 'UTC', weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit', hour12: false });
        document.getElementById('apptPatient').textContent = event.title;
        document.getElementById('apptWhen').textContent = fmt(event.start) + (event.end ? ' – ' + event.end.toISOString().slice(11, 16) : '');
        document.getElementById('apptProcedure').textContent = p.procedure;
        document.getElementById('apptProfessional').textContent = p.professional;
        const badge = document.getElementById('apptStatus');
        badge.className = `badge bg-${statuses[p.status].color}`;
        badge.textContent = p.statusLabel;
        document.getElementById('apptAlert').classList.toggle('d-none', !p.alert);
        document.getElementById('apptOver').classList.toggle('d-none', !p.overbooked);
        document.getElementById('apptOpen').href = p.url;
        const actions = document.getElementById('apptActions');
        if (actions) {
            actions.innerHTML = '';
            (transitions[p.status] || []).forEach((status) => {
                const [label, icon, tone] = actionLabels[status];
                const button = document.createElement('button');
                button.type = 'button';
                button.className = `btn btn-sm btn-${tone}`;
                button.innerHTML = `<i class="bi ${icon}"></i> ${label}`;
                button.addEventListener('click', () => setStatus(event, status));
                actions.appendChild(button);
            });
        }
        modal().show();
    }

    async function setStatus(event, status) {
        const body = new URLSearchParams({ status });
        if (status === 'cancelada') {
            const reason = prompt('Motivo de la cancelación:');
            if (!reason) return;
            body.set('cancel_reason', reason);
        }
        const response = await fetch(event.extendedProps.url + '/estado', { method: 'POST', body, headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' } });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) { window.dexiToast?.(Object.values(data.errors || {})[0]?.[0] || 'No se pudo cambiar el estado.', 'error'); return; }
        modal().hide();
        window.dexiToast?.(`${event.title}: ${data.label}.`, 'success');
        calendar.refetchEvents();
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }
})();
</script>
@endpush
@endif
@endsection
