@extends('layouts.app')

@section('title', 'Laboratorio')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
@endphp
<div class="container-fluid">
    @include('admin.finance.partials.errors')

    <div class="page-header">
        <div>
            <h1><i class="bi bi-gem"></i> Laboratorio</h1>
            <p class="page-subtitle">Coronas, prótesis y férulas: qué está en el laboratorio, qué llegó y qué falta instalar o pagar.</p>
        </div>
        @if($can('lab_orders.create'))
            <div class="page-actions"><a href="{{ route('lab-orders.create') }}" class="btn btn-primary"><i class="bi bi-send"></i> Nueva orden</a></div>
        @endif
    </div>

    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <nav class="filter-chips" aria-label="Filtros">
            @foreach(\App\Http\Controllers\Admin\LabOrderController::FILTERS as $key => [$label, $icon])
                <a href="{{ route('lab-orders.index', array_filter(['filter' => $key, 'lab' => request('lab'), 'q' => request('q')])) }}" class="filter-chip {{ $filter === $key ? 'active' : '' }}">
                    <i class="bi {{ $icon }}"></i> {{ $label }} <span class="count {{ $key === 'atrasados' && $counts[$key] ? 'bg-danger text-white' : '' }}">{{ $counts[$key] }}</span>
                </a>
            @endforeach
        </nav>
        <form method="GET" class="ms-auto d-flex gap-2">
            <input type="hidden" name="filter" value="{{ $filter }}">
            @if($labs->count() > 1)
                <select name="lab" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Laboratorio">
                    <option value="">Todos los laboratorios</option>
                    @foreach($labs as $lab)<option value="{{ $lab->id }}" @selected((string) request('lab') === (string) $lab->id)>{{ $lab->name }}</option>@endforeach
                </select>
            @endif
            <input type="search" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Buscar paciente…" aria-label="Buscar paciente">
        </form>
    </div>

    @if($filter === 'pagar')
        @forelse($unpaidByLab as $labId => $list)
            @php $lab = $list->first()->lab; @endphp
            <form method="POST" action="{{ route('lab-orders.pay', $lab) }}" class="card mb-3 js-lab-pay">
                @csrf
                <div class="card-header d-flex align-items-center gap-2 flex-wrap">
                    <span class="fw-semibold"><i class="bi bi-building"></i> {{ $lab->name }}</span>
                    <span class="ms-auto small text-muted">Se debe</span>
                    <span class="fw-bold tabular">{{ $money($list->sum('cost')) }}</span>
                </div>
                <ul class="list-group list-group-flush">
                    @foreach($list as $o)
                        <li class="list-group-item d-flex align-items-center gap-2">
                            <input class="form-check-input mt-0 js-pick" type="checkbox" name="orders[]" value="{{ $o->id }}" data-cost="{{ (float) $o->cost }}" id="pay-{{ $o->id }}" checked @disabled(! $can('lab_orders.edit'))>
                            <label for="pay-{{ $o->id }}" class="flex-fill">
                                <span class="fw-semibold">{{ $o->code }}</span> · {{ $o->summary }}
                                <div class="small text-muted">{{ $o->patient?->full_name }} · {{ $o->status_label }}</div>
                            </label>
                            <span class="tabular">{{ $money($o->cost) }}</span>
                        </li>
                    @endforeach
                </ul>
                @if($can('lab_orders.edit') && $source && $source['sources'])
                    <details class="card-body border-top">
                        <summary class="fw-semibold text-success"><i class="bi bi-cash-coin"></i> Pagar lo marcado · <span class="js-sum tabular">{{ $money($list->sum('cost')) }}</span></summary>
                        <div class="mt-3">
                            <input type="text" name="reference" class="form-control form-control-sm mb-2" maxlength="80" placeholder="Referencia / factura del laboratorio (opcional)" aria-label="Referencia">
                            @include('admin.finance.partials.source-picker', ['source' => $source, 'id' => 'lab-' . $lab->id])
                            <button class="btn btn-success mt-3" type="submit"><i class="bi bi-check2-circle"></i> Registrar pago</button>
                            <div class="form-text">Queda como un gasto de proveedor (categoría LABORATORIO DENTAL).</div>
                        </div>
                    </details>
                @endif
            </form>
        @empty
            <div class="card"><div class="empty-state"><div class="empty-icon"><i class="bi bi-check2-circle"></i></div><h5 class="mb-1">No se le debe nada a ningún laboratorio</h5><p class="small mb-0">Aquí aparecen los trabajos con costo que todavía no se pagaron.</p></div></div>
        @endforelse
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle table-stack mb-0">
                    <thead><tr><th class="ps-3">Orden</th><th>Paciente</th><th>Laboratorio</th><th>Entrega</th><th>Estado</th><th class="text-end pe-3">Costo</th></tr></thead>
                    <tbody>
                        @forelse($orders as $o)
                            <tr class="{{ $o->status === 'cancelado' ? 'text-muted' : '' }}">
                                <td class="ps-3 stack-full">
                                    <a href="{{ route('lab-orders.show', $o) }}" class="fw-semibold text-decoration-none">{{ $o->code }}</a>
                                    <div class="small">{{ $o->summary }}</div>
                                </td>
                                <td data-label="Paciente">{{ $o->patient?->full_name }}@if($o->professional)<div class="small text-muted">{{ $o->professional->full_name }}</div>@endif</td>
                                <td class="small" data-label="Laboratorio">{{ $o->lab?->name }}</td>
                                <td data-label="Entrega" class="{{ $o->isLate() ? 'text-danger fw-semibold' : '' }}">
                                    {{ $o->due_at?->format('d/m/Y') ?? '—' }}
                                    @if($o->isLate())<div class="small text-nowrap">{{ (int) $o->due_at->diffInDays(today()) }} {{ (int) $o->due_at->diffInDays(today()) === 1 ? "día" : "días" }} de atraso</div>@elseif($o->isAtLab() && $o->due_at?->isToday())<div class="small text-primary">llega hoy</div>@endif
                                </td>
                                <td data-label="Estado">
                                    <span class="badge bg-{{ $o->status_color }}{{ $o->status_color === 'warning' ? ' text-dark' : '' }}"><i class="bi {{ \App\Models\LabOrder::STATUSES[$o->status][2] }}"></i> {{ $o->status_label }}</span>
                                    @if($o->returns_count)<span class="badge bg-light text-dark border" title="Devuelto para ajuste">↺ {{ $o->returns_count }}</span>@endif
                                </td>
                                <td class="text-end pe-3 tabular text-nowrap" data-label="Costo">
                                    {{ (float) $o->cost ? $money($o->cost) : '—' }}
                                    @if((float) $o->cost && $o->status !== 'cancelado')<div class="small {{ $o->isPaid() ? 'text-success' : 'text-warning-emphasis' }}">{{ $o->isPaid() ? 'pagado' : 'por pagar' }}</div>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="stack-full">
                                <div class="empty-state">
                                    <div class="empty-icon"><i class="bi bi-gem"></i></div>
                                    <h5 class="mb-1">Sin trabajos aquí</h5>
                                    <p class="small mb-3">Cada corona, prótesis o férula que mandas al laboratorio: con pieza, color y fecha de entrega, para saber siempre dónde está.</p>
                                    @if($can('lab_orders.create'))<a href="{{ route('lab-orders.create') }}" class="btn btn-primary"><i class="bi bi-send"></i> Nueva orden</a>@endif
                                </div>
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-3">{{ $orders->links() }}</div>
    @endif
</div>
@include('admin.finance.partials.styles')
@push('scripts')
<script>
    document.querySelectorAll('.js-lab-pay').forEach((form) => {
        const sum = form.querySelector('.js-sum');
        const symbol = @json(\App\Support\Money::symbol($tenantCompany));
        form.addEventListener('change', () => {
            const total = [...form.querySelectorAll('.js-pick:checked')].reduce((s, el) => s + parseFloat(el.dataset.cost), 0);
            if (sum) sum.textContent = symbol + ' ' + total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        });
    });
</script>
@endpush
@endsection
