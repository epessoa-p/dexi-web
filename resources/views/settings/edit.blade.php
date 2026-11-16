@extends('layouts.app')

@section('title', 'Configuración de la clínica')

@section('page')
<div class="container-fluid" style="max-width: 1100px;">
    <div class="page-header">
        <div>
            <h1 class="mb-1"><i class="bi bi-gear"></i> Configuración</h1>
            <p class="text-muted mb-0">Ajustes de {{ $company->displayName() }}.</p>
        </div>
    </div>

    @if($errors->any())
        <div class="alert alert-danger">
            <strong>Revisa los siguientes errores:</strong>
            <ul class="mb-0 mt-1 ps-3">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('settings.update') }}" method="POST" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        @foreach($grouped as $group => $definitions)
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <h6 class="fw-bold mb-3">{{ \App\Support\SettingDefinitions::groupLabels()[$group] ?? ucfirst($group) }}</h6>
                    <div class="row g-3">
                        @foreach($definitions as $key => $definition)
                            @include('partials.setting-field', ['key' => $key, 'definition' => $definition, 'values' => $values])
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach

        <div class="d-flex gap-2 mb-4">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Guardar configuración</button>
            <a href="{{ route('dashboard') }}" class="btn btn-light border">Cancelar</a>
        </div>
    </form>
</div>
@endsection
