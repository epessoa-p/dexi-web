@extends('layouts.app')

@section('title', 'Categorías de gasto')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $types = \App\Models\ExpenseCategory::TYPES;
    $icons = ['general' => 'bi-receipt', 'basico' => 'bi-lightning-charge', 'proveedor' => 'bi-truck', 'personal' => 'bi-person-badge'];
    $newType = old('_modal') === 'newCategory' ? old('type', 'general') : 'general';
@endphp
<div class="container-fluid" style="max-width: 980px;">
    @include('admin.finance.partials.errors')
    <div class="page-header">
        <div>
            <h1 class="mb-1"><i class="bi bi-tags"></i> Categorías de gasto</h1>
            <p class="text-muted mb-0">El tipo decide en qué pestaña de Gastos aparece y a quién se le paga.</p>
        </div>
        @if($can('expense_categories.create'))
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newCategory"><i class="bi bi-plus-lg"></i> Nueva categoría</button>
        @endif
    </div>

    <div class="row g-3">
        @foreach($groups as $group => $data)
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 pt-3 d-flex align-items-center gap-2">
                        <i class="bi {{ $icons[$group] }} text-warning-emphasis"></i>
                        <h6 class="fw-bold mb-0">{{ $data['title'] }}</h6>
                        <span class="badge bg-light text-dark border ms-auto">{{ $data['items']->count() }}</span>
                    </div>
                    <ul class="list-group list-group-flush">
                        @forelse($data['items'] as $category)
                            <li class="list-group-item d-flex align-items-center gap-2 {{ $category->active ? '' : 'text-muted' }}">
                                <span class="flex-fill">
                                    {{ $category->name }}
                                    @if($category->recurring)<span class="badge bg-light text-dark border ms-1">{{ \App\Models\ExpenseCategory::FREQUENCIES[$category->frequency ?? 'mensual'] }}</span>@endif
                                    @unless($category->active)<span class="badge bg-secondary ms-1">Inactiva</span>@endunless
                                </span>
                                <small class="text-muted">{{ $category->expenses_count }} {{ $category->expenses_count === 1 ? 'gasto' : 'gastos' }}</small>
                                @if($can('expense_categories.edit'))
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#cat-{{ $category->id }}" title="Editar"><i class="bi bi-pencil"></i></button>
                                @endif
                                @if($can('expense_categories.delete'))
                                    <form method="POST" action="{{ route('expense-categories.destroy', $category) }}" class="d-inline"
                                          onsubmit="return confirm('{{ $category->expenses_count ? '¿Dar de baja ' . e($category->name) . '? Tiene gastos: quedará inactiva.' : '¿Eliminar ' . e($category->name) . '?' }}')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" type="submit" title="{{ $category->expenses_count ? 'Dar de baja' : 'Eliminar' }}"><i class="bi bi-trash"></i></button>
                                    </form>
                                @endif
                            </li>
                        @empty
                            <li class="list-group-item text-muted small">Sin categorías de este tipo.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        @endforeach
    </div>
</div>

@if($can('expense_categories.create') || $can('expense_categories.edit'))
    @php
        $modals = [['id' => 'newCategory', 'category' => null]];
        foreach ($groups as $data) {
            foreach ($data['items'] as $category) {
                $modals[] = ['id' => "cat-{$category->id}", 'category' => $category];
            }
        }
    @endphp
    @foreach($modals as $modal)
        @php
            $c = $modal['category'];
            $reopen = $errors->any() && old('_modal') === $modal['id'];
            $type = $reopen ? old('type') : ($c?->group ?? 'general');
            $frequency = $reopen ? old('frequency', 'mensual') : ($c?->frequency ?? 'mensual');
        @endphp
        <div class="modal fade" id="{{ $modal['id'] }}" tabindex="-1" aria-hidden="true" @if($reopen) data-reopen @endif>
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="POST" action="{{ $c ? route('expense-categories.update', $c) : route('expense-categories.store') }}" data-category-form>
                    @csrf
                    @if($c) @method('PUT') @endif
                    <input type="hidden" name="_modal" value="{{ $modal['id'] }}">
                    <div class="modal-header"><h5 class="modal-title">{{ $c ? 'Editar categoría' : 'Nueva categoría' }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <label class="form-label">Nombre <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control mb-1" maxlength="80" value="{{ $reopen ? old('name') : $c?->name }}" required>
                        @if($reopen) @error('name')<div class="text-danger small">{{ $message }}</div>@enderror @endif
                        <label class="form-label mt-3">Tipo</label>
                        <div class="row g-2">
                            @foreach($types as $key => $label)
                                <div class="col-6">
                                    <input type="radio" class="btn-check" name="type" id="{{ $modal['id'] }}-type-{{ $key }}" value="{{ $key }}" @checked($type === $key)>
                                    <label class="btn btn-outline-primary w-100 text-start" for="{{ $modal['id'] }}-type-{{ $key }}"><i class="bi {{ $icons[$key] }}"></i> {{ $label }}</label>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-3" data-frequency @if($type !== 'basico') hidden @endif>
                            <label class="form-label">Se paga</label>
                            <div class="btn-group w-100" role="group">
                                @foreach(\App\Models\ExpenseCategory::FREQUENCIES as $key => $label)
                                    <input type="radio" class="btn-check" name="frequency" id="{{ $modal['id'] }}-freq-{{ $key }}" value="{{ $key }}" @checked($frequency === $key)>
                                    <label class="btn btn-outline-secondary" for="{{ $modal['id'] }}-freq-{{ $key }}">{{ $label }}</label>
                                @endforeach
                            </div>
                        </div>
                        @if($c)
                            <div class="form-check form-switch mt-3">
                                <input class="form-check-input" type="checkbox" name="active" value="1" id="{{ $modal['id'] }}-active" @checked($reopen ? old('active') : $c->active)>
                                <label class="form-check-label" for="{{ $modal['id'] }}-active">Activa</label>
                            </div>
                        @endif
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Guardar</button></div>
                </form>
            </div>
        </div>
    @endforeach
    <script>
        // «Se paga: Mensual / Anual» solo para los servicios básicos.
        document.addEventListener('change', (event) => {
            if (event.target.name !== 'type') return;
            const form = event.target.closest('[data-category-form]');
            form?.querySelector('[data-frequency]')?.toggleAttribute('hidden', event.target.value !== 'basico');
        });
    </script>
@endif
@include('admin.finance.partials.styles')
@endsection
