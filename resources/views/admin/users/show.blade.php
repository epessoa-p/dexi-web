@extends('layouts.app')

@section('title', $user->name)

@section('page')
<div class="container-fluid" style="max-width: 760px;">
<div class="page-header">
    <div>
        <a href="{{ route('users.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Usuarios</a>
        <h1 class="mt-1"><i class="bi bi-person"></i> {{ $user->name }}</h1>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Información del Usuario</h5>
    </div>
    <div class="card-body">
        <p><strong>Email:</strong> {{ $user->email }}</p>
        <p><strong>Teléfono:</strong> {{ $user->phone ?? '-' }}</p>
        <p><strong>Super Admin:</strong> <span class="badge {{ $user->is_super_admin ? 'bg-danger' : 'bg-secondary' }}">{{ $user->is_super_admin ? 'Sí' : 'No' }}</span></p>
        <p><strong>Estado:</strong> <span class="badge {{ $user->active ? 'bg-success' : 'bg-danger' }}">{{ $user->active ? 'Activo' : 'Inactivo' }}</span></p>
    </div>
</div>

</div>
@endsection
