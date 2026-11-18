@extends('layouts.app')

@section('title', "{$plan->code} · {$plan->patient?->full_name}")

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
    $symbol = \App\Support\Money::symbol($tenantCompany);
    $p = $plan->patient;
    $canEdit = $can('treatment_plans.edit');
    $editable = $canEdit && $plan->isEditable();
    $open = $canEdit && ! $plan->isClosed();
    $phases = $plan->items->groupBy('phase');
    $nextPhase = max(1, (int) $plan->items->max('phase'));
    $grouped = $procedures->groupBy(fn ($proc) => $proc->specialty?->name ?? 'Otros');
@endphp
<div class="container-fluid">
    @include('admin.finance.partials.errors')

    <div class="page-header">
        <div class="min-w-0">
            <a href="{{ route('patients.show', [$p, 'tab' => 'tratamiento']) }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> {{ $p?->full_name }}</a>
            <h1 class="mt-1">{{ $plan->title }}</h1>
            <p class="page-subtitle">
                <span class="badge bg-{{ $plan->status_color }}"><i class="bi {{ $plan->status_icon }}"></i> {{ $plan->status_label }}</span>
                <span class="ms-1">{{ $plan->code }} · {{ $plan->professional?->full_name ?? 'Sin profesional' }}</span>
                @if($plan->approved_at)<span class="ms-1">· aprobado por <strong>{{ $plan->approved_by_name }}</strong> el {{ $plan->approved_at->format('d/m/Y') }}</span>@endif
                @if($plan->close_reason)<span class="ms-1 text-danger">· {{ $plan->close_reason }}</span>@endif
            </p>
        </div>
        <div class="page-actions">
            <a href="{{ route('treatment-plans.print', $plan) }}" target="_blank" class="btn btn-light"><i class="bi bi-printer"></i> Presupuesto</a>
            @if(in_array($plan->status, ['presentado', 'aprobado'], true) && $tenantCompany->planAllows('laboratorio') && auth()->user()->hasPermissionInCompany('lab_orders.create', $tenantCompany))
                <a href="{{ route('lab-orders.create', ['patient_id' => $plan->patient_id]) }}" class="btn btn-light"><i class="bi bi-gem"></i> Orden de laboratorio</a>
            @endif
            @if($canEdit)
                @if($plan->status === 'borrador')
                    <form method="POST" action="{{ route('treatment-plans.status', $plan) }}" class="m-0">@csrf<input type="hidden" name="status" value="presentado"><button class="btn btn-outline-primary" type="submit"><i class="bi bi-send"></i> Presentado al paciente</button></form>
                @endif
                @if(in_array($plan->status, ['borrador', 'presentado'], true))
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#approveModal"><i class="bi bi-hand-thumbs-up"></i> Aprobar</button>
                @endif
                @if(! $plan->isClosed())
                    <div class="dropdown">
                        <button class="btn btn-light" data-bs-toggle="dropdown" aria-label="Más acciones"><i class="bi bi-three-dots"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            @if($plan->status === 'presentado')
                                <li><form method="POST" action="{{ route('treatment-plans.status', $plan) }}">@csrf<input type="hidden" name="status" value="borrador"><button class="dropdown-item" type="submit"><i class="bi bi-pencil"></i> Volver a borrador</button></form></li>
                                <li><button class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#closeModal" data-close="rechazado"><i class="bi bi-hand-thumbs-down"></i> El paciente lo rechazó</button></li>
                            @endif
                            <li><button class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#closeModal" data-close="cancelado"><i class="bi bi-x-circle"></i> Cancelar plan</button></li>
                        </ul>
                    </div>
                @elseif($plan->status === 'rechazado')
                    <form method="POST" action="{{ route('treatment-plans.status', $plan) }}" class="m-0">@csrf<input type="hidden" name="status" value="borrador"><button class="btn btn-outline-primary" type="submit"><i class="bi bi-arrow-counterclockwise"></i> Reabrir como borrador</button></form>
                @endif
            @endif
        </div>
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            @forelse($phases as $phase => $items)
                <section class="card mb-3">
                    <div class="card-header d-flex align-items-center gap-2">
                        <span class="badge rounded-pill bg-primary-subtle text-primary-emphasis">Fase {{ $phase }}</span>
                        <span class="small text-muted fw-normal">{{ $items->where('status', 'realizado')->count() }} de {{ $items->where('status', '!=', 'cancelado')->count() }} realizados</span>
                        <span class="ms-auto tabular">{{ $money($items->where('status', '!=', 'cancelado')->sum(fn ($i) => $i->subtotal)) }}</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle table-stack">
                            <thead><tr><th class="ps-3" style="width:4.5rem">Pieza</th><th>Procedimiento</th><th style="width:4.5rem">Cant.</th><th class="text-end" style="width:8rem">Precio</th><th class="text-end" style="width:6.5rem">Subtotal</th><th class="pe-3 text-end" style="width:1%"></th></tr></thead>
                            <tbody>
                                @foreach($items as $item)
                                    <tr class="{{ $item->status === 'cancelado' ? 'text-decoration-line-through text-muted' : '' }}">
                                        <td class="ps-3" data-label="Pieza">
                                            @if($item->tooth)<span class="badge bg-light text-dark border tabular">{{ $item->tooth }}{{ $item->surfaces ? ' ' . $item->surfaces : '' }}</span>@else<span class="text-muted small">—</span>@endif
                                        </td>
                                        <td class="stack-full">
                                            <span class="fw-semibold">{{ $item->name }}</span>
                                            @if($item->notes)<div class="small text-muted">{{ $item->notes }}</div>@endif
                                            @if($item->status === 'realizado')
                                                <div class="small text-success"><i class="bi bi-check2-circle"></i> Realizado {{ $item->done_at?->format('d/m/Y') }}
                                                    @if($item->consultation) · <a href="{{ route('consultations.show', $item->consultation) }}" class="text-success">{{ $item->consultation->code }}</a>@endif</div>
                                            @endif
                                        </td>
                                        <td data-label="Cantidad">
                                            @if($editable)
                                                <input type="number" min="1" max="99" value="{{ $item->quantity }}" class="form-control form-control-sm" style="max-width:4rem" data-item-field="quantity" data-url="{{ route('treatment-plans.items.update', [$plan, $item]) }}" aria-label="Cantidad">
                                            @else
                                                {{ $item->quantity }}
                                            @endif
                                        </td>
                                        <td class="text-end" data-label="Precio">
                                            @if($editable)
                                                <div class="input-group input-group-sm"><span class="input-group-text">{{ $symbol }}</span>
                                                    <input type="number" step="0.5" min="0" value="{{ (float) $item->unit_price }}" class="form-control text-end tabular" data-item-field="unit_price" data-url="{{ route('treatment-plans.items.update', [$plan, $item]) }}" aria-label="Precio"></div>
                                            @else
                                                <span class="tabular">{{ $money($item->unit_price) }}</span>
                                            @endif
                                        </td>
                                        <td class="text-end fw-semibold tabular" data-label="Subtotal" data-subtotal="{{ $item->id }}">{{ $money($item->subtotal) }}</td>
                                        <td class="pe-3 text-end text-nowrap">
                                            @if($canEdit && $plan->status === 'aprobado' && $item->status !== 'cancelado')
                                                <form method="POST" action="{{ route('treatment-plans.items.toggle', [$plan, $item]) }}" class="d-inline">
                                                    @csrf<input type="hidden" name="done" value="{{ $item->status === 'realizado' ? 0 : 1 }}">
                                                    <button class="btn btn-sm {{ $item->status === 'realizado' ? 'btn-light' : 'btn-outline-success' }}" type="submit" title="{{ $item->status === 'realizado' ? 'Volver a pendiente' : 'Marcar realizado' }}">
                                                        <i class="bi {{ $item->status === 'realizado' ? 'bi-arrow-counterclockwise' : 'bi-check-lg' }}"></i>{{ $item->status === 'realizado' ? '' : ' Hecho' }}
                                                    </button>
                                                </form>
                                            @endif
                                            @if($open && ($editable || $item->status === 'pendiente'))
                                                <form method="POST" action="{{ route('treatment-plans.items.destroy', [$plan, $item]) }}" class="d-inline" onsubmit="return confirm('{{ $editable ? '¿Quitar este procedimiento del plan?' : '¿Cancelar este procedimiento? Queda la constancia en el plan.' }}')">
                                                    @csrf @method('DELETE')
                                                    <button class="btn btn-sm btn-light text-danger" title="{{ $editable ? 'Quitar' : 'Cancelar' }}"><i class="bi bi-x-lg"></i></button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @empty
                <div class="card mb-3"><div class="empty-state">
                    <div class="empty-icon"><i class="bi bi-clipboard2-plus"></i></div>
                    <h5 class="mb-1">El plan está vacío</h5>
                    <p class="small mb-0">Agrega los procedimientos con el formulario de abajo.</p>
                </div></div>
            @endforelse

            @if($open)
                <form method="POST" action="{{ route('treatment-plans.items.store', $plan) }}" class="card form-section">
                    @csrf
                    <div class="card-header"><span class="section-icon" style="--tone:#059669"><i class="bi bi-plus-lg"></i></span> Agregar procedimiento
                        @unless($editable)<span class="section-hint">El plan ya está aprobado: lo que agregues es un adicional</span>@endunless</div>
                    <div class="card-body row g-2 align-items-end">
                        <div class="col-md-6">
                            <label class="form-label small" for="add-procedure">Procedimiento</label>
                            <select name="procedure_id" id="add-procedure" class="form-select" required>
                                <option value="">Elige…</option>
                                @foreach($grouped as $specialty => $list)
                                    <optgroup label="{{ $specialty }}">
                                        @foreach($list as $proc)
                                            <option value="{{ $proc->id }}" data-price="{{ (float) $proc->price }}">{{ $proc->name }}{{ $proc->hasPrice() ? ' · ' . $money($proc->price) : '' }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small" for="add-tooth">Pieza</label>
                            @include('admin.consultations.partials.tooth-select', ['name' => 'tooth', 'id' => 'add-tooth'])
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small" for="add-phase">Fase</label>
                            <select name="phase" id="add-phase" class="form-select">
                                @foreach(range(1, max(4, $nextPhase + 1)) as $n)<option value="{{ $n }}" @selected($n === $nextPhase)>Fase {{ $n }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-md-5">@include('admin.consultations.partials.surfaces', ['prefix' => 'plan'])</div>
                        <div class="col-4 col-md-2"><input type="number" name="quantity" min="1" max="99" value="1" class="form-control" aria-label="Cantidad" title="Cantidad"></div>
                        <div class="col-8 col-md-3">
                            <div class="input-group"><span class="input-group-text">{{ $symbol }}</span><input type="number" name="unit_price" id="add-price" step="0.5" min="0" class="form-control" placeholder="Precio" aria-label="Precio"></div>
                        </div>
                        <div class="col-md-2"><button class="btn btn-success w-100" type="submit">Agregar</button></div>
                    </div>
                </form>
            @endif
        </div>

        <div class="col-xl-4">
            <div class="card mb-3">
                <div class="card-header">Resumen</div>
                <div class="card-body">
                    @if(in_array($plan->status, ['aprobado', 'terminado'], true))
                        <div class="d-flex justify-content-between small mb-1"><span>Avance</span><span class="fw-semibold">{{ $totals['items_done'] }} de {{ $totals['items'] }} · {{ $totals['progress'] }} %</span></div>
                        <div class="progress mb-3" style="height: 9px" role="progressbar" aria-valuenow="{{ $totals['progress'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="Avance del plan">
                            <div class="progress-bar bg-success" style="width: {{ $totals['progress'] }}%"></div>
                        </div>
                    @endif
                    <dl class="info-list mb-0" id="plan-totals">
                        <dt>Subtotal</dt><dd class="text-end tabular" data-total="subtotal">{{ $money($totals['subtotal']) }}</dd>
                        <dt>Descuento</dt><dd class="text-end tabular" data-total="discount">− {{ $money($totals['discount']) }}</dd>
                        <dt class="fw-bold text-dark">Total</dt><dd class="text-end tabular fs-5 fw-bold" data-total="total">{{ $money($totals['total']) }}</dd>
                        @if(in_array($plan->status, ['aprobado', 'terminado'], true))
                            <dt>Realizado</dt><dd class="text-end tabular text-success">{{ $money($totals['done']) }}</dd>
                            <dt>Por realizar</dt><dd class="text-end tabular">{{ $money($totals['pending']) }}</dd>
                        @endif
                    </dl>
                    @if($tenantCompany->planAllows('ventas') && in_array($plan->status, ['aprobado', 'terminado'], true))
                        @php $billed = \App\Support\Sales::billedForPlan($plan->id); @endphp
                        <div class="border-top mt-3 pt-3">
                            <dl class="info-list mb-2">
                                <dt>Cobrado</dt><dd class="text-end tabular text-success">{{ $money($billed) }}</dd>
                                <dt>Falta cobrar</dt><dd class="text-end tabular fw-semibold">{{ $money(max(0, $totals['total'] - $billed)) }}</dd>
                            </dl>
                            @if($can('sales.create') && $plan->items->where('status', '!=', 'cancelado')->whereNull('sale_item_id')->isNotEmpty())
                                <a href="{{ route('sales.create', ['patient_id' => $plan->patient_id, 'plan_id' => $plan->id]) }}" class="btn btn-primary w-100"><i class="bi bi-cash-coin"></i> Cobrar del plan</a>
                                <div class="form-text">Puedes cobrar todo por adelantado o solo lo realizado. El descuento del plan se aplica al cobrar.</div>
                            @endif
                        </div>
                    @elseif(! $tenantCompany->planAllows('ventas'))
                        <p class="small text-muted mt-3 mb-0"><i class="bi bi-info-circle"></i> Para cobrar el plan, tu suscripción necesita el módulo de Ventas.</p>
                    @endif
                </div>
            </div>

            <form method="POST" action="{{ route('treatment-plans.update', $plan) }}" class="card">
                @csrf @method('PUT')
                <div class="card-header">Datos del plan</div>
                <fieldset class="card-body row g-3" @disabled(! $open)>
                    <div class="col-12">
                        <label class="form-label" for="title">Título</label>
                        <input type="text" id="title" name="title" class="form-control" maxlength="150" value="{{ old('title', $plan->title) }}" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="discount">Descuento</label>
                        <div class="input-group"><span class="input-group-text">{{ $symbol }}</span>
                            <input type="number" id="discount" name="discount" step="0.5" min="0" class="form-control" value="{{ old('discount', (float) $plan->discount) }}" @disabled(! $editable)></div>
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="valid_until">Válido hasta</label>
                        <input type="date" id="valid_until" name="valid_until" class="form-control" value="{{ old('valid_until', $plan->valid_until?->toDateString()) }}">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="notes">Notas para el paciente</label>
                        <textarea id="notes" name="notes" rows="3" class="form-control" maxlength="3000" placeholder="Forma de pago, garantía, cuidados…">{{ old('notes', $plan->notes) }}</textarea>
                    </div>
                    @if($open)<div class="col-12 text-end"><button class="btn btn-outline-primary" type="submit">Guardar</button></div>@endif
                </fieldset>
            </form>
        </div>
    </div>
</div>

@if($canEdit && in_array($plan->status, ['borrador', 'presentado'], true))
    <div class="modal fade" id="approveModal" tabindex="-1" aria-hidden="true" @if($errors->has('approved_by_name')) data-reopen @endif>
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="POST" action="{{ route('treatment-plans.status', $plan) }}">
                @csrf
                <input type="hidden" name="status" value="aprobado">
                <div class="modal-header"><h5 class="modal-title">Aprobar el plan</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                <div class="modal-body">
                    <p class="small text-muted">Total: <strong>{{ $money($totals['total']) }}</strong>. Al aprobarlo, los precios quedan fijos y cada procedimiento se marca realizado cuando se registra en una consulta.</p>
                    <label class="form-label" for="approved_by_name">¿Quién lo aprueba?</label>
                    <input type="text" id="approved_by_name" name="approved_by_name" class="form-control" maxlength="150" value="{{ old('approved_by_name', $p?->isMinor() ? $p->guardian_name : $p?->full_name) }}" required>
                    <div class="form-text">{{ $p?->isMinor() ? 'Es menor de edad: aprueba su tutor.' : 'El paciente (o quien sea responsable del pago).' }}</div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-success" type="submit"><i class="bi bi-hand-thumbs-up"></i> Aprobar</button></div>
            </form>
        </div>
    </div>
@endif
@if($canEdit && ! $plan->isClosed())
    <div class="modal fade" id="closeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="POST" action="{{ route('treatment-plans.status', $plan) }}">
                @csrf
                <input type="hidden" name="status" value="cancelado" id="close-status">
                <div class="modal-header"><h5 class="modal-title" id="close-title">Cancelar el plan</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                <div class="modal-body">
                    <label class="form-label" for="close_reason">Motivo</label>
                    <input type="text" id="close_reason" name="close_reason" class="form-control" maxlength="255" placeholder="Costo, se atiende en otro lugar, cambió el plan…" required>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Volver</button><button class="btn btn-danger" type="submit">Confirmar</button></div>
            </form>
        </div>
    </div>
@endif

@include('admin.finance.partials.styles')

<script>
(function () {
    const csrf = {!! json_encode(csrf_token()) !!};
    const symbol = {!! json_encode($symbol) !!};
    const fmt = (v) => `${symbol} ${Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

    // Edición rápida de precio y cantidad (borrador / por aprobar).
    document.querySelectorAll('[data-item-field]').forEach((input) => {
        let saved = input.value;
        const save = async () => {
            if (input.value === saved || input.value === '') return;
            const body = new URLSearchParams({ _method: 'PATCH', [input.dataset.itemField]: input.value });
            const response = await fetch(input.dataset.url, { method: 'POST', body, headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' } });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) { window.dexiToast?.(Object.values(data.errors || {})[0]?.[0] || 'No se pudo guardar.', 'error'); input.value = saved; return; }
            saved = input.value;
            const id = input.dataset.url.split('/').pop();
            document.querySelector(`[data-subtotal="${id}"]`).textContent = fmt(data.subtotal);
            document.querySelector('[data-total="subtotal"]').textContent = fmt(data.totals.subtotal);
            document.querySelector('[data-total="discount"]').textContent = '− ' + fmt(data.totals.discount);
            document.querySelector('[data-total="total"]').textContent = fmt(data.totals.total);
        };
        input.addEventListener('change', save);
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); save(); } });
    });

    // Precio del tarifario al elegir procedimiento.
    document.getElementById('add-procedure')?.addEventListener('change', (e) => {
        document.getElementById('add-price').value = e.target.selectedOptions[0]?.dataset.price || '';
    });

    // Rechazar o cancelar: el mismo modal.
    document.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => {
        document.getElementById('close-status').value = b.dataset.close;
        document.getElementById('close-title').textContent = b.dataset.close === 'rechazado' ? 'El paciente rechazó el plan' : 'Cancelar el plan';
    }));
})();
</script>
@endsection
