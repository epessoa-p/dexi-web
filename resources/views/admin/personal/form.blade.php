@php
    $hasUser = (bool) $personal?->user;
    // En edición con acceso ya creado el bloque siempre se muestra.
    $createUserChecked = old('create_user', $hasUser ? 1 : 0);
@endphp

<div class="container-fluid" style="max-width: 1100px;">
    <div class="page-header">
        <div>
            <h1 class="mb-1">{{ $personal ? 'Editar personal' : 'Nuevo personal' }}</h1>
            <p class="text-muted mb-0">El usuario de acceso al sistema es opcional; el cargo define su rol si lo creas.</p>
        </div>
        <a href="{{ route('personal.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>

    @if($errors->any())
        <div class="alert alert-danger alert-dismissible fade show d-flex gap-2 align-items-start" role="alert">
            <i class="bi bi-exclamation-triangle-fill mt-1 flex-shrink-0"></i>
            <div>
                <strong>Por favor corrige los siguientes errores:</strong>
                <ul class="mb-0 mt-1 ps-3">
                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <form action="{{ $action }}" method="POST" class="row g-4">
        @csrf
        @if($method !== 'POST') @method($method) @endif

        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <h6 class="fw-bold mb-3"><i class="bi bi-person-badge"></i> Datos del personal</h6>
                    <div class="row g-3">
                        @if($companies->count() > 1)
                            <div class="col-md-6">
                                <label class="form-label">Clínica</label>
                                <select name="company_id" id="company_id" class="form-select" required>
                                    <option value="">Seleccionar clínica</option>
                                    @foreach($companies as $company)
                                        <option value="{{ $company->id }}" {{ (string) old('company_id', $personal?->company_id) === (string) $company->id ? 'selected' : '' }}>{{ $company->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif

                        <div class="col-md-6">
                            <label class="form-label">Cargo <span class="text-danger">*</span></label>
                            <select name="cargo_id" class="form-select" required>
                                <option value="">Seleccionar cargo</option>
                                @foreach($cargos as $cargo)
                                    <option value="{{ $cargo->id }}" {{ (string) old('cargo_id', $personal?->cargo_id) === (string) $cargo->id ? 'selected' : '' }}>
                                        {{ $cargo->name }} · Rol: {{ $cargo->role?->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label">Nombre completo <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" value="{{ old('full_name', $personal?->full_name) }}" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Documento</label>
                            <input type="text" name="id_number" class="form-control" value="{{ old('id_number', $personal?->id_number) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Teléfono</label>
                            <input type="text" name="phone" class="form-control" value="{{ old('phone', $personal?->phone) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Fecha de ingreso</label>
                            <input type="date" name="hire_date" class="form-control" value="{{ old('hire_date', optional($personal?->hire_date)->toDateString()) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Dirección</label>
                            <input type="text" name="address" class="form-control" value="{{ old('address', $personal?->address) }}">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notas</label>
                            <textarea name="notes" rows="2" class="form-control">{{ old('notes', $personal?->notes) }}</textarea>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="active" value="1" {{ old('active', $personal?->active ?? true) ? 'checked' : '' }}>
                                <label class="form-check-label">Activo</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <h6 class="fw-bold mb-3"><i class="bi bi-person-gear"></i> Acceso al sistema</h6>

                    @if($hasUser)
                        <div class="alert alert-success small d-flex justify-content-between align-items-center gap-2">
                            <span>Este personal ya tiene acceso como <strong>{{ $personal->user->name }}</strong>.</span>
                        </div>
                    @else
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="create_user" name="create_user" value="1" {{ $createUserChecked ? 'checked' : '' }}>
                            <label class="form-check-label fw-semibold" for="create_user">Crear usuario de acceso al sistema</label>
                            <div class="form-text">Déjalo apagado si esta persona no necesita iniciar sesión.</div>
                        </div>
                    @endif

                    <div id="user-fields" class="{{ $hasUser || $createUserChecked ? '' : 'd-none' }}">
                        <div class="alert alert-info small">
                            El nombre de usuario se genera automáticamente con base en el nombre completo.
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Email de acceso <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $personal?->user?->email ?? $personal?->email) }}">
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Contraseña {{ $hasUser ? '(opcional para cambiar)' : '' }}</label>
                            <input type="password" name="password" class="form-control @error('password') is-invalid @enderror" autocomplete="new-password">
                            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Confirmar contraseña</label>
                            <input type="password" name="password_confirmation" class="form-control" autocomplete="new-password">
                        </div>
                    </div>

                    @unless($hasUser)
                        <div id="no-user-note" class="text-muted small mb-3 {{ $createUserChecked ? 'd-none' : '' }}">
                            <i class="bi bi-info-circle"></i> Se guardará solo la ficha del personal, sin credenciales.
                            Puedes crear el acceso más adelante desde esta misma pantalla.
                        </div>
                    @endunless

                    <button class="btn btn-primary w-100" type="submit">
                        <i class="bi bi-save"></i> {{ $personal ? 'Guardar cambios' : 'Crear personal' }}
                    </button>
                    <a href="{{ route('personal.index') }}" class="btn btn-light border w-100 mt-2">Cancelar</a>
                </div>
            </div>

            @if($hasUser)
                <div class="card border-0 shadow-sm mt-3">
                    <div class="card-body p-4">
                        <h6 class="fw-bold mb-2">Retirar acceso</h6>
                        <p class="text-muted small mb-3">Elimina el usuario y sus credenciales, pero conserva la ficha del personal.</p>
                        <button type="submit" form="revoke-access-form" class="btn btn-outline-danger w-100">
                            <i class="bi bi-person-dash"></i> Quitar acceso al sistema
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </form>

    @if($hasUser)
        <form id="revoke-access-form" action="{{ route('personal.revoke-access', $personal) }}" method="POST"
              onsubmit="return confirm('¿Quitar el acceso al sistema de {{ addslashes($personal->full_name) }}? Se eliminará su usuario.')">
            @csrf
            @method('DELETE')
        </form>
    @endif
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var toggle = document.getElementById('create_user');
        var fields = document.getElementById('user-fields');
        var note = document.getElementById('no-user-note');

        if (!toggle || !fields) {
            return;
        }

        toggle.addEventListener('change', function () {
            fields.classList.toggle('d-none', !toggle.checked);
            if (note) {
                note.classList.toggle('d-none', toggle.checked);
            }
        });
    });
</script>
@endpush
