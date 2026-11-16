@extends('layouts.app')

@section('title', 'Editar usuario')

@section('page')
<div class="container-fluid" style="max-width: 760px;">
<div class="page-header">
    <div>
        <a href="{{ route('users.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Usuarios</a>
        <h1 class="mt-1"><i class="bi bi-pencil"></i> Editar usuario</h1>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form action="{{ route('users.update', $user) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="form-group mb-3">
                <label for="name" class="form-label">Usuario</label>
                <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $user->name) }}" required>
            </div>

            <div class="form-group mb-3">
                <label for="email" class="form-label">Email</label>
                <input type="email" id="email" class="form-control" value="{{ $user->email }}" disabled>
            </div>

            <div class="form-group mb-3">
                <label for="phone" class="form-label">Teléfono</label>
                <input type="text" id="phone" name="phone" class="form-control @error('phone') is-invalid @enderror" value="{{ old('phone', $user->phone) }}">
            </div>

            <div class="row">
                <div class="col-md-6 form-group mb-3">
                    <label for="password" class="form-label">Nueva contraseña</label>
                    <input type="password" id="password" name="password" class="form-control @error('password') is-invalid @enderror" autocomplete="new-password">
                    <div class="form-text">Déjala vacía para no cambiarla.</div>
                </div>
                <div class="col-md-6 form-group mb-3">
                    <label for="password_confirmation" class="form-label">Confirmar contraseña</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" class="form-control" autocomplete="new-password">
                </div>
            </div>

            @if($company)
                <div class="form-group mb-3">
                    <label for="role_id" class="form-label">Rol en {{ $company->displayName() }}</label>
                    <select id="role_id" name="role_id" class="form-select @error('role_id') is-invalid @enderror">
                        @foreach($roles as $role)
                            <option value="{{ $role->id }}" @selected((string) old('role_id', $currentRoleId) === (string) $role->id)>{{ $role->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if(auth()->user()->is_super_admin && $user->id !== auth()->id())
                <div class="form-check form-switch mb-3">
                    <input type="hidden" name="is_super_admin" value="0">
                    <input class="form-check-input" type="checkbox" id="is_super_admin" name="is_super_admin" value="1" @checked(old('is_super_admin', $user->is_super_admin))>
                    <label class="form-check-label" for="is_super_admin">Operador de la plataforma (superadmin)</label>
                </div>
            @endif

            <div class="form-group">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle"></i> Actualizar
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
