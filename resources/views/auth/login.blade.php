@extends('layouts.auth')

@section('title', 'Iniciar sesión - ' . \App\Support\Brand::appName())

@section('content')
<div class="auth-card">
    <div class="auth-header">
        <img src="{{ asset('images/logo.webp') }}" alt="{{ \App\Support\Brand::appName() }}" class="auth-logo">
        <h1 class="visually-hidden">{{ \App\Support\Brand::appName() }}</h1>
        <p>{{ \App\Support\Brand::tagline() }}</p>
    </div>

    @if($errors->any())
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle"></i>
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form action="{{ route('login.store') }}" method="POST">
        @csrf

        <div class="form-group">
            <label for="email" class="form-label">Email</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror"
                       placeholder="correo@ejemplo.com" value="{{ old('email') }}" required>
            </div>
        </div>

        <div class="form-group">
            <label for="password" class="form-label">Contraseña</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                <input type="password" id="password" name="password" class="form-control @error('password') is-invalid @enderror"
                       placeholder="••••••••" required>
            </div>
        </div>

        <div class="remember-me">
            <input type="checkbox" id="remember" name="remember" value="1">
            <label for="remember" style="margin: 0; cursor: pointer;">Recuerda mi información</label>
        </div>

        <button type="submit" class="btn btn-login">
            <i class="bi bi-box-arrow-in-right"></i> Iniciar Sesión
        </button>
    </form>
</div>
@endsection
