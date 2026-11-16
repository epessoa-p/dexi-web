@extends('layouts.app')

@section('title', 'Módulo no incluido')

@section('page')
<div class="container-fluid">
    <div class="d-flex align-items-center justify-content-center" style="min-height: 60vh;">
        <div class="text-center" style="max-width: 520px;">
            <h1 class="display-4 text-warning"><i class="bi bi-box-seam"></i></h1>
            <h2 class="mb-3">Tu plan no incluye este módulo</h2>
            <p class="text-muted mb-2">
                El plan <strong>{{ $company->currentPlan()?->name ?? 'actual' }}</strong> de {{ $company->displayName() }}
                no incluye
                @foreach($modules as $module)
                    <strong>{{ \App\Models\Plan::moduleLabel($module) }}</strong>@if(!$loop->last) ni @endif
                @endforeach.
            </p>
            <p class="text-muted mb-4">Contacta a soporte para ampliar tu plan.</p>
            <div class="d-inline-block text-start mb-4">
                @include('partials.support-contact')
            </div>
            <div>
                <a href="{{ route('dashboard') }}" class="btn btn-primary">
                    <i class="bi bi-arrow-left"></i> Volver al Dashboard
                </a>
            </div>
        </div>
    </div>
</div>
@endsection
