@php
    $featureCatalog = \App\Models\Plan::MODULES;
    $selectedFeatures = old('features', $plan?->features ?? []);
@endphp

<div class="container-fluid" style="max-width: 1000px;">
    <div class="page-header">
        <div>
            <h1 class="mb-1">{{ $plan ? 'Editar plan' : 'Nuevo plan' }}</h1>
            <p class="text-muted mb-0">Define el precio, el período de facturación y los cupos incluidos.</p>
        </div>
        <a href="{{ route('plans.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>

    @if($errors->any())
        <div class="alert alert-danger">
            <strong>Revisa los siguientes errores:</strong>
            <ul class="mb-0 mt-1 ps-3">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form action="{{ $action }}" method="POST">
        @csrf
        @if($method !== 'POST') @method($method) @endif

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3">Datos del plan</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Nombre <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="{{ old('name', $plan?->name) }}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Identificador (slug)</label>
                        <input type="text" name="slug" class="form-control" value="{{ old('slug', $plan?->slug) }}" placeholder="Se genera del nombre si lo dejas vacío">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Descripción</label>
                        <textarea name="description" rows="2" class="form-control">{{ old('description', $plan?->description) }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3">Precio y facturación</h6>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Precio <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0" name="price" class="form-control" value="{{ old('price', $plan?->price ?? '0.00') }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Moneda <span class="text-danger">*</span></label>
                        <input type="text" name="currency" maxlength="3" class="form-control text-uppercase" value="{{ old('currency', $plan?->currency ?? 'USD') }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Período <span class="text-danger">*</span></label>
                        <select name="billing_period" class="form-select" required>
                            @foreach(\App\Models\Plan::PERIODS as $value => $label)
                                <option value="{{ $value }}" {{ old('billing_period', $plan?->billing_period ?? 'monthly') === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Días de prueba</label>
                        <input type="number" min="0" max="365" name="trial_days" class="form-control" value="{{ old('trial_days', $plan?->trial_days ?? 0) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Orden de aparición</label>
                        <input type="number" min="0" name="sort_order" class="form-control" value="{{ old('sort_order', $plan?->sort_order ?? 0) }}">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check form-switch">
                            <input type="hidden" name="active" value="0">
                            <input class="form-check-input" type="checkbox" id="active" name="active" value="1" {{ old('active', $plan?->active ?? true) ? 'checked' : '' }}>
                            <label class="form-check-label" for="active">Plan disponible</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-1">Límites incluidos</h6>
                <p class="text-muted small mb-3">Deja el campo vacío para que ese recurso sea ilimitado.</p>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Máximo de usuarios</label>
                        <input type="number" min="1" name="max_users" class="form-control" value="{{ old('max_users', $plan?->max_users) }}" placeholder="Ilimitado">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Máximo de personal</label>
                        <input type="number" min="1" name="max_personal" class="form-control" value="{{ old('max_personal', $plan?->max_personal) }}" placeholder="Ilimitado">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Máximo de consultorios</label>
                        <input type="number" min="1" name="max_branches" class="form-control" value="{{ old('max_branches', $plan?->max_branches) }}" placeholder="Ilimitado">
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-1">Módulos incluidos</h6>
                <p class="text-muted small mb-3">Usuarios, roles, personal, consultorios y configuración son administrativos: siempre están disponibles.</p>
                <div class="row g-2">
                    @foreach($featureCatalog as $value => $label)
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="feature_{{ $value }}" name="features[]" value="{{ $value }}"
                                       {{ in_array($value, $selectedFeatures, true) ? 'checked' : '' }}>
                                <label class="form-check-label" for="feature_{{ $value }}">{{ $label }}</label>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="d-flex gap-2 mb-4">
            <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> {{ $plan ? 'Guardar cambios' : 'Crear plan' }}</button>
            <a href="{{ route('plans.index') }}" class="btn btn-light border">Cancelar</a>
        </div>
    </form>
</div>
