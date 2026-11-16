@extends('layouts.app')

@section('title', 'Nuevo usuario')

@section('page')
<div class="container-fluid" style="max-width: 760px;">
<div class="page-header">
    <div>
        <a href="{{ route('users.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Usuarios</a>
        <h1 class="mt-1"><i class="bi bi-person-plus"></i> Nuevo usuario</h1>
    </div>
</div>
@if($company)
    <p class="text-muted mb-0">Tendrá acceso a <strong>{{ $company->displayName() }}</strong> con el rol que elijas.</p>
@endif

<div class="card">
    <div class="card-body">
        <form action="{{ route('users.store') }}" method="POST">
            @csrf

            <div class="form-group mb-3">
                <label for="name" class="form-label">Usuario</label>
                <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" required>
                <div class="form-text">Sin espacios. Sirve para iniciar sesión, igual que el email.</div>
            </div>

            <div class="form-group mb-3">
                <label for="email" class="form-label">Email</label>
                <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email') }}" required>
            </div>

            <div class="row">
                <div class="col-md-6 form-group mb-3">
                    <label for="password" class="form-label">Contraseña</label>
                    <input type="password" id="password" name="password" class="form-control @error('password') is-invalid @enderror" required>
                </div>
                <div class="col-md-6 form-group mb-3">
                    <label for="password_confirmation" class="form-label">Confirmar Contraseña</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" class="form-control" required>
                </div>
            </div>

            <div class="form-group mb-3">
                <label for="phone" class="form-label">Teléfono</label>
                <input type="text" id="phone" name="phone" class="form-control @error('phone') is-invalid @enderror" value="{{ old('phone') }}">
            </div>

            {{-- Superadmin en Modo Global: elige a qué clínica pertenece (opcional). --}}
            @if(!$company && $companies->isNotEmpty())
                <div class="form-group mb-3">
                    <label for="company_id" class="form-label">Clínica</label>
                    <select id="company_id" name="company_id" class="form-select @error('company_id') is-invalid @enderror">
                        <option value="">— Sin clínica —</option>
                        @foreach($companies as $option)
                            <option value="{{ $option->id }}" @selected((string) old('company_id') === (string) $option->id)>{{ $option->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="form-group mb-3">
                <label for="role_id" class="form-label">Rol en la clínica</label>
                <select id="role_id" name="role_id" class="form-select @error('role_id') is-invalid @enderror" @required($company)>
                    <option value="">Seleccionar rol</option>
                    @foreach($roles as $role)
                        <option value="{{ $role->id }}" @selected((string) old('role_id') === (string) $role->id)>{{ $role->name }}</option>
                    @endforeach
                </select>
                <div class="form-text">El rol define qué puede hacer dentro de la clínica.</div>
            </div>

            @if(auth()->user()->is_super_admin)
                <div class="form-check form-switch mb-3">
                    <input type="hidden" name="is_super_admin" value="0">
                    <input class="form-check-input" type="checkbox" id="is_super_admin" name="is_super_admin" value="1" @checked(old('is_super_admin'))>
                    <label class="form-check-label" for="is_super_admin">Operador de la plataforma (superadmin)</label>
                </div>
            @endif

            <div class="form-group">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle"></i> Crear Usuario
                </button>
                <a href="{{ route('users.index') }}" class="btn btn-light">
                    <i class="bi bi-arrow-left"></i> Cancelar
                </a>
            </div>
        </form>
    </div>
</div>
</div>
@endsection
