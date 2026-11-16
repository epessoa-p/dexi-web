@extends('layouts.auth')

@section('title', 'Seleccionar clínica - ' . \App\Support\Brand::appName())

@section('content')
<div class="auth-card">
    <div class="auth-header">
        <div class="auth-icon"><x-tooth-icon size="2.6rem" /></div>
        <h1>Selecciona una clínica</h1>
        <p>Tu cuenta tiene acceso a más de una clínica</p>
    </div>

    <div class="list-group">
        @foreach($companies as $company)
            <form action="{{ route('set-company', $company->id) }}" method="POST" style="display: inline-block; width: 100%; margin-bottom: 10px;">
                @csrf
                <button type="submit" class="list-group-item list-group-item-action p-3" style="text-align: left;">
                    <div class="d-flex">
                        <div>
                            <h6 class="mb-1 text-primary">{{ $company->name }}</h6>
                            <small class="text-muted">
                                @if($company->ruc)
                                    <span><i class="bi bi-receipt"></i> {{ $company->ruc }}</span>
                                @endif
                                @if($company->email)
                                    <span class="ms-2"><i class="bi bi-envelope"></i> {{ $company->email }}</span>
                                @endif
                            </small>
                        </div>
                        <div class="ms-auto text-end">
                            <i class="bi bi-chevron-right text-primary"></i>
                        </div>
                    </div>
                </button>
            </form>
        @endforeach
    </div>

    <hr class="my-4">

    <form action="{{ route('logout') }}" method="POST">
        @csrf
        <button type="submit" class="btn btn-outline-secondary w-100">
            <i class="bi bi-box-arrow-left"></i> Usar otra cuenta
        </button>
    </form>
</div>
@endsection
