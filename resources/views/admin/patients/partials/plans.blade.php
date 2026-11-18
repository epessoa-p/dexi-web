{{-- Pestaña «Plan de tratamiento» de la ficha: los planes del paciente con su avance. --}}
@php $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany); @endphp
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="small text-muted">{{ $plans->count() }} {{ $plans->count() === 1 ? 'plan' : 'planes' }}</div>
    @if($p->active && $can('treatment_plans.create'))
        <a href="{{ route('treatment-plans.create', ['patient_id' => $p->id]) }}" class="btn btn-primary"><i class="bi bi-clipboard2-plus"></i> Nuevo plan</a>
    @endif
</div>

@forelse($plans as $plan)
    @php $t = $plan->totals(); @endphp
    <a href="{{ route('treatment-plans.show', $plan) }}" class="card mb-3 text-decoration-none text-reset">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                <div>
                    <div class="fw-bold">{{ $plan->title }}</div>
                    <div class="small text-muted">{{ $plan->code }} · {{ $plan->created_at->format('d/m/Y') }} · {{ $plan->professional?->full_name }}</div>
                </div>
                <div class="text-end">
                    <span class="badge bg-{{ $plan->status_color }}"><i class="bi {{ $plan->status_icon }}"></i> {{ $plan->status_label }}</span>
                    <div class="fw-bold tabular mt-1">{{ $money($t['total']) }}</div>
                </div>
            </div>
            @if(in_array($plan->status, ['aprobado', 'terminado'], true))
                <div class="d-flex align-items-center gap-2 mt-2">
                    <div class="progress flex-fill" style="height: 7px" role="progressbar" aria-valuenow="{{ $t['progress'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="Avance">
                        <div class="progress-bar bg-success" style="width: {{ $t['progress'] }}%"></div>
                    </div>
                    <span class="small text-muted">{{ $t['items_done'] }} de {{ $t['items'] }}</span>
                </div>
            @endif
            <div class="d-flex flex-wrap gap-1 mt-2">
                @foreach($plan->items->where('status', '!=', 'cancelado')->take(8) as $item)
                    <span class="badge fw-normal {{ $item->status === 'realizado' ? 'bg-success-subtle text-success-emphasis' : 'bg-light text-dark border' }}">
                        @if($item->status === 'realizado')<i class="bi bi-check2"></i>@endif
                        {{ $item->tooth ? $item->tooth . ' · ' : '' }}{{ $item->name }}
                    </span>
                @endforeach
                @if($plan->items->count() > 8)<span class="small text-muted">+{{ $plan->items->count() - 8 }}</span>@endif
            </div>
        </div>
    </a>
@empty
    <div class="card"><div class="empty-state">
        <div class="empty-icon"><i class="bi bi-clipboard2-check"></i></div>
        <h5 class="mb-1">Sin planes de tratamiento</h5>
        <p class="small mb-3">Arma un presupuesto a partir de los hallazgos del odontograma: el paciente lo aprueba y avanza solo con cada consulta.</p>
        @if($p->active && $can('treatment_plans.create'))
            <a href="{{ route('treatment-plans.create', ['patient_id' => $p->id]) }}" class="btn btn-primary"><i class="bi bi-clipboard2-plus"></i> Nuevo plan</a>
        @endif
    </div></div>
@endforelse

@if($labOrders !== null)
    <div class="card mt-3">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-gem"></i> Trabajos de laboratorio
            @if($p->active && $can('lab_orders.create'))<a href="{{ route('lab-orders.create', ['patient_id' => $p->id]) }}" class="btn btn-sm btn-light ms-auto"><i class="bi bi-send"></i> Nueva orden</a>@endif
        </div>
        <ul class="list-group list-group-flush">
            @forelse($labOrders as $lo)
                <a href="{{ route('lab-orders.show', $lo) }}" class="list-group-item list-group-item-action d-flex align-items-center gap-2 flex-wrap">
                    <span class="fw-semibold">{{ $lo->code }}</span>
                    <span class="flex-fill">{{ $lo->summary }} <span class="small text-muted">· {{ $lo->lab?->name }}</span></span>
                    @if($lo->isLate())<span class="badge bg-danger">Atrasado</span>@endif
                    <span class="badge bg-{{ $lo->status_color }}{{ $lo->status_color === 'warning' ? ' text-dark' : '' }}">{{ $lo->status_label }}</span>
                </a>
            @empty
                <li class="list-group-item small text-muted">Sin trabajos de laboratorio.</li>
            @endforelse
        </ul>
    </div>
@endif
