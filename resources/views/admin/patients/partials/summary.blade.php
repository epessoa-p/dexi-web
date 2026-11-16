{{-- Pestaña «Resumen» de la ficha. --}}
@php
    $conditions = collect($p->conditions ?? [])->map(fn ($k) => \App\Models\Patient::CONDITIONS[$k][0] ?? null)->filter();
    $row = fn ($value) => filled($value) ? e($value) : '<span class="text-muted">—</span>';
@endphp
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="bi bi-heart-pulse text-danger"></i> Antecedentes médicos</span>
                <small class="text-muted fw-normal">
                    {{ $p->medical_updated_at ? 'Actualizado el ' . $p->medical_updated_at->format('d/m/Y') : 'Sin registrar' }}
                </small>
            </div>
            <div class="card-body">
                @if($conditions->isEmpty() && blank($p->allergies) && blank($p->medications) && ! $p->pregnant && blank($p->medical_notes))
                    <div class="d-flex gap-3 align-items-center">
                        <div class="empty-icon m-0" style="width:3rem;height:3rem;font-size:1.3rem;background:#fff4e5;color:#9a4b00"><i class="bi bi-clipboard2-pulse"></i></div>
                        <div>
                            <div class="fw-semibold">Falta la anamnesis</div>
                            <div class="small text-muted">Pregunta por enfermedades, alergias y medicación antes de la primera atención.</div>
                        </div>
                        @if($can('patients.edit'))<a href="{{ route('patients.edit', $p) }}#allergies" class="btn btn-sm btn-outline-primary ms-auto">Completar</a>@endif
                    </div>
                @else
                    <dl class="info-list">
                        <dt>Condiciones</dt><dd>{!! $conditions->isNotEmpty() ? e($conditions->implode(', ')) : '<span class="text-muted">Ninguna marcada</span>' !!}</dd>
                        <dt>Alergias</dt><dd>{!! filled($p->allergies) ? '<span class="text-danger fw-semibold">' . e($p->allergies) . '</span>' : '<span class="text-muted">Ninguna conocida</span>' !!}</dd>
                        <dt>Medicación</dt><dd>{!! $row($p->medications) !!}</dd>
                        @if($p->pregnant)<dt>Embarazo</dt><dd class="text-danger fw-semibold">Sí</dd>@endif
                        <dt>Grupo sanguíneo</dt><dd>{!! $row($p->blood_type) !!}</dd>
                        <dt>Otros</dt><dd>{!! $row($p->medical_notes) !!}</dd>
                    </dl>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="bi bi-images"></i> Documentos recientes</span>
                <a href="{{ route('patients.show', [$p, 'tab' => 'documentos']) }}" class="small text-decoration-none">Ver todos ({{ $p->documents_count }})</a>
            </div>
            <div class="card-body">
                @if($recentDocuments->isEmpty())
                    <p class="text-muted small mb-0">Sin documentos. Sube el consentimiento firmado, radiografías o fotos desde la pestaña <a href="{{ route('patients.show', [$p, 'tab' => 'documentos']) }}">Documentos y fotos</a>.</p>
                @else
                    <div class="doc-grid" style="grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));">
                        @foreach($recentDocuments as $document)
                            @include('admin.patients.partials.document-card', ['document' => $document, 'compact' => true])
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        @if($agenda)
            @php $next = $appointments->filter(fn ($a) => $a->starts_at->gte(now()->startOfDay()) && ! $a->isClosed())->sortBy('starts_at')->first(); @endphp
            <div class="card mb-3">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="kpi-icon" style="--tone:#7c3aed"><i class="bi bi-calendar-event"></i></div>
                    <div class="flex-fill min-w-0">
                        <div class="kpi-label">Próxima cita</div>
                        @if($next)
                            <a href="{{ route('appointments.show', $next) }}" class="fw-semibold text-decoration-none text-reset d-block text-truncate">{{ ucfirst($next->starts_at->locale('es')->translatedFormat('l j \d\e F, H:i')) }}</a>
                            <div class="small text-muted text-truncate">{{ $next->title }} · {{ $next->professional?->full_name }}</div>
                        @else
                            <div class="fw-semibold">Sin cita agendada</div>
                        @endif
                    </div>
                    @if(! $next && $p->active && $can('appointments.create'))<a href="{{ route('appointments.create', ['patient_id' => $p->id]) }}" class="btn btn-sm btn-outline-primary">Agendar</a>@endif
                </div>
            </div>
        @endif
        @if($clinical && $odontogramSummary && $can('odontogram.view'))
            <a href="{{ route('patients.show', [$p, 'tab' => 'odontograma']) }}" class="card mb-3 text-decoration-none text-reset">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="kpi-icon" style="--tone: {{ $odontogramSummary['findings'] ? '#dc3545' : '#0e9f9a' }}"><i class="bi bi-grid-3x3"></i></div>
                    <div class="flex-fill">
                        <div class="kpi-label">Odontograma</div>
                        <div class="fw-semibold">{{ $odontogramSummary['findings'] ? $odontogramSummary['findings'] . ' ' . ($odontogramSummary['findings'] === 1 ? 'hallazgo pendiente' : 'hallazgos pendientes') : 'Sin hallazgos pendientes' }}</div>
                        <div class="small text-muted">{{ $odontogramSummary['treatments'] }} tratamientos registrados{{ $odontogramSummary['missing'] ? ' · ' . $odontogramSummary['missing'] . ' ausentes' : '' }}</div>
                    </div>
                    <i class="bi bi-chevron-right text-muted"></i>
                </div>
            </a>
        @endif
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-person"></i> Datos personales</div>
            <div class="card-body">
                <dl class="info-list">
                    <dt>Nacimiento</dt><dd>{!! $p->birth_date ? e($p->birth_date->format('d/m/Y') . ' · ' . $p->age_label) : '<span class="text-muted">—</span>' !!}</dd>
                    <dt>Sexo</dt><dd>{!! $row(\App\Models\Patient::GENDERS[$p->gender] ?? null) !!}</dd>
                    <dt>Documento</dt><dd>{!! $row($p->document_label) !!}</dd>
                    <dt>Ocupación</dt><dd>{!! $row($p->occupation) !!}</dd>
                    @if($p->branch)<dt>Consultorio</dt><dd>{{ $p->branch->name }}</dd>@endif
                </dl>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-telephone"></i> Contacto</div>
            <div class="card-body">
                <dl class="info-list">
                    <dt>Celular</dt><dd>{!! $p->phone ? '<a href="tel:' . e($p->phone) . '">' . e($p->phone) . '</a>' : '<span class="text-muted">—</span>' !!}</dd>
                    @if($p->whatsapp)<dt>WhatsApp</dt><dd>{{ $p->whatsapp }}</dd>@endif
                    <dt>Correo</dt><dd>{!! $p->email ? '<a href="mailto:' . e($p->email) . '">' . e($p->email) . '</a>' : '<span class="text-muted">—</span>' !!}</dd>
                    <dt>Dirección</dt><dd>{!! $row($p->address) !!}</dd>
                    <dt>Emergencia</dt><dd>{!! $row(collect([$p->emergency_contact, $p->emergency_phone])->filter()->implode(' · ')) !!}</dd>
                </dl>
                @if($p->guardian_name)
                    <div class="mt-3 p-2 rounded-3" style="background:#fff7ed">
                        <div class="small fw-semibold"><i class="bi bi-person-hearts"></i> Tutor</div>
                        <div class="small">{{ $p->guardian_name }}@if($p->guardian_relationship) ({{ $p->guardian_relationship }})@endif @if($p->guardian_phone) · <a href="tel:{{ $p->guardian_phone }}">{{ $p->guardian_phone }}</a>@endif</div>
                    </div>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="bi bi-info-circle"></i> Más datos</div>
            <div class="card-body">
                <dl class="info-list">
                    <dt>Nos conoció por</dt><dd>{!! $row(\App\Models\Patient::REFERRAL_SOURCES[$p->referral_source] ?? null) !!}@if($p->referred_by) · {{ $p->referred_by }}@endif</dd>
                    <dt>Seguro</dt><dd>{!! $row($p->insurance) !!}</dd>
                    <dt>Notas</dt><dd>{!! $row($p->notes) !!}</dd>
                    <dt>Registrado</dt><dd>{{ $p->created_at?->format('d/m/Y') }}@if($p->creator) · {{ $p->creator->name }}@endif</dd>
                </dl>
            </div>
        </div>
    </div>
</div>
