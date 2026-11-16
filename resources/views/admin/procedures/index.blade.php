@extends('layouts.app')

@section('title', 'Procedimientos y tarifario')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $money = fn ($v) => \App\Support\Money::format($v, $tenantCompany);
    $symbol = \App\Support\Money::symbol($tenantCompany);
    $canEdit = $can('procedures.edit');
    $suggestedCount = collect(\App\Models\Procedure::SUGGESTED)->flatten(1)->count();
    // Si el modal falló al guardar, vuelve a abrirse con lo escrito.
    $reopen = $errors->any() && old('_modal') === 'procedure';
    $editingId = $reopen ? old('_procedure_id') : null;
@endphp
<div class="container-fluid">
    @include('admin.finance.partials.errors')

    <div class="page-header">
        <div>
            <h1><i class="bi bi-tags"></i> Procedimientos y tarifario</h1>
            <p class="page-subtitle">Un solo catálogo para la agenda, el odontograma, los presupuestos y las ventas.</p>
        </div>
        @if($total)
            <div class="page-actions">
                @if($canEdit)
                    <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#adjustModal"><i class="bi bi-percent"></i> Ajustar precios</button>
                @endif
                @if($can('procedures.create'))
                    <button class="btn btn-primary" data-procedure-open><i class="bi bi-plus-lg"></i> Nuevo procedimiento</button>
                @endif
            </div>
        @endif
    </div>

    @if(! $total)
        {{-- Primera vez: catálogo sugerido o desde cero --}}
        <div class="card">
            <div class="empty-state py-5">
                <div class="empty-icon"><i class="bi bi-tags"></i></div>
                <h5 class="mb-1">Arma tu tarifario</h5>
                <p class="small mb-4 mx-auto" style="max-width: 520px">Empieza con el catálogo sugerido: {{ $suggestedCount }} procedimientos dentales comunes, ordenados por especialidad, con su duración. Solo tendrás que ponerles tu precio.</p>
                @if($can('procedures.create'))
                    <div class="d-flex flex-wrap gap-2 justify-content-center">
                        <form method="POST" action="{{ route('procedures.suggested') }}">
                            @csrf
                            <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-magic"></i> Cargar catálogo sugerido</button>
                        </form>
                        <button class="btn btn-light btn-lg" data-procedure-open><i class="bi bi-plus-lg"></i> Crear desde cero</button>
                    </div>
                @endif
            </div>
        </div>
    @else
        <form method="GET" class="mb-3" role="search">
            @if($filter)<input type="hidden" name="filter" value="{{ $filter }}">@endif
            <div class="search-hero">
                <i class="bi bi-search"></i>
                <input type="search" name="q" value="{{ request('q') }}" class="form-control" placeholder="Buscar por nombre o código…" aria-label="Buscar procedimiento" autocomplete="off">
            </div>
        </form>

        <div class="d-flex align-items-center justify-content-between gap-2 mb-3 flex-wrap">
            <nav class="filter-chips" aria-label="Filtros">
                <a href="{{ route('procedures.index', array_filter(['q' => request('q')])) }}" class="filter-chip {{ ! $filter ? 'active' : '' }}"><i class="bi bi-list-ul"></i> Activos <span class="count">{{ $counts['all'] }}</span></a>
                @if($counts['no_price'])
                    <a href="{{ route('procedures.index', array_filter(['filter' => 'no_price', 'q' => request('q')])) }}" class="filter-chip {{ $filter === 'no_price' ? 'active' : '' }}"><i class="bi bi-exclamation-circle"></i> Sin precio <span class="count">{{ $counts['no_price'] }}</span></a>
                @endif
                <a href="{{ route('procedures.index', array_filter(['filter' => 'inactive', 'q' => request('q')])) }}" class="filter-chip {{ $filter === 'inactive' ? 'active' : '' }}"><i class="bi bi-archive"></i> Inactivos <span class="count">{{ $counts['inactive'] }}</span></a>
            </nav>
            <a href="{{ route('specialties.index') }}" class="small text-decoration-none"><i class="bi bi-bookmark-star"></i> Especialidades</a>
        </div>

        @if($filter === 'no_price' && $canEdit)
            <div class="alert alert-warning d-flex gap-2 align-items-center small">
                <i class="bi bi-lightning-charge-fill"></i>
                <div>Escribe el precio y presiona <kbd>Enter</kbd>: se guarda al instante y pasas al siguiente.</div>
            </div>
        @endif

        @forelse($groups as $group)
            @php $s = $group['specialty']; @endphp
            <section class="card mb-3">
                <div class="card-header d-flex align-items-center gap-2">
                    <span class="rounded-circle d-inline-block" style="width:.8rem;height:.8rem;background: {{ $s?->color ?? '#94a3b8' }}"></span>
                    {{ $s?->name ?? 'Sin especialidad' }}
                    <span class="badge bg-light text-dark border ms-auto">{{ $group['items']->count() }}</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle table-stack">
                        <thead><tr><th class="ps-3" style="width:7rem">Código</th><th>Procedimiento</th><th>Se aplica</th><th>Duración</th><th class="text-end" style="width:13rem">Precio</th><th class="pe-3" style="width:6rem"></th></tr></thead>
                        <tbody>
                            @foreach($group['items'] as $procedure)
                                <tr>
                                    <td class="ps-3 small text-muted" data-label="Código"><code>{{ $procedure->code ?: '—' }}</code></td>
                                    <td class="stack-full">
                                        <span class="fw-semibold">{{ $procedure->name }}</span>
                                        @if($procedure->color)<span class="rounded-circle d-inline-block ms-1" style="width:.55rem;height:.55rem;background: {{ $procedure->color }}"></span>@endif
                                        @if($procedure->description)<div class="small text-muted">{{ \Illuminate\Support\Str::limit($procedure->description, 90) }}</div>@endif
                                    </td>
                                    <td class="small" data-label="Se aplica">{{ $procedure->applies_to_label }}</td>
                                    <td class="small text-nowrap" data-label="Duración"><i class="bi bi-clock text-muted"></i> {{ $procedure->duration_label }}</td>
                                    <td class="text-end" data-label="Precio">
                                        @if($canEdit)
                                            <form method="POST" action="{{ route('procedures.price', $procedure) }}" class="d-inline-flex align-items-center gap-1 justify-content-end" data-quick-price>
                                                @csrf @method('PATCH')
                                                <div class="input-group input-group-sm" style="width: 9rem">
                                                    <span class="input-group-text">{{ $symbol }}</span>
                                                    <input type="number" name="price" step="0.5" min="0" value="{{ $procedure->hasPrice() ? rtrim(rtrim(number_format((float) $procedure->price, 2, '.', ''), '0'), '.') : '' }}"
                                                           class="form-control text-end tabular {{ $procedure->hasPrice() ? '' : 'border-warning' }}" placeholder="Sin precio" aria-label="Precio de {{ $procedure->name }}">
                                                </div>
                                                <span class="quick-price-status text-success small" aria-live="polite"></span>
                                            </form>
                                            @if($procedure->price_max)<div class="small text-muted">hasta {{ $money($procedure->price_max) }}</div>@endif
                                        @else
                                            @if($procedure->hasPrice())
                                                <span class="fw-semibold tabular">{{ $money($procedure->price) }}</span>@if($procedure->price_max)<span class="small text-muted"> – {{ $money($procedure->price_max) }}</span>@endif
                                            @else
                                                <span class="alert-chip">Sin precio</span>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="pe-3 text-end text-nowrap">
                                        @if($canEdit)
                                            <button class="btn btn-sm btn-light" title="Editar" data-procedure-open="{{ json_encode($procedure->only(['id', 'code', 'name', 'specialty_id', 'price', 'price_max', 'duration_minutes', 'applies_to', 'color', 'description', 'active'])) }}"><i class="bi bi-pencil"></i></button>
                                        @endif
                                        @if($can('procedures.delete'))
                                            <form method="POST" action="{{ route('procedures.destroy', $procedure) }}" class="d-inline" onsubmit="return confirm('¿Eliminar «{{ e($procedure->name) }}» del tarifario?')">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-sm btn-light text-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
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
            <div class="card"><div class="empty-state">
                <div class="empty-icon"><i class="bi bi-search"></i></div>
                <h5 class="mb-1">Nada por aquí</h5>
                <p class="small mb-0">{{ $filter === 'no_price' ? '¡Todos los procedimientos tienen precio!' : 'No hay procedimientos que coincidan.' }}</p>
            </div></div>
        @endforelse

        @if($can('procedures.create') && $total < $suggestedCount)
            <form method="POST" action="{{ route('procedures.suggested') }}" class="text-center mt-2">
                @csrf
                <button class="btn btn-link btn-sm text-decoration-none" type="submit"><i class="bi bi-magic"></i> Agregar los procedimientos sugeridos que falten</button>
            </form>
        @endif
    @endif
</div>

{{-- Alta / edición (un solo modal, se llena con los datos de la fila) --}}
@if($can('procedures.create') || $canEdit)
<div class="modal fade" id="procedureModal" tabindex="-1" aria-hidden="true" @if($reopen) data-reopen @endif>
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content" method="POST" id="procedureForm"
              action="{{ $editingId ? route('procedures.update', $editingId) : route('procedures.store') }}"
              data-store="{{ route('procedures.store') }}" data-update="{{ route('procedures.update', '__ID__') }}">
            @csrf
            <input type="hidden" name="_method" value="{{ $editingId ? 'PUT' : 'POST' }}">
            <input type="hidden" name="_modal" value="procedure">
            <input type="hidden" name="_procedure_id" value="{{ $editingId }}">
            <div class="modal-header"><h5 class="modal-title" id="procedureTitle">{{ $editingId ? 'Editar procedimiento' : 'Nuevo procedimiento' }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body row g-3">
                <div class="col-md-9">
                    <label class="form-label" for="p-name">Nombre <span class="text-danger">*</span></label>
                    <input type="text" id="p-name" name="name" class="form-control @error('name') is-invalid @enderror" maxlength="150" value="{{ old('name') }}" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="p-code">Código</label>
                    <input type="text" id="p-code" name="code" class="form-control text-uppercase @error('code') is-invalid @enderror" maxlength="20" value="{{ old('code') }}" placeholder="END-01">
                    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="p-specialty">Especialidad</label>
                    <select id="p-specialty" name="specialty_id" class="form-select">
                        <option value="">Sin especialidad</option>
                        @foreach($specialties as $specialty)
                            <option value="{{ $specialty->id }}" @selected((string) old('specialty_id') === (string) $specialty->id)>{{ $specialty->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="p-duration">Duración en la agenda</label>
                    <select id="p-duration" name="duration_minutes" class="form-select">
                        @foreach(\App\Models\Procedure::DURATIONS as $minutes)
                            <option value="{{ $minutes }}" @selected((int) old('duration_minutes', 30) === $minutes)>{{ $minutes >= 60 ? intdiv($minutes, 60) . ' h' . ($minutes % 60 ? ' ' . $minutes % 60 . ' min' : '') : $minutes . ' min' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="p-price">Precio <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">{{ $symbol }}</span>
                        <input type="number" id="p-price" name="price" step="0.5" min="0" class="form-control @error('price') is-invalid @enderror" value="{{ old('price', 0) }}" required>
                    </div>
                    @error('price')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="p-price-max">Hasta <small class="text-muted fw-normal">(si el precio varía según el caso)</small></label>
                    <div class="input-group">
                        <span class="input-group-text">{{ $symbol }}</span>
                        <input type="number" id="p-price-max" name="price_max" step="0.5" min="0" class="form-control @error('price_max') is-invalid @enderror" value="{{ old('price_max') }}" placeholder="Opcional">
                    </div>
                    @error('price_max')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <label class="form-label">Se aplica</label>
                    <div class="row g-2">
                        @foreach(\App\Models\Procedure::APPLIES_TO as $key => [$label, $hint])
                            <div class="col-sm-6">
                                <input type="radio" class="btn-check" name="applies_to" id="p-applies-{{ $key }}" value="{{ $key }}" @checked(old('applies_to', 'paciente') === $key)>
                                <label class="btn btn-outline-primary w-100 text-start py-2" for="p-applies-{{ $key }}">
                                    <span class="fw-semibold d-block">{{ $label }}</span><small class="opacity-75">{{ $hint }}</small>
                                </label>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="col-md-8">
                    <label class="form-label" for="p-description">Descripción <small class="text-muted fw-normal">(opcional)</small></label>
                    <input type="text" id="p-description" name="description" class="form-control" maxlength="1000" value="{{ old('description') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="p-color">Color en la agenda</label>
                    <div class="d-flex gap-2 align-items-center">
                        <input type="color" id="p-color" class="form-control form-control-color" value="{{ old('color') ?: '#0e9f9a' }}" title="Color">
                        <input type="hidden" name="color" id="p-color-value" value="{{ old('color') }}">
                        <button type="button" class="btn btn-sm btn-link text-decoration-none" id="p-color-reset">El de la especialidad</button>
                    </div>
                </div>
                <div class="col-12" id="p-active-wrap" @unless($editingId) hidden @endunless>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="p-active" name="active" value="1" @checked(old('active', true))>
                        <label class="form-check-label" for="p-active">Activo (aparece en la agenda y en los presupuestos)</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Guardar</button></div>
        </form>
    </div>
</div>
@endif

{{-- Ajuste de precios por porcentaje --}}
@if($canEdit && $total)
<div class="modal fade" id="adjustModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('procedures.adjust') }}" onsubmit="return confirm('¿Aplicar el ajuste? Cambia los precios de una vez.')">
            @csrf
            <div class="modal-header"><h5 class="modal-title"><i class="bi bi-percent"></i> Ajustar precios</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body d-flex flex-column gap-3">
                <div>
                    <label class="form-label" for="a-percent">Porcentaje</label>
                    <div class="input-group">
                        <input type="number" id="a-percent" name="percent" step="0.1" class="form-control" placeholder="10 para subir, -5 para bajar" required>
                        <span class="input-group-text">%</span>
                    </div>
                    <div class="form-text" id="a-preview">Ejemplo: un procedimiento de {{ $symbol }} 100 quedaría en …</div>
                </div>
                <div>
                    <label class="form-label" for="a-specialty">Aplicar a</label>
                    <select id="a-specialty" name="specialty_id" class="form-select">
                        <option value="">Todo el tarifario</option>
                        @foreach($specialties as $specialty)
                            <option value="{{ $specialty->id }}">Solo {{ $specialty->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="round" value="1" id="a-round" checked>
                    <label class="form-check-label" for="a-round">Redondear a 0.50</label>
                </div>
                <p class="small text-muted mb-0">Solo cambia los procedimientos activos con precio. Las citas y presupuestos ya hechos no cambian.</p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Aplicar</button></div>
        </form>
    </div>
</div>
@endif

@include('admin.finance.partials.styles')

<script>
(function () {
    const form = document.getElementById('procedureForm');
    if (form) {
        const modal = () => bootstrap.Modal.getOrCreateInstance(document.getElementById('procedureModal'));
        const colorInput = document.getElementById('p-color');
        const colorValue = document.getElementById('p-color-value');
        colorInput.addEventListener('input', () => { colorValue.value = colorInput.value; });
        document.getElementById('p-color-reset').addEventListener('click', () => { colorValue.value = ''; colorInput.value = '#0e9f9a'; });

        // Abrir el modal vacío (nuevo) o con los datos de la fila (editar).
        document.querySelectorAll('[data-procedure-open]').forEach((button) => button.addEventListener('click', () => {
            const p = button.dataset.procedureOpen ? JSON.parse(button.dataset.procedureOpen) : null;
            form.reset();
            form.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
            form.action = p ? form.dataset.update.replace('__ID__', p.id) : form.dataset.store;
            form.querySelector('[name=_method]').value = p ? 'PUT' : 'POST';
            form.querySelector('[name=_procedure_id]').value = p ? p.id : '';
            document.getElementById('procedureTitle').textContent = p ? 'Editar procedimiento' : 'Nuevo procedimiento';
            document.getElementById('p-active-wrap').hidden = !p;
            const set = (name, value) => { const el = form.querySelector(`[name="${name}"]`); if (el) el.value = value ?? ''; };
            set('name', p?.name); set('code', p?.code); set('specialty_id', p?.specialty_id);
            set('price', p ? parseFloat(p.price) : 0); set('price_max', p?.price_max ? parseFloat(p.price_max) : '');
            set('duration_minutes', p?.duration_minutes ?? 30); set('description', p?.description);
            form.querySelector(`[name=applies_to][value="${p?.applies_to ?? 'paciente'}"]`).checked = true;
            colorValue.value = p?.color ?? ''; colorInput.value = p?.color || '#0e9f9a';
            document.getElementById('p-active').checked = p ? !!p.active : true;
            modal().show();
            setTimeout(() => document.getElementById('p-name').focus(), 300);
        }));
    }

    // Precio rápido: Enter guarda sin recargar y salta al siguiente.
    document.querySelectorAll('[data-quick-price]').forEach((quick) => {
        const input = quick.querySelector('input[name=price]');
        const status = quick.querySelector('.quick-price-status');
        let saved = input.value;
        const save = async () => {
            if (input.value === saved || input.value === '') return;
            const body = new FormData(quick);
            try {
                const response = await fetch(quick.action, { method: 'POST', body, headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                if (!response.ok) throw new Error();
                saved = input.value;
                input.classList.remove('border-warning');
                status.innerHTML = '<i class="bi bi-check-lg"></i>';
                setTimeout(() => { status.innerHTML = ''; }, 1500);
            } catch (e) {
                window.dexiToast?.('No se pudo guardar el precio.', 'error');
            }
        };
        quick.addEventListener('submit', (event) => { event.preventDefault(); save(); });
        input.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter') return;
            event.preventDefault();
            save();
            const all = [...document.querySelectorAll('[data-quick-price] input[name=price]')];
            all[all.indexOf(input) + 1]?.focus();
        });
        input.addEventListener('blur', save);
    });

    // Vista previa del ajuste por %.
    const percent = document.getElementById('a-percent');
    if (percent) {
        const preview = document.getElementById('a-preview');
        const round = document.getElementById('a-round');
        const update = () => {
            const p = parseFloat(percent.value);
            if (isNaN(p) || p === 0) { preview.textContent = 'Ejemplo: un procedimiento de {{ $symbol }} 100 quedaría en …'; return; }
            let value = 100 * (1 + p / 100);
            value = round.checked ? Math.round(value * 2) / 2 : Math.round(value * 100) / 100;
            preview.textContent = `Ejemplo: un procedimiento de {{ $symbol }} 100 quedaría en {{ $symbol }} ${value.toFixed(2)}.`;
        };
        percent.addEventListener('input', update);
        round.addEventListener('change', update);
    }
})();
</script>
@endsection
