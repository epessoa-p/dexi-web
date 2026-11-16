@extends('layouts.app')

@section('title', 'Nueva clínica')

@section('page')
<div class="container-fluid" style="max-width: 960px;">
<div class="page-header">
    <div>
        <a href="{{ route('companies.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Clínicas</a>
        <h1 class="mt-1"><i class="bi bi-hospital"></i> Nueva clínica</h1>
    </div>
</div>
<p class="text-muted">Crea la clínica, su suscripción de prueba y, si quieres, su usuario administrador en un solo paso.</p>

<form action="{{ route('companies.store') }}" method="POST">
    @csrf

    <div class="card mb-3">
        <div class="card-body">
            <h6 class="fw-bold mb-3">Datos de la clínica</h6>

            <div class="row">
                <div class="col-md-8 form-group mb-3">
                    <label for="name" class="form-label">Nombre <span class="text-danger">*</span></label>
                    <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" required>
                </div>
                <div class="col-md-4 form-group mb-3">
                    <label for="ruc" class="form-label">NIT / RUC</label>
                    <input type="text" id="ruc" name="ruc" class="form-control @error('ruc') is-invalid @enderror" value="{{ old('ruc') }}">
                </div>
                <div class="col-md-6 form-group mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email') }}">
                </div>
                <div class="col-md-6 form-group mb-3">
                    <label for="phone" class="form-label">Teléfono</label>
                    <input type="text" id="phone" name="phone" class="form-control @error('phone') is-invalid @enderror" value="{{ old('phone') }}">
                </div>
                <div class="col-12 form-group mb-3">
                    <label for="address" class="form-label">Dirección</label>
                    <input type="text" id="address" name="address" class="form-control @error('address') is-invalid @enderror" value="{{ old('address') }}">
                </div>
                <div class="col-12 form-group">
                    <label for="description" class="form-label">Descripción</label>
                    <textarea id="description" name="description" class="form-control @error('description') is-invalid @enderror" rows="2">{{ old('description') }}</textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h6 class="fw-bold mb-1">Plan inicial</h6>
            <p class="text-muted small mb-3">La clínica arranca en periodo de prueba con este plan. Sin plan no podrá entrar hasta que le asignes una suscripción.</p>
            <select id="plan_id" name="plan_id" class="form-select @error('plan_id') is-invalid @enderror">
                <option value="">— Asignar después —</option>
                @foreach($plans as $plan)
                    <option value="{{ $plan->id }}" @selected((string) old('plan_id', $defaultPlanId) === (string) $plan->id)>
                        {{ $plan->name }} — {{ $plan->currency }} {{ number_format($plan->price, 2) }} / {{ $plan->periodLabel() }}
                        @if($plan->trial_days) · {{ $plan->trial_days }} días de prueba @endif
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h6 class="fw-bold mb-1">Administrador de la clínica</h6>
            <p class="text-muted small mb-3">Opcional. Tendrá el rol <strong>Administrador de Clínica</strong>.</p>
            <div class="row">
                <div class="col-md-4 form-group mb-3">
                    <label for="admin_name" class="form-label">Usuario</label>
                    <input type="text" id="admin_name" name="admin_name" class="form-control @error('admin_name') is-invalid @enderror" value="{{ old('admin_name') }}">
                </div>
                <div class="col-md-4 form-group mb-3">
                    <label for="admin_email" class="form-label">Email</label>
                    <input type="email" id="admin_email" name="admin_email" class="form-control @error('admin_email') is-invalid @enderror" value="{{ old('admin_email') }}">
                </div>
                <div class="col-md-4 form-group mb-3">
                    <label for="admin_password" class="form-label">Contraseña</label>
                    <input type="password" id="admin_password" name="admin_password" class="form-control @error('admin_password') is-invalid @enderror" autocomplete="new-password">
                </div>
            </div>
        </div>
    </div>

    <div class="mt-3 mb-4">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-circle"></i> Crear Clínica
        </button>
        <a href="{{ route('companies.index') }}" class="btn btn-light">
            <i class="bi bi-arrow-left"></i> Cancelar
        </a>
    </div>
</form>
</div>
@endsection
