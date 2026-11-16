@extends('layouts.app')

@section('title', 'Gastos')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
    $tabs = \App\Http\Controllers\Admin\ExpenseController::TABS;
    $createLabels = \App\Http\Controllers\Admin\ExpenseController::CREATE_LABELS;
    $period = ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    $createGroup = $tab === 'todos' ? 'general' : $tab;
@endphp
<div class="container-fluid">
    @include('admin.finance.partials.errors')
    <div class="page-header">
        <div>
            <h1 class="mb-1"><i class="bi bi-wallet2"></i> Gastos</h1>
            <p class="text-muted mb-0">Lo que se paga, a quién y de dónde salió el dinero.</p>
        </div>
        @if($can('expenses.create'))
            <a href="{{ route('expenses.create', ['group' => $createGroup]) }}" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> {{ $createLabels[$createGroup] }}
            </a>
        @endif
    </div>

    <ul class="nav nav-pills expense-tabs gap-1 mb-3 flex-nowrap overflow-auto">
        @foreach($tabs as $key => [$label, $icon])
            <li class="nav-item">
                <a class="nav-link text-nowrap {{ $tab === $key ? 'active' : '' }}" href="{{ route('expenses.index', ['tab' => $key] + $period) }}">
                    <i class="bi {{ $icon }}"></i> {{ $label }}
                </a>
            </li>
        @endforeach
    </ul>

    @if($basics)
        @php $prev = $basics['month']->subMonth()->format('Y-m'); $next = $basics['month']->addMonth()->format('Y-m'); @endphp
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <a class="btn btn-sm btn-light border" href="{{ route('expenses.index', ['tab' => 'basico', 'month' => $prev] + $period) }}" title="Mes anterior"><i class="bi bi-chevron-left"></i></a>
                        <div>
                            <div class="fw-bold">{{ $basics['label'] }}</div>
                            <div class="small text-muted">
                                <span class="text-success">{{ $basics['paid'] }} {{ $basics['paid'] === 1 ? 'pagado' : 'pagados' }}</span> ·
                                <span class="{{ $basics['pending'] ? 'text-warning-emphasis' : '' }}">{{ $basics['pending'] }} {{ $basics['pending'] === 1 ? 'pendiente' : 'pendientes' }}</span> ·
                                {{ $money($basics['total']) }}
                            </div>
                        </div>
                        @if($basics['month']->lt(now()->startOfMonth()))
                            <a class="btn btn-sm btn-light border" href="{{ route('expenses.index', ['tab' => 'basico', 'month' => $next] + $period) }}" title="Mes siguiente"><i class="bi bi-chevron-right"></i></a>
                        @endif
                    </div>
                    @if($can('expense_categories.create'))
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#newService"><i class="bi bi-plus-lg"></i> Nuevo servicio</button>
                    @endif
                </div>
                @forelse($basics['items'] as $item)
                    @php $c = $item['category']; $paid = $item['status'] === 'pagado'; @endphp
                    <div class="basics-row">
                        <div class="basics-status {{ $paid ? 'paid' : 'pending' }}"><i class="bi {{ $paid ? 'bi-check-lg' : 'bi-clock' }}"></i></div>
                        <div class="flex-fill min-w-0">
                            <div class="fw-semibold">
                                {{ $c->name }}
                                @if($c->frequency === 'anual')<span class="badge bg-light text-dark border ms-1">Anual</span>@endif
                            </div>
                            <div class="small {{ $paid ? 'text-success' : 'text-warning-emphasis' }}">
                                @if($paid)
                                    Pagado{{ $item['paid_amount'] > 0 ? ' ' . $money($item['paid_amount']) : '' }}
                                    @if($item['next_due']) · vuelve el {{ $item['next_due']->format('d/m/Y') }}@endif
                                @else
                                    Pendiente @if($item['last_amount'] !== null)· último {{ $money($item['last_amount']) }}@endif
                                @endif
                            </div>
                        </div>
                        @if(! $paid && $can('expenses.create'))
                            <a class="btn btn-sm btn-warning" href="{{ route('expenses.create', ['group' => 'basico', 'category_id' => $c->id, 'amount' => $item['last_amount']]) }}">
                                <i class="bi bi-plus-lg"></i> Registrar
                            </a>
                        @endif
                    </div>
                @empty
                    <p class="text-muted small mb-0">No hay servicios básicos. Agrega luz, agua, alquiler… para controlar sus pagos de cada mes.</p>
                @endforelse
            </div>
        </div>
    @endif

    <form method="GET" class="card border-0 shadow-sm mb-3">
        <input type="hidden" name="tab" value="{{ $tab }}">
        @if(request('month'))<input type="hidden" name="month" value="{{ request('month') }}">@endif
        <div class="card-body row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">Desde</label>
                <input type="date" name="from" value="{{ $from->format('Y-m-d') }}" class="form-control">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">Hasta</label>
                <input type="date" name="to" value="{{ $to->format('Y-m-d') }}" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">{{ $tab === 'basico' ? 'Servicio' : 'Categoría' }}</label>
                <select name="category_id" class="form-select">
                    <option value="">Todas</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) request('category_id') === (string) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Salió de</label>
                <select name="source" class="form-select">
                    <option value="">Todos</option>
                    @foreach($sources as $key => $label)
                        <option value="{{ $key }}" @selected(request('source') === $key)>{{ $key === 'account' ? 'Cuentas' : $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-outline-primary flex-fill" type="submit"><i class="bi bi-funnel"></i> Filtrar</button>
                <a href="{{ route('expenses.index', ['tab' => $tab]) }}" class="btn btn-light border" title="Mes actual"><i class="bi bi-x-lg"></i></a>
            </div>
        </div>
    </form>

    <div class="d-flex align-items-baseline justify-content-between flex-wrap gap-2 mb-2">
        <div class="text-muted small">{{ $from->format('d/m/Y') }} – {{ $to->format('d/m/Y') }} · {{ $count }} {{ $count === 1 ? 'gasto' : 'gastos' }}</div>
        <div class="fs-5">Total gastado: <strong>{{ $money($total) }}</strong></div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr><th class="ps-3">Fecha</th><th>Descripción</th><th>Categoría</th>@if($tab !== 'general' && $tab !== 'basico')<th>A quién</th>@endif<th>Salió de</th><th class="text-end pe-3">Importe</th></tr>
                    </thead>
                    <tbody>
                        @forelse($expenses as $expense)
                            <tr class="{{ $expense->isCancelled() ? 'movement-cancelled' : '' }}" role="button" onclick="location.href='{{ route('expenses.show', $expense) }}'">
                                <td class="ps-3 small text-nowrap">{{ $expense->spent_at->format('d/m/Y') }}<div class="text-muted">{{ $expense->code }}</div></td>
                                <td>
                                    {{ $expense->concept }}
                                    @if($expense->receipt_url)<i class="bi bi-paperclip text-muted" title="Con comprobante"></i>@endif
                                    @if($expense->isCancelled())<span class="badge bg-secondary ms-1 no-strike">Anulado</span>@endif
                                </td>
                                <td class="small">{{ $expense->category?->name ?? '—' }}</td>
                                @if($tab !== 'general' && $tab !== 'basico')<td class="small">{{ $expense->payee_name ?? '—' }}</td>@endif
                                <td class="small text-muted">{{ $expense->source_label }}</td>
                                <td class="text-end fw-semibold pe-3 text-nowrap">{{ $money($expense->amount) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="stack-full"><div class="empty-state py-4"><div class="empty-icon"><i class="bi bi-wallet2"></i></div><h5 class="mb-1">Sin gastos en el período</h5><p class="mb-0 small">Cambia las fechas o registra el primero con el botón de arriba.</p></div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="mt-3 d-flex justify-content-center">{{ $expenses->links() }}</div>
</div>

@if($basics && $can('expense_categories.create'))
    <div class="modal fade" id="newService" tabindex="-1" aria-hidden="true" @if($errors->any() && old('return') === 'basics') data-reopen @endif>
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="POST" action="{{ route('expense-categories.store') }}">
                @csrf
                <input type="hidden" name="type" value="basico">
                <input type="hidden" name="return" value="basics">
                <div class="modal-header"><h5 class="modal-title">Nuevo servicio básico</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <label class="form-label">Nombre <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control mb-3" maxlength="80" placeholder="Luz, agua, hosting, seguro…" value="{{ old('return') === 'basics' ? old('name') : '' }}" required>
                    @if(old('return') === 'basics') @error('name')<div class="text-danger small mb-2">{{ $message }}</div>@enderror @endif
                    <label class="form-label">Se paga</label>
                    <div class="btn-group w-100" role="group">
                        <input type="radio" class="btn-check" name="frequency" id="freq-m" value="mensual" checked>
                        <label class="btn btn-outline-primary" for="freq-m">Mensual</label>
                        <input type="radio" class="btn-check" name="frequency" id="freq-a" value="anual">
                        <label class="btn btn-outline-primary" for="freq-a">Anual</label>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Agregar</button></div>
            </form>
        </div>
    </div>
@endif
@include('admin.finance.partials.styles')
@endsection
