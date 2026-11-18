@extends('layouts.app')

@section('title', "Consulta {$consultation->code} · {$patient->full_name}")

@section('page')
@php
    use App\Models\ConsultationDiagnosis;
    use App\Support\Odontogram;
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $c = $consultation;
    $p = $patient;
    $open = $c->isOpen();
    $canEdit = $open && $can('consultations.edit');
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
    $alerts = $p->medicalAlerts();
    $toothGroups = [
        'Permanente' => array_merge(...Odontogram::rows('permanente')),
        'Temporal' => array_merge(...Odontogram::rows('temporal')),
    ];
    $grouped = $procedures->groupBy(fn ($proc) => $proc->specialty?->name ?? 'Otros');
    $conditions = Odontogram::CONDITIONS;
    $templates = [
        'Analgésico' => 'Ibuprofeno 400 mg, 1 comprimido cada 8 horas por 3 días (después de comer).',
        'Antibiótico' => 'Amoxicilina 500 mg, 1 cápsula cada 8 horas por 7 días.',
        'Post extracción' => "No enjuagarse ni escupir por 24 horas. Morder la gasa 30 minutos.\nDieta blanda y fría. No fumar ni usar bombilla por 72 horas.",
        'Post resina' => 'Puede comer normalmente. Evitar alimentos muy duros en la zona por 24 horas.',
        'Higiene' => 'Cepillado 3 veces al día con técnica indicada. Hilo dental diario. Enjuague bucal por la noche.',
    ];
@endphp
<div class="container-fluid">
    @include('admin.finance.partials.errors')

    {{-- Cabecera: paciente y consulta --}}
    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap gap-3 align-items-center">
            <span class="avatar avatar-lg {{ $alerts && $alerts[0]['critical'] ? 'is-alert' : '' }}">{{ $p->initials }}</span>
            <div class="flex-fill min-w-0">
                <a href="{{ route('consultations.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Consultas</a>
                <h1 class="mb-1 mt-1 text-truncate">
                    <a href="{{ route('patients.show', $p) }}" class="text-reset text-decoration-none">{{ $p->full_name }}</a>
                </h1>
                <div class="small text-muted d-flex flex-wrap column-gap-3 row-gap-1">
                    <span>{{ $p->code }}</span>
                    @if($p->age_label)<span>{{ $p->age_label }}</span>@endif
                    <span><i class="bi bi-hash"></i>{{ $c->code }}</span>
                    <span><i class="bi bi-clock"></i> {{ $c->started_at?->format('d/m/Y H:i') }}</span>
                    @if($c->appointment)<a href="{{ route('appointments.show', $c->appointment) }}" class="text-reset"><i class="bi bi-calendar-event"></i> cita {{ $c->appointment->starts_at->format('H:i') }}</a>@endif
                </div>
            </div>
            <div class="page-actions align-items-center">
                @if($open)
                    <span class="badge bg-warning text-dark fs-6"><i class="bi bi-pencil"></i> Abierta</span>
                @else
                    <span class="badge bg-success fs-6"><i class="bi bi-lock-fill"></i> Cerrada {{ $c->closed_at?->format('d/m H:i') }}</span>
                @endif
                <a href="{{ route('consultations.prescription', $c) }}" target="_blank" class="btn btn-light"><i class="bi bi-printer"></i> Receta</a>
                @if($tenantCompany->planAllows('ventas') && $can('sales.create') && $c->procedures->whereNull('sale_item_id')->isNotEmpty())
                    <a href="{{ route('sales.create', ['patient_id' => $p->id, 'consultation_id' => $c->id]) }}" class="btn {{ $open ? 'btn-light' : 'btn-primary' }}"><i class="bi bi-cash-coin"></i> Cobrar {{ $money($c->procedures->whereNull('sale_item_id')->sum(fn ($i) => $i->subtotal)) }}</a>
                @endif
                @if($canEdit)
                    <button type="button" class="btn btn-primary" id="closeBtn"><i class="bi bi-check2-circle"></i> Cerrar y firmar</button>
                @endif
            </div>
        </div>
        <div class="card-body border-top py-2 d-flex align-items-center gap-2 flex-wrap small">
            <span class="text-muted">Profesional:</span>
            @if($canEdit && $professionals->count() > 1)
                <form method="POST" action="{{ route('consultations.professional', $c) }}" class="d-flex gap-2 m-0">
                    @csrf @method('PUT')
                    <select name="personal_id" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Profesional">
                        @foreach($professionals as $pro)
                            <option value="{{ $pro->id }}" @selected($pro->id === $c->personal_id)>{{ $pro->full_name }}</option>
                        @endforeach
                    </select>
                </form>
            @else
                <strong>{{ $c->professional?->full_name }}</strong>
            @endif
            <span class="ms-auto text-muted" id="saveStatus" aria-live="polite">@if($open) Los cambios se guardan solos. @endif</span>
        </div>
    </div>

    @if($alerts)
        <div class="medical-banner" role="alert">
            <i class="bi bi-exclamation-octagon-fill"></i>
            <div>
                <div class="fw-bold mb-1">Antes de atender</div>
                <div class="d-flex flex-wrap gap-1">
                    @foreach($alerts as $alert)<span class="alert-chip {{ $alert['critical'] ? 'critical' : '' }}">{{ $alert['label'] }}</span>@endforeach
                </div>
                @if($p->medications)<div class="small mt-1"><strong>Medicación:</strong> {{ $p->medications }}</div>@endif
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('consultations.notes', $c) }}" id="notes-form">@csrf @method('PUT')</form>

    <div class="row g-3">
        <div class="col-xl-7">
            <section class="card form-section">
                <div class="card-header"><span class="section-icon"><i class="bi bi-chat-square-text"></i></span> Motivo, anamnesis y examen</div>
                <div class="card-body row g-3">
                    <div class="col-12">
                        <label class="form-label" for="reason">Motivo de consulta</label>
                        <input type="text" id="reason" name="reason" form="notes-form" class="form-control" maxlength="255" value="{{ old('reason', $c->reason) }}" @disabled(! $canEdit) placeholder="Dolor en molar inferior derecho…">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="history">Enfermedad actual / anamnesis</label>
                        <textarea id="history" name="history" form="notes-form" rows="4" class="form-control" @disabled(! $canEdit) placeholder="Desde cuándo, cómo es el dolor, qué lo calma…">{{ old('history', $c->history) }}</textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="exam">Examen clínico</label>
                        <textarea id="exam" name="exam" form="notes-form" rows="4" class="form-control" @disabled(! $canEdit) placeholder="Extraoral, intraoral, percusión, vitalidad, Rx…">{{ old('exam', $c->exam) }}</textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="plan">Plan / evolución</label>
                        <textarea id="plan" name="plan" form="notes-form" rows="2" class="form-control" @disabled(! $canEdit) placeholder="Qué se hizo hoy y qué sigue.">{{ old('plan', $c->plan) }}</textarea>
                    </div>
                </div>
            </section>

            @if($can('odontogram.view'))
                <section class="card form-section">
                    <div class="card-header"><span class="section-icon" style="--tone:#2563eb"><i class="bi bi-grid-3x3"></i></span> Odontograma
                        <span class="section-hint">{{ $canEdit && $can('odontogram.edit') ? 'Lo que marques queda ligado a esta consulta' : 'Solo lectura' }}</span></div>
                    <div class="card-body">
                        @include('admin.odontogram.chart', ['patient' => $p, 'editable' => $canEdit && $can('odontogram.edit'), 'consultation' => $open ? $c : null])
                    </div>
                </section>
            @endif
        </div>

        <div class="col-xl-5">
            {{-- Diagnósticos --}}
            <section class="card form-section">
                <div class="card-header"><span class="section-icon" style="--tone:#dc3545"><i class="bi bi-search-heart"></i></span> Diagnósticos <span class="badge bg-light text-dark border ms-auto">{{ $c->diagnoses->count() }}</span></div>
                <ul class="list-group list-group-flush">
                    @forelse($c->diagnoses as $d)
                        <li class="list-group-item d-flex align-items-start gap-2">
                            <div class="flex-fill">
                                <div class="fw-semibold">{{ $d->label }} @if($d->cie10)<span class="badge bg-light text-muted border fw-normal">{{ $d->cie10 }}</span>@endif</div>
                                <div class="small text-muted">{{ collect([$d->tooth ? 'Pieza ' . $d->tooth . ($d->surfaces ? ' · ' . $d->surfaces : '') : null, $d->notes])->filter()->implode(' · ') ?: 'General' }}</div>
                            </div>
                            @if($canEdit)
                                <form method="POST" action="{{ route('consultations.diagnoses.destroy', [$c, $d]) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-light text-danger" title="Quitar"><i class="bi bi-x-lg"></i></button></form>
                            @endif
                        </li>
                    @empty
                        <li class="list-group-item small text-muted">Sin diagnósticos.</li>
                    @endforelse
                </ul>
                @if($canEdit)
                    <form method="POST" action="{{ route('consultations.diagnoses.store', $c) }}" class="card-body border-top row g-2" id="diagnosis-form">
                        @csrf
                        <div class="col-12">
                            <select name="code" class="form-select" aria-label="Diagnóstico" id="diagnosis-code" required>
                                <option value="">Agregar diagnóstico…</option>
                                @foreach(ConsultationDiagnosis::CATALOG as $key => [$label, $cie])
                                    <option value="{{ $key }}">{{ $label }}{{ $cie ? " ({$cie})" : '' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12" id="diagnosis-label" hidden>
                            <input type="text" name="label" class="form-control" maxlength="150" placeholder="¿Cuál?">
                        </div>
                        <div class="col-5">
                            @include('admin.consultations.partials.tooth-select', ['name' => 'tooth', 'id' => 'diagnosis-tooth'])
                        </div>
                        <div class="col-7">@include('admin.consultations.partials.surfaces', ['prefix' => 'dx'])</div>
                        <div class="col-9"><input type="text" name="notes" class="form-control" maxlength="255" placeholder="Nota (opcional)"></div>
                        <div class="col-3"><button class="btn btn-outline-primary w-100" type="submit">Agregar</button></div>
                    </form>
                @endif
            </section>

            {{-- Plan de tratamiento: lo pendiente del plan aprobado, «hecho hoy» en un clic --}}
            @if($tenantCompany->planAllows('tratamientos') && $can('treatment_plans.view'))
                <section class="card form-section">
                    <div class="card-header"><span class="section-icon" style="--tone:#7c3aed"><i class="bi bi-clipboard2-check"></i></span> Plan de tratamiento
                        @if($planItems->isNotEmpty())<span class="section-hint">{{ $planItems->first()->plan->code }} · {{ $planItems->count() }} pendientes</span>@endif</div>
                    @if($planItems->isNotEmpty())
                        <ul class="list-group list-group-flush">
                            @foreach($planItems->take(8) as $planItem)
                                <li class="list-group-item d-flex align-items-center gap-2">
                                    @if($planItem->tooth)<span class="badge bg-light text-dark border tabular">{{ $planItem->tooth }}{{ $planItem->surfaces ? ' ' . $planItem->surfaces : '' }}</span>@endif
                                    <span class="flex-fill small"><span class="fw-semibold">{{ $planItem->name }}</span> <span class="text-muted">· fase {{ $planItem->phase }}</span></span>
                                    @if($canEdit)
                                        <form method="POST" action="{{ route('consultations.plan-item', [$c, $planItem]) }}" class="m-0">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-success" type="submit" title="Registrar como realizado hoy"><i class="bi bi-check-lg"></i> Hecho hoy</button>
                                        </form>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        <div class="card-body py-2 small"><a href="{{ route('treatment-plans.show', $planItems->first()->plan) }}" class="text-decoration-none">Ver el plan completo</a></div>
                    @else
                        <div class="card-body small">
                            @if($openPlan)
                                <p class="mb-2">Hay un plan <strong>{{ $openPlan->status_label }}</strong>: {{ $openPlan->title }} ({{ $openPlan->code }}).</p>
                                <a href="{{ route('treatment-plans.show', $openPlan) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-clipboard2-check"></i> Ver y presentar el plan</a>
                            @else
                            <p class="text-muted mb-2">No hay un plan aprobado en curso.</p>
                            @endif
                            @if(! $openPlan && $can('treatment_plans.create'))
                                <a href="{{ route('treatment-plans.create', ['patient_id' => $p->id, 'consultation_id' => $c->id]) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-clipboard2-plus"></i> Armar plan con los hallazgos del odontograma</a>
                            @endif
                        </div>
                    @endif
                </section>
            @endif
            {{-- Procedimientos realizados --}}
            <section class="card form-section" id="procedures-card">
                <div class="card-header"><span class="section-icon" style="--tone:#059669"><i class="bi bi-tools"></i></span> Realizado hoy
                    <span class="section-hint">{{ $money($c->total) }}</span></div>
                <ul class="list-group list-group-flush">
                    @forelse($c->procedures as $item)
                        <li class="list-group-item d-flex align-items-start gap-2">
                            <div class="flex-fill">
                                <div class="fw-semibold">{{ $item->name }}@if($item->quantity > 1) <span class="text-muted">×{{ $item->quantity }}</span>@endif</div>
                                <div class="small text-muted">{{ collect([$item->tooth ? 'Pieza ' . $item->tooth . ($item->surfaces ? ' · ' . $item->surfaces : '') : null, $item->notes])->filter()->implode(' · ') ?: 'General' }}</div>
                            </div>
                            <span class="tabular small fw-semibold">{{ $money($item->subtotal) }}</span>
                            @if($canEdit)
                                <form method="POST" action="{{ route('consultations.procedures.destroy', [$c, $item]) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-light text-danger" title="Quitar"><i class="bi bi-x-lg"></i></button></form>
                            @endif
                        </li>
                    @empty
                        <li class="list-group-item small text-muted">Todavía no se registró nada.</li>
                    @endforelse
                </ul>
                @if($canEdit)
                    <form method="POST" action="{{ route('consultations.procedures.store', $c) }}" class="card-body border-top row g-2" id="procedure-form">
                        @csrf
                        <div class="col-12">
                            <select name="procedure_id" id="procedure-select" class="form-select" aria-label="Procedimiento" required>
                                <option value="">Agregar procedimiento…</option>
                                @foreach($grouped as $specialty => $items)
                                    <optgroup label="{{ $specialty }}">
                                        @foreach($items as $proc)
                                            <option value="{{ $proc->id }}" data-price="{{ (float) $proc->price }}" data-applies="{{ $proc->applies_to }}" data-mark="{{ $marks[$proc->code] ?? '' }}">{{ $proc->name }}{{ $proc->hasPrice() ? ' · ' . $money($proc->price) : '' }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            @if($procedures->isEmpty())<div class="form-text">Carga el <a href="{{ route('procedures.index') }}">tarifario</a> primero.</div>@endif
                        </div>
                        <div class="col-5">@include('admin.consultations.partials.tooth-select', ['name' => 'tooth', 'id' => 'procedure-tooth'])</div>
                        <div class="col-7">@include('admin.consultations.partials.surfaces', ['prefix' => 'px'])</div>
                        <div class="col-4">
                            <input type="number" name="quantity" class="form-control" min="1" max="99" value="1" aria-label="Cantidad" title="Cantidad">
                        </div>
                        <div class="col-8">
                            <div class="input-group">
                                <span class="input-group-text">{{ \App\Support\Money::symbol($tenantCompany) }}</span>
                                <input type="number" name="price" id="procedure-price" class="form-control" step="0.5" min="0" placeholder="Precio" aria-label="Precio">
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="d-flex align-items-center gap-2 small">
                                <input class="form-check-input mt-0" type="checkbox" id="mark-check" checked>
                                <label for="mark-check" class="form-check-label">Marcar en el odontograma como</label>
                                <select name="mark" id="mark-select" class="form-select form-select-sm w-auto">
                                    <option value="">— no marcar —</option>
                                    @foreach($conditions as $key => [$label, $type])
                                        @if($type === 'tratamiento')<option value="{{ $key }}">{{ $label }}</option>@endif
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-9"><input type="text" name="notes" class="form-control" maxlength="255" placeholder="Nota (material, anestesia…)"></div>
                        <div class="col-3"><button class="btn btn-success w-100" type="submit">Agregar</button></div>
                    </form>
                @endif
            </section>

            {{-- Indicaciones y próxima visita --}}
            <section class="card form-section">
                <div class="card-header"><span class="section-icon" style="--tone:#7c3aed"><i class="bi bi-prescription2"></i></span> Indicaciones y receta</div>
                <div class="card-body">
                    @if($canEdit)
                        <div class="d-flex flex-wrap gap-1 mb-2">
                            @foreach($templates as $label => $text)
                                <button type="button" class="quick-fill" data-template="{{ $text }}">+ {{ $label }}</button>
                            @endforeach
                        </div>
                    @endif
                    <textarea id="indications" name="indications" form="notes-form" rows="5" class="form-control mb-2" @disabled(! $canEdit) placeholder="Medicamentos, dosis y cuidados.">{{ old('indications', $c->indications) }}</textarea>
                    <label class="form-label" for="next_visit">Próxima visita</label>
                    <input type="text" id="next_visit" name="next_visit" form="notes-form" class="form-control" maxlength="255" value="{{ old('next_visit', $c->next_visit) }}" @disabled(! $canEdit) list="next-visits" placeholder="Control en 7 días…">
                    <datalist id="next-visits"><option value="Control en 7 días"><option value="Control en 15 días"><option value="Control en 1 mes"><option value="Continuar tratamiento la próxima semana"><option value="Alta"></datalist>
                    @if($open && $p->active && $can('appointments.create') && Route::has('appointments.create'))
                        <a href="{{ route('appointments.create', ['patient_id' => $p->id, 'personal_id' => $c->personal_id]) }}" class="btn btn-sm btn-link px-0 mt-1 text-decoration-none" target="_blank"><i class="bi bi-calendar-plus"></i> Agendar la próxima cita</a>
                    @endif
                </div>
            </section>

            @if(! $open)
                <section class="card form-section">
                    <div class="card-header"><span class="section-icon" style="--tone:#475569"><i class="bi bi-journal-plus"></i></span> Notas de corrección</div>
                    <div class="card-body">
                        <p class="small text-muted">La consulta está firmada{{ $c->closer ? ' por ' . $c->closer->name : '' }}. Las correcciones se agregan con fecha y autor; lo anterior no se modifica.</p>
                        @if($c->addendum)<div class="small mb-3 p-2 rounded-3" style="background:#f6f8f9; white-space: pre-line">{{ $c->addendum }}</div>@endif
                        @if($can('consultations.edit'))
                            <form method="POST" action="{{ route('consultations.addendum', $c) }}" class="d-flex gap-2">
                                @csrf
                                <input type="text" name="addendum" class="form-control" maxlength="1000" placeholder="Nota de corrección…" required>
                                <button class="btn btn-outline-primary" type="submit">Agregar</button>
                            </form>
                        @endif
                    </div>
                </section>
            @endif

            @if($previous->isNotEmpty())
                <section class="card">
                    <div class="card-header"><i class="bi bi-clock-history"></i> Consultas anteriores</div>
                    <ul class="list-group list-group-flush small">
                        @foreach($previous as $prev)
                            <li class="list-group-item">
                                <a href="{{ route('consultations.show', $prev) }}" class="text-decoration-none text-reset d-flex justify-content-between gap-2">
                                    <span>{{ $prev->started_at?->format('d/m/Y') }} · {{ $prev->reason ?: 'Consulta' }}</span>
                                    <span class="text-muted">{{ $prev->professional?->short_name }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>
</div>

@if($canEdit)
    <form method="POST" action="{{ route('consultations.close', $c) }}" id="close-form">@csrf</form>
@endif

<script>
(function () {
    const notes = document.getElementById('notes-form');
    if (!notes) return;
    const status = document.getElementById('saveStatus');
    const fields = [...document.querySelectorAll('[form="notes-form"]')];
    let timer = null, dirty = false;

    async function save() {
        if (!dirty) return true;
        dirty = false;
        const body = new FormData(notes);
        fields.forEach((f) => body.set(f.name, f.value));
        status.textContent = 'Guardando…';
        try {
            const response = await fetch(notes.action, { method: 'POST', body, headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (!response.ok) throw new Error();
            status.innerHTML = `<i class="bi bi-check2"></i> Guardado ${data.saved_at}`;
            return true;
        } catch (e) {
            dirty = true;
            status.innerHTML = '<span class="text-danger">No se pudo guardar: revisa tu conexión.</span>';
            return false;
        }
    }
    fields.forEach((f) => f.addEventListener('input', () => { dirty = true; status.textContent = 'Cambios sin guardar…'; clearTimeout(timer); timer = setTimeout(save, 1200); }));
    window.addEventListener('beforeunload', (e) => { if (dirty) { save(); e.preventDefault(); } });
    // Antes de agregar un diagnóstico o procedimiento (que recarga la página), guarda las notas.
    document.querySelectorAll('#diagnosis-form, #procedure-form').forEach((form) => form.addEventListener('submit', async (e) => {
        if (!dirty) return;
        e.preventDefault();
        if (await save()) form.submit();
    }));

    // Plantillas de indicaciones.
    document.querySelectorAll('[data-template]').forEach((b) => b.addEventListener('click', () => {
        const box = document.getElementById('indications');
        box.value = (box.value.trim() ? box.value.trim() + '\n' : '') + b.dataset.template;
        box.dispatchEvent(new Event('input'));
        box.focus();
    }));

    // Cerrar y firmar: guarda y cierra.
    document.getElementById('closeBtn')?.addEventListener('click', async () => {
        if (!confirm('¿Cerrar y firmar la consulta? Después solo se le podrán agregar notas de corrección.')) return;
        dirty = true;
        if (await save()) document.getElementById('close-form').submit();
    });

    // Diagnóstico «Otro»: pide el texto.
    document.getElementById('diagnosis-code')?.addEventListener('change', (e) => { document.getElementById('diagnosis-label').hidden = e.target.value !== 'otro'; });

    // Procedimiento: precio del tarifario y sugerencia de qué marcar en el odontograma.
    const select = document.getElementById('procedure-select');
    const markSelect = document.getElementById('mark-select'), markCheck = document.getElementById('mark-check');
    select?.addEventListener('change', () => {
        const option = select.selectedOptions[0];
        document.getElementById('procedure-price').value = option?.dataset.price || '';
        markSelect.value = option?.dataset.mark || '';
        markCheck.checked = !!option?.dataset.mark;
    });
    markCheck?.addEventListener('change', () => { if (!markCheck.checked) markSelect.value = ''; });
    markSelect?.addEventListener('change', () => { markCheck.checked = !!markSelect.value; });

    // Desde el odontograma: «Registrar algo hecho en esta pieza».
    document.addEventListener('odontogram:tooth', (e) => {
        const { tooth, surface } = e.detail;
        document.getElementById('procedure-tooth').value = tooth;
        document.querySelectorAll('#procedure-form [name="surfaces[]"]').forEach((c) => { c.checked = c.value === surface; });
        document.getElementById('procedures-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
        setTimeout(() => select?.focus(), 400);
    });
})();
</script>
@endsection
