@extends('layouts.app')

@section('title', 'Comisiones y producción')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
    $period = ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    $presets = [
        'Este mes' => [now()->startOfMonth(), now()],
        'Mes pasado' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
        'Este año' => [now()->startOfYear(), now()],
    ];
@endphp
<div class="container-fluid" style="max-width: 1200px;">
    @include('admin.finance.partials.errors')

    <div class="page-header">
        <div>
            <h1><i class="bi bi-percent"></i> Comisiones y producción</h1>
            <p class="page-subtitle">Cuánto atendió y generó cada odontólogo, cuánto se le debe y qué se le pagó.</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('commissions.rules') }}" class="btn btn-light"><i class="bi bi-sliders"></i> Reglas</a>
        </div>
    </div>

    @if($activeRules === 0)
        <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span><i class="bi bi-exclamation-triangle"></i> No hay reglas activas: <strong>no se está generando ninguna comisión</strong> al cobrar.</span>
            @if($can('commissions.edit'))<a href="{{ route('commissions.rules') }}" class="btn btn-sm btn-dark">Crear reglas</a>@endif
        </div>
    @endif

    @include('admin.commissions.partials.pending-card', ['pending' => $pending])

    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <span><i class="bi bi-graph-up"></i> Producción por profesional</span>
            <form method="GET" class="ms-auto d-flex flex-wrap align-items-center gap-2">
                @foreach($presets as $label => [$a, $b])
                    @php $active = $from->isSameDay($a) && $to->isSameDay($b); @endphp
                    <a href="{{ route('commissions.index', ['from' => $a->toDateString(), 'to' => $b->toDateString()]) }}" class="btn btn-sm {{ $active ? 'btn-primary' : 'btn-light' }}">{{ $label }}</a>
                @endforeach
                <input type="date" name="from" value="{{ $period['from'] }}" class="form-control form-control-sm w-auto" aria-label="Desde">
                <input type="date" name="to" value="{{ $period['to'] }}" class="form-control form-control-sm w-auto" aria-label="Hasta">
                <button class="btn btn-sm btn-light" type="submit"><i class="bi bi-funnel"></i></button>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table align-middle table-stack mb-0">
                <thead><tr>
                    <th class="ps-3">Profesional</th>
                    <th class="text-end" title="Valor de lo realizado en sus consultas">Atendido</th>
                    <th class="text-end" title="Sus líneas en ventas del período (con el descuento de la venta)">Vendido</th>
                    <th class="text-end">Comisiones</th>
                    <th class="text-end">Pagado</th>
                    <th class="text-end">Pendiente</th>
                    <th class="pe-3"></th>
                </tr></thead>
                <tbody>
                    @forelse($production as $row)
                        @php $p = $row['professional']; @endphp
                        <tr>
                            <td class="ps-3 stack-full">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="avatar" style="--tone: {{ $p->color }}">{{ mb_strtoupper(mb_substr($p->full_name, 0, 1)) }}</span>
                                    <div><div class="fw-semibold">{{ $p->full_name }}</div><div class="small text-muted">{{ $row['procedures'] }} {{ $row['procedures'] === 1 ? 'procedimiento' : 'procedimientos' }}</div></div>
                                </div>
                            </td>
                            <td class="text-end tabular" data-label="Atendido">{{ $money($row['done']) }}</td>
                            <td class="text-end tabular" data-label="Vendido">{{ $money($row['sold']) }}</td>
                            <td class="text-end tabular fw-semibold" data-label="Comisiones">{{ $money($row['earned']) }}</td>
                            <td class="text-end tabular text-success" data-label="Pagado">{{ $money($row['settled']) }}</td>
                            <td class="text-end tabular {{ $row['pending'] > 0 ? 'text-warning-emphasis fw-semibold' : 'text-muted' }}" data-label="Pendiente">{{ $money($row['pending']) }}</td>
                            <td class="pe-3 text-end stack-full"><a href="{{ route('commissions.professional', [$p] + $period) }}" class="btn btn-sm btn-light">Detalle <i class="bi bi-chevron-right"></i></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="stack-full">
                            <div class="empty-state">
                                <div class="empty-icon"><i class="bi bi-graph-up"></i></div>
                                <h5 class="mb-1">Sin producción en el período</h5>
                                <p class="small mb-0">Aparece lo que cada odontólogo atendió en consultas y lo que se cobró de su trabajo.</p>
                            </div>
                        </td></tr>
                    @endforelse
                </tbody>
                @if($production->count() > 1)
                    <tfoot class="fw-bold">
                        <tr>
                            <td class="ps-3">Total</td>
                            <td class="text-end tabular">{{ $money($production->sum('done')) }}</td>
                            <td class="text-end tabular">{{ $money($production->sum('sold')) }}</td>
                            <td class="text-end tabular">{{ $money($production->sum('earned')) }}</td>
                            <td class="text-end tabular">{{ $money($production->sum('settled')) }}</td>
                            <td class="text-end tabular">{{ $money($production->sum('pending')) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    <div class="card" id="liquidaciones">
        <div class="card-header d-flex align-items-center flex-wrap gap-2">
            <span><i class="bi bi-receipt"></i> Liquidaciones pagadas</span>
            <form method="GET" action="{{ route('commissions.index') }}#liquidaciones" class="ms-auto">
                @foreach($period as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                <select name="personal_id" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Profesional">
                    <option value="">Todo el equipo</option>
                    @foreach($professionals as $pro)
                        <option value="{{ $pro->id }}" @selected((string) request('personal_id') === (string) $pro->id)>{{ $pro->full_name }}</option>
                    @endforeach
                </select>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle table-stack mb-0">
                <thead><tr><th class="ps-3">Nº</th><th>Profesional</th><th>Período</th><th class="text-end">Comisiones</th><th class="text-end">Total</th><th class="pe-3">Pagó</th></tr></thead>
                <tbody>
                    @forelse($settlements as $s)
                        <tr class="{{ $s->isCancelled() ? 'text-muted' : '' }}">
                            <td class="ps-3 fw-semibold text-nowrap stack-full"><a href="{{ route('commissions.settlement', $s) }}" class="text-decoration-none">{{ $s->code }}</a> @if($s->isCancelled())<span class="badge bg-secondary">Anulada</span>@endif</td>
                            <td data-label="Profesional">{{ $s->professional?->full_name }}</td>
                            <td class="small text-nowrap" data-label="Período">{{ $s->period_start->format('d/m/Y') }} – {{ $s->period_end->format('d/m/Y') }}</td>
                            <td class="text-end" data-label="Comisiones">{{ $s->commissions_count }}</td>
                            <td class="text-end fw-semibold tabular {{ $s->isCancelled() ? 'text-decoration-line-through' : '' }}" data-label="Total">{{ $money($s->total) }}</td>
                            <td class="small text-muted pe-3" data-label="Pagó">{{ $s->paid_at?->format('d/m/Y') }} · {{ $s->payer?->name }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted small py-4 stack-full">Todavía no hay liquidaciones.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $settlements->fragment('liquidaciones')->links() }}</div>
</div>
@include('admin.finance.partials.styles')
@endsection
