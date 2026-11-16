{{-- Pestaña «Citas» de la ficha: próximas arriba, después el historial. --}}
@php
    $upcoming = $appointments->filter(fn ($a) => $a->starts_at->gte(now()->startOfDay()) && ! $a->isClosed())->sortBy('starts_at');
    $past = $appointments->reject(fn ($a) => $upcoming->contains($a));
    $missed = $past->where('status', 'no_asistio')->count();
@endphp
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-calendar-check"></i> Próximas</span>
                @if($p->active && $can('appointments.create'))<a href="{{ route('appointments.create', ['patient_id' => $p->id]) }}" class="small text-decoration-none">+ Agendar</a>@endif
            </div>
            <ul class="list-group list-group-flush">
                @forelse($upcoming as $a)
                    <li class="list-group-item d-flex gap-3 align-items-center">
                        <div class="text-center" style="min-width:3.2rem">
                            <div class="small text-uppercase text-muted">{{ $a->starts_at->locale('es')->translatedFormat('M') }}</div>
                            <div class="fs-4 fw-bold lh-1">{{ $a->starts_at->format('d') }}</div>
                        </div>
                        <a href="{{ route('appointments.show', $a) }}" class="flex-fill text-decoration-none text-reset min-w-0">
                            <div class="fw-semibold">{{ $a->starts_at->locale('es')->translatedFormat('l') }} {{ $a->starts_at->format('H:i') }} · {{ $a->title }}</div>
                            <div class="small text-muted">{{ $a->professional?->full_name }}</div>
                        </a>
                        <span class="badge bg-{{ $a->status_color }}">{{ $a->status_label }}</span>
                    </li>
                @empty
                    <li class="list-group-item small text-muted">No tiene citas próximas.</li>
                @endforelse
            </ul>
        </div>
        <div class="card">
            <div class="card-body d-flex justify-content-around text-center">
                <div><div class="fs-4 fw-bold">{{ $past->where('status', 'atendida')->count() }}</div><div class="small text-muted">Atendidas</div></div>
                <div><div class="fs-4 fw-bold {{ $missed ? 'text-danger' : '' }}">{{ $missed }}</div><div class="small text-muted">No asistió</div></div>
                <div><div class="fs-4 fw-bold">{{ $past->where('status', 'cancelada')->count() }}</div><div class="small text-muted">Canceladas</div></div>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><i class="bi bi-clock-history"></i> Historial</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle table-stack">
                    <thead><tr><th class="ps-3">Fecha</th><th>Procedimiento</th><th>Profesional</th><th class="pe-3">Estado</th></tr></thead>
                    <tbody>
                        @forelse($past as $a)
                            <tr class="row-link" onclick="location.href='{{ route('appointments.show', $a) }}'">
                                <td class="ps-3 small text-nowrap" data-label="Fecha">{{ $a->starts_at->format('d/m/Y H:i') }}</td>
                                <td class="small" data-label="Procedimiento">{{ $a->title }}</td>
                                <td class="small" data-label="Profesional">{{ $a->professional?->full_name }}</td>
                                <td class="pe-3" data-label="Estado"><span class="badge bg-{{ $a->status_color }}">{{ $a->status_label }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="stack-full small text-muted ps-3">Sin citas anteriores.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
