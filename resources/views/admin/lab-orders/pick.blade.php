@extends('layouts.app')

@section('title', 'Nueva orden de laboratorio')

@section('page')
<div class="container-fluid" style="max-width: 720px;">
    <div class="page-header">
        <div>
            <a href="{{ route('lab-orders.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Laboratorio</a>
            <h1 class="mt-1">Nueva orden de laboratorio</h1>
            <p class="page-subtitle">¿Para qué paciente es el trabajo?</p>
        </div>
    </div>
    <form method="GET" class="search-hero mb-3">
        <i class="bi bi-search"></i>
        <input type="search" name="q" value="{{ request('q') }}" class="form-control form-control-lg" placeholder="Nombre, documento o historia…" autofocus aria-label="Buscar paciente">
    </form>
    @if(request('q'))
        <div class="card">
            <div class="list-group list-group-flush">
                @forelse($patients as $p)
                    <a href="{{ route('lab-orders.create', ['patient_id' => $p->id]) }}" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                        <span class="avatar">{{ $p->initials }}</span>
                        <span class="flex-fill"><span class="fw-semibold">{{ $p->full_name }}</span> <span class="small text-muted">{{ $p->code }}</span></span>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </a>
                @empty
                    <div class="list-group-item small text-muted">No se encontró ningún paciente con «{{ request('q') }}».</div>
                @endforelse
            </div>
        </div>
    @endif
</div>
@endsection
