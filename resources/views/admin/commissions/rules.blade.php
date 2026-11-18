@extends('layouts.app')

@section('title', 'Reglas de comisión')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $editable = $can('commissions.edit');
    $priorities = [1 => 'Persona + procedimiento', 2 => 'Persona + especialidad', 3 => 'Persona + todo', 4 => 'Equipo + procedimiento', 5 => 'Equipo + especialidad', 6 => 'Equipo + todo'];
    $active = $rules->where('active', true)->count();
@endphp
<div class="container-fluid" style="max-width: 1100px;">
    @include('admin.finance.partials.errors')

    <div class="page-header">
        <div>
            <a href="{{ route('commissions.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Comisiones</a>
            <h1 class="mt-1"><i class="bi bi-sliders"></i> Reglas de comisión</h1>
            <p class="page-subtitle">Cuánto gana cada odontólogo por lo que atiende. Sin regla, no hay comisión.</p>
        </div>
        @if($editable)
            <div class="page-actions"><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#rule-new"><i class="bi bi-plus-lg"></i> Nueva regla</button></div>
        @endif
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-body small">
                    <div class="fw-semibold mb-2"><i class="bi bi-info-circle"></i> Cómo se calcula</div>
                    <ul class="mb-0 ps-3 d-flex flex-column gap-1">
                        <li>Cada línea cobrada va al odontólogo que la <strong>atendió</strong>: el de la consulta, el que hizo el ítem del plan (o quien lo propuso) o el elegido al cobrar lo suelto.</li>
                        <li>Se aplica <strong>una sola regla: la más específica</strong> (la de prioridad 1 gana a la de 6).</li>
                        <li>La <strong>base</strong> es el total de la línea con el descuento de la venta repartido. El monto fijo se paga por procedimiento (× cantidad).</li>
                        <li>Editar o borrar una regla <strong>no cambia</strong> lo ya ganado.</li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <form method="POST" action="{{ route('commissions.rules.basis') }}" class="card h-100">
                @csrf
                <div class="card-body">
                    <div class="fw-semibold small mb-2"><i class="bi bi-clock"></i> ¿Cuándo se ganan?</div>
                    @foreach(\App\Support\Commissions::BASIS as $key => $label)
                        @php [$title, $hint] = explode(': ', $label, 2); @endphp
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="radio" name="basis" id="basis-{{ $key }}" value="{{ $key }}" @checked($basis === $key) @disabled(! $editable) onchange="this.form.submit()">
                            <label class="form-check-label small" for="basis-{{ $key }}"><strong>{{ $title }}</strong> <span class="text-muted">— {{ $hint }}</span></label>
                        </div>
                    @endforeach
                </div>
            </form>
        </div>
    </div>

    @if($active === 0)
        <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> No hay reglas activas: <strong>no se está generando ninguna comisión</strong>. Ejemplo: «Todo el equipo · Todos los procedimientos · 30 %».</div>
    @endif

    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle table-stack mb-0">
                <thead><tr><th class="ps-3">Prioridad</th><th>A quién</th><th>Sobre qué</th><th class="text-end">Paga</th><th class="pe-3"></th></tr></thead>
                <tbody>
                    @forelse($rules as $rule)
                        <tr class="{{ $rule->active ? '' : 'text-muted' }}">
                            <td class="ps-3 small text-nowrap" data-label="Prioridad"><span class="badge bg-light text-dark border">{{ $rule->priority }}</span> {{ $priorities[$rule->priority] }}</td>
                            <td class="fw-semibold" data-label="A quién">{{ $rule->who_label }}</td>
                            <td data-label="Sobre qué">{{ $rule->what_label }} @unless($rule->active)<span class="badge bg-secondary ms-1">Inactiva</span>@endunless
                                @if($rule->notes)<div class="small text-muted">{{ $rule->notes }}</div>@endif</td>
                            <td class="text-end fw-bold text-nowrap" data-label="Paga">{{ $rule->rate_label }}</td>
                            <td class="pe-3 text-end text-nowrap stack-full">
                                @if($editable)
                                    <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#rule-{{ $rule->id }}" title="Editar"><i class="bi bi-pencil"></i></button>
                                    <form method="POST" action="{{ route('commissions.rules.destroy', $rule) }}" class="d-inline" onsubmit="return confirm('¿Eliminar la regla? Las comisiones ya generadas no cambian.')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-light text-danger" type="submit" title="Eliminar"><i class="bi bi-trash"></i></button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="stack-full">
                            <div class="empty-state">
                                <div class="empty-icon"><i class="bi bi-sliders"></i></div>
                                <h5 class="mb-1">Todavía no hay reglas</h5>
                                <p class="small mb-3">Empieza con una general y agrega excepciones: «Ortodoncia: 40 %», «Dra. Rojas: 35 %».</p>
                                @if($editable)<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#rule-new"><i class="bi bi-plus-lg"></i> Nueva regla</button>@endif
                            </div>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@if($editable)
    @foreach(collect([null])->merge($rules) as $rule)
        <div class="modal fade" id="rule-{{ $rule?->id ?? 'new' }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="POST" action="{{ $rule ? route('commissions.rules.update', $rule) : route('commissions.rules.store') }}">
                    @csrf
                    @if($rule) @method('PUT') @endif
                    <div class="modal-header"><h5 class="modal-title">{{ $rule ? 'Editar regla' : 'Nueva regla' }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                    <div class="modal-body">@include('admin.commissions.partials.rule-form', ['rule' => $rule])</div>
                    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar</button></div>
                </form>
            </div>
        </div>
    @endforeach
@endif
@push('scripts')
<script>
    document.querySelectorAll('.js-rule-form').forEach((form) => {
        const sync = () => {
            const applies = form.querySelector('.js-applies:checked')?.value;
            form.querySelectorAll('.js-when').forEach((el) => { el.hidden = el.dataset.when !== applies; });
            const percent = form.querySelector('.js-type:checked')?.value === 'porcentaje';
            form.querySelector('.js-prefix').hidden = percent;
            form.querySelector('.js-suffix').hidden = ! percent;
        };
        form.addEventListener('change', sync);
    });
</script>
@endpush
@endsection
