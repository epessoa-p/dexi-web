{{-- Pestaña «Historia clínica»: las consultas en orden cronológico, de la más reciente a la más antigua. --}}
@if($consultations->isEmpty())
    <div class="card"><div class="empty-state">
        <div class="empty-icon"><i class="bi bi-journal-medical"></i></div>
        <h5 class="mb-1">Sin consultas todavía</h5>
        <p class="small mb-3">Cada atención (motivo, examen, diagnóstico y lo que se hizo) queda aquí, firmada por el profesional.</p>
        @if($p->active && $can('consultations.create'))
            <form method="POST" action="{{ route('consultations.start') }}">
                @csrf
                <input type="hidden" name="patient_id" value="{{ $p->id }}">
                <button class="btn btn-primary" type="submit"><i class="bi bi-clipboard2-pulse"></i> Iniciar consulta</button>
            </form>
        @endif
    </div></div>
@else
    <div class="timeline">
        @foreach($consultations as $c)
            <article class="timeline-item">
                <div class="timeline-dot {{ $c->isOpen() ? 'is-open' : '' }}"></div>
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                            <div>
                                <a href="{{ route('consultations.show', $c) }}" class="fw-bold text-decoration-none text-reset">{{ ucfirst($c->started_at?->locale('es')->translatedFormat('l j \d\e F \d\e Y')) }}</a>
                                <div class="small text-muted">{{ $c->code }} · {{ $c->professional?->full_name }}</div>
                            </div>
                            @if($c->isOpen())
                                <span class="badge bg-warning text-dark">Abierta</span>
                            @else
                                <span class="badge bg-success-subtle text-success-emphasis"><i class="bi bi-lock-fill"></i> Cerrada</span>
                            @endif
                        </div>
                        @if($c->reason)<div class="mt-2"><span class="text-muted small">Motivo:</span> {{ $c->reason }}</div>@endif
                        @if($c->diagnoses->isNotEmpty())
                            <div class="mt-2 d-flex flex-wrap gap-1">
                                @foreach($c->diagnoses as $d)
                                    <span class="badge bg-light text-dark border fw-normal">{{ $d->label }}@if($d->tooth) · {{ $d->tooth }}{{ $d->surfaces }}@endif</span>
                                @endforeach
                            </div>
                        @endif
                        @if($c->procedures->isNotEmpty())
                            <ul class="small mb-0 mt-2 ps-3">
                                @foreach($c->procedures as $item)
                                    <li>{{ $item->name }}@if($item->tooth) — pieza {{ $item->tooth }}{{ $item->surfaces ? ' (' . $item->surfaces . ')' : '' }}@endif</li>
                                @endforeach
                            </ul>
                        @endif
                        @if($c->next_visit)<div class="small mt-2"><i class="bi bi-calendar-event text-muted"></i> {{ $c->next_visit }}</div>@endif
                    </div>
                </div>
            </article>
        @endforeach
    </div>
    @push('styles')
    <style>
        .timeline { position: relative; padding-left: 1.6rem; }
        .timeline::before { content: ''; position: absolute; left: .45rem; top: .4rem; bottom: .4rem; width: 2px; background: var(--dx-border); }
        .timeline-item { position: relative; margin-bottom: .9rem; }
        .timeline-dot { position: absolute; left: -1.55rem; top: 1.1rem; width: .95rem; height: .95rem; border-radius: 50%; background: var(--brand-base); box-shadow: 0 0 0 3px var(--dx-bg); }
        .timeline-dot.is-open { background: #f59e0b; }
    </style>
    @endpush
@endif
