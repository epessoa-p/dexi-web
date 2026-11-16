{{-- Alta y edición de una cita. Parámetros: $appointment, $patient, $professionals, $procedures, $branches, $defaultMinutes. --}}
@php
    $a = $appointment;
    $isNew = ! $a->exists;
    $start = $a->starts_at;
    $date = old('date', $start?->toDateString() ?? now()->toDateString());
    $time = old('time', $start?->format('H:i'));
    $duration = (int) old('duration', $a->exists ? $a->duration_minutes : ($a->procedure?->duration_minutes ?? $defaultMinutes));
    $personalId = (string) old('personal_id', $a->personal_id);
    $procedureId = (string) old('procedure_id', $a->procedure_id);
    $selectedPatient = old('patient_id') ? \App\Models\Patient::find(old('patient_id')) : $patient;
    $durations = collect([15, 20, 30, 45, 60, 75, 90, 120, 150, 180])->push($duration)->unique()->sort()->values();
    $grouped = $procedures->groupBy(fn ($p) => $p->specialty?->name ?? 'Otros');
@endphp
<div class="container-fluid" style="max-width: 980px;">
    <div class="page-header">
        <div>
            <a href="{{ $isNew ? route('appointments.index', ['date' => $date]) : route('appointments.show', $a) }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> {{ $isNew ? 'Agenda' : 'Cita' }}</a>
            <h1 class="mt-1">{{ $isNew ? 'Nueva cita' : 'Editar cita' }}</h1>
        </div>
    </div>

    <form method="POST" action="{{ $isNew ? route('appointments.store') : route('appointments.update', $a) }}" id="appointment-form" novalidate>
        @csrf
        @unless($isNew) @method('PUT') @endunless

        @error('force')
            <div class="alert alert-warning d-flex gap-3 align-items-start" role="alert">
                <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                <div class="flex-fill">
                    <div class="fw-semibold mb-1">{{ $message }}</div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="force" value="1" id="force">
                        <label class="form-check-label" for="force">Agendar como sobreturno</label>
                    </div>
                </div>
            </div>
        @enderror
        @if($errors->any() && ! $errors->has('force'))
            <div class="alert alert-danger d-flex gap-2"><i class="bi bi-exclamation-circle"></i><div>{{ $errors->first() }}</div></div>
        @endif

        <section class="card form-section">
            <div class="card-header"><span class="section-icon" style="--tone:#2563eb"><i class="bi bi-person"></i></span> Paciente</div>
            <div class="card-body">
                <input type="hidden" name="patient_id" id="patient_id" value="{{ $selectedPatient?->id }}">
                <div id="patient-selected" class="d-flex align-items-center gap-2 {{ $selectedPatient ? '' : 'd-none' }}">
                    <span class="avatar" id="patient-avatar">{{ $selectedPatient?->initials }}</span>
                    <div class="flex-fill min-w-0">
                        <div class="fw-semibold" id="patient-name">{{ $selectedPatient?->full_name }}</div>
                        <div class="small text-muted" id="patient-detail">{{ $selectedPatient ? collect([$selectedPatient->code, $selectedPatient->age_label, $selectedPatient->phone])->filter()->implode(' · ') : '' }}</div>
                        <div id="patient-alert" class="{{ $selectedPatient?->hasAlerts() ? '' : 'd-none' }}">
                            <span class="alert-chip critical mt-1"><i class="bi bi-exclamation-triangle-fill"></i> Tiene alertas médicas: revisa la ficha antes de atender</span>
                        </div>
                    </div>
                    @if($isNew)<button type="button" class="btn btn-sm btn-light" id="patient-change">Cambiar</button>@endif
                </div>
                <div id="patient-search" class="{{ $selectedPatient ? 'd-none' : '' }}">
                    <div class="search-hero position-relative">
                        <i class="bi bi-search"></i>
                        <input type="search" class="form-control @error('patient_id') is-invalid @enderror" id="patient-query" placeholder="Nombre, CI, teléfono o HC…" autocomplete="off" aria-label="Buscar paciente" aria-controls="patient-results">
                    </div>
                    <div class="list-group mt-2 shadow-sm" id="patient-results" role="listbox"></div>
                    @if(auth()->user()->hasPermissionInCompany('patients.create', $tenantCompany))
                        <a href="{{ route('patients.create', ['return' => 'appointment', 'start' => "{$date}T" . ($time ?? '')]) }}" class="small text-decoration-none d-inline-block mt-2" id="patient-new"><i class="bi bi-person-plus"></i> ¿Paciente nuevo? Regístralo y vuelves aquí</a>
                    @endif
                </div>
            </div>
        </section>

        <section class="card form-section">
            <div class="card-header"><span class="section-icon"><i class="bi bi-calendar-event"></i></span> Cuándo y con quién</div>
            <div class="card-body row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="personal_id">Profesional <span class="text-danger">*</span></label>
                    <select id="personal_id" name="personal_id" class="form-select @error('personal_id') is-invalid @enderror" required>
                        @foreach($professionals as $professional)
                            <option value="{{ $professional->id }}" @selected($personalId === (string) $professional->id)>{{ $professional->full_name }}{{ $professional->specialty ? ' · ' . $professional->specialty->name : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="procedure_id">Procedimiento</label>
                    <select id="procedure_id" name="procedure_id" class="form-select">
                        <option value="" data-duration="">Consulta / sin definir</option>
                        @foreach($grouped as $specialty => $items)
                            <optgroup label="{{ $specialty }}">
                                @foreach($items as $procedure)
                                    <option value="{{ $procedure->id }}" data-duration="{{ $procedure->duration_minutes }}" @selected($procedureId === (string) $procedure->id)>{{ $procedure->name }} ({{ $procedure->duration_label }})</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @if($procedures->isEmpty())<div class="form-text">Carga tu <a href="{{ route('procedures.index') }}">tarifario</a> para elegir procedimientos y su duración.</div>@endif
                </div>
                <div class="col-sm-5 col-md-4">
                    <label class="form-label" for="date">Fecha <span class="text-danger">*</span></label>
                    <input type="date" id="date" name="date" class="form-control" value="{{ $date }}" required>
                </div>
                <div class="col-sm-4 col-md-4">
                    <label class="form-label" for="time">Hora <span class="text-danger">*</span></label>
                    <input type="time" id="time" name="time" class="form-control @error('time') is-invalid @enderror" value="{{ $time }}" step="300" required>
                    @error('time')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-sm-3 col-md-4">
                    <label class="form-label" for="duration">Duración</label>
                    <select id="duration" name="duration" class="form-select">
                        @foreach($durations as $minutes)
                            <option value="{{ $minutes }}" @selected($duration === $minutes)>{{ $minutes >= 60 ? intdiv($minutes, 60) . ' h' . ($minutes % 60 ? ' ' . $minutes % 60 : '') : $minutes . ' min' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <div class="small text-muted mb-1" id="slots-title">Horas libres</div>
                    <div class="d-flex flex-wrap gap-1" id="slots" aria-live="polite"></div>
                </div>
                @if($branches->count() > 1)
                    <div class="col-md-6">
                        <label class="form-label" for="branch_id">Consultorio</label>
                        <select id="branch_id" name="branch_id" class="form-select">
                            <option value="">—</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" @selected((string) old('branch_id', $a->branch_id) === (string) $branch->id)>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>
        </section>

        <section class="card form-section">
            <div class="card-header"><span class="section-icon" style="--tone:#7c3aed"><i class="bi bi-chat-left-text"></i></span> Motivo y notas</div>
            <div class="card-body row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="reason">Motivo de consulta</label>
                    <input type="text" id="reason" name="reason" class="form-control" maxlength="255" value="{{ old('reason', $a->reason) }}" list="reasons" placeholder="Dolor, control, limpieza…">
                    <datalist id="reasons"><option value="Dolor de muela"><option value="Control"><option value="Limpieza"><option value="Evaluación / presupuesto"><option value="Urgencia"><option value="Sensibilidad"><option value="Estética"></datalist>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="notes">Notas internas</label>
                    <input type="text" id="notes" name="notes" class="form-control" maxlength="2000" value="{{ old('notes', $a->notes) }}" placeholder="Viene con su mamá, traer radiografía…">
                </div>
            </div>
        </section>

        <div class="sticky-actions">
            <a href="{{ $isNew ? route('appointments.index', ['date' => $date]) : route('appointments.show', $a) }}" class="btn btn-light">Cancelar</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-calendar-check"></i> {{ $isNew ? 'Agendar' : 'Guardar cambios' }}</button>
        </div>
    </form>
</div>

<script>
(function () {
    const $ = (id) => document.getElementById(id);
    const searchUrl = {!! json_encode(route('patients.search')) !!};
    const slotsUrl = {!! json_encode(route('appointments.slots')) !!};
    const ignore = {!! json_encode($a->exists ? $a->id : null) !!};

    // ── Paciente: búsqueda con teclado ──
    const query = $('patient-query'), results = $('patient-results');
    let timer, items = [], active = -1;
    const choose = (p) => {
        $('patient_id').value = p.id;
        $('patient-name').textContent = p.name;
        $('patient-detail').textContent = p.detail;
        $('patient-avatar').textContent = p.name.split(' ').slice(0, 2).map((w) => w[0]).join('').toUpperCase();
        $('patient-alert').classList.toggle('d-none', !p.alert);
        $('patient-selected').classList.remove('d-none');
        $('patient-search').classList.add('d-none');
        results.innerHTML = '';
        $('personal_id').focus();
    };
    const render = () => {
        results.innerHTML = '';
        items.forEach((p, i) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center' + (i === active ? ' active' : '');
            button.setAttribute('role', 'option');
            button.innerHTML = `<span><span class="fw-semibold"></span><small class="d-block opacity-75"></small></span>${p.alert ? '<span class="alert-chip critical">Alerta</span>' : ''}`;
            button.querySelector('.fw-semibold').textContent = p.name;
            button.querySelector('small').textContent = p.detail;
            button.addEventListener('click', () => choose(p));
            results.appendChild(button);
        });
        if (!items.length && query.value.trim().length >= 2) {
            results.innerHTML = '<div class="list-group-item small text-muted">Sin resultados. ¿Es un paciente nuevo?</div>';
        }
    };
    query?.addEventListener('input', () => {
        clearTimeout(timer);
        const term = query.value.trim();
        if (term.length < 2) { items = []; render(); return; }
        timer = setTimeout(async () => {
            items = await fetch(searchUrl + '?q=' + encodeURIComponent(term), { headers: { Accept: 'application/json' } }).then((r) => r.json()).catch(() => []);
            active = items.length ? 0 : -1;
            render();
        }, 220);
    });
    query?.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, items.length - 1); render(); }
        if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); render(); }
        if (e.key === 'Enter') { e.preventDefault(); if (items[active]) choose(items[active]); }
    });
    $('patient-change')?.addEventListener('click', () => {
        $('patient_id').value = '';
        $('patient-selected').classList.add('d-none');
        $('patient-search').classList.remove('d-none');
        query.value = ''; query.focus();
    });
    if (!$('patient_id').value) query?.focus();

    // ── Procedimiento → duración ──
    $('procedure_id').addEventListener('change', (e) => {
        const minutes = e.target.selectedOptions[0]?.dataset.duration;
        if (!minutes) return;
        const select = $('duration');
        if (![...select.options].some((o) => o.value === minutes)) select.add(new Option(minutes + ' min', minutes));
        select.value = minutes;
        loadSlots();
    });

    // ── Horas libres ──
    async function loadSlots() {
        const box = $('slots'), title = $('slots-title');
        if (!$('personal_id').value || !$('date').value) return;
        const params = new URLSearchParams({ personal_id: $('personal_id').value, date: $('date').value, duration: $('duration').value });
        if (ignore) params.set('ignore', ignore);
        box.innerHTML = '<span class="small text-muted">Buscando…</span>';
        const data = await fetch(slotsUrl + '?' + params, { headers: { Accept: 'application/json' } }).then((r) => r.json()).catch(() => null);
        if (!data) { box.innerHTML = ''; return; }
        title.textContent = data.schedule.length ? `Horas libres · atiende ${data.schedule.join(' y ')}` : (data.has_schedule ? 'No atiende ese día' : 'Horas libres (horario general de la clínica)');
        box.innerHTML = '';
        if (!data.slots.length) { box.innerHTML = '<span class="small text-muted">No quedan horas libres ese día.</span>'; return; }
        data.slots.forEach((slot) => {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'btn btn-sm ' + (slot === $('time').value ? 'btn-primary' : 'btn-outline-primary');
            chip.textContent = slot;
            chip.addEventListener('click', () => {
                $('time').value = slot;
                box.querySelectorAll('button').forEach((b) => b.className = 'btn btn-sm btn-outline-primary');
                chip.className = 'btn btn-sm btn-primary';
            });
            box.appendChild(chip);
        });
    }
    ['personal_id', 'date', 'duration'].forEach((id) => $(id).addEventListener('change', loadSlots));
    loadSlots();

    // El enlace «paciente nuevo» lleva la fecha y hora elegidas.
    const newLink = $('patient-new');
    newLink?.addEventListener('click', () => {
        const url = new URL(newLink.href);
        url.searchParams.set('start', `${$('date').value}T${$('time').value}`);
        url.searchParams.set('personal_id', $('personal_id').value);
        newLink.href = url;
    });
})();
</script>
