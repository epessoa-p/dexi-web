@extends('layouts.app')

@section('title', 'Especialidades')

@section('page')
@php
    $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $tenantCompany);
    $canEdit = $can('procedures.edit');
    $modals = $specialties->map(fn ($s) => ['id' => "sp-{$s->id}", 'specialty' => $s])->prepend(['id' => 'newSpecialty', 'specialty' => null]);
@endphp
<div class="container-fluid" style="max-width: 860px;">
    @include('admin.finance.partials.errors')

    <div class="page-header">
        <div>
            <a href="{{ route('procedures.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Tarifario</a>
            <h1 class="mt-1"><i class="bi bi-bookmark-star"></i> Especialidades</h1>
            <p class="page-subtitle">Agrupan el tarifario y dan color a las citas en la agenda.</p>
        </div>
        @if($canEdit)
            <div class="page-actions">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newSpecialty"><i class="bi bi-plus-lg"></i> Nueva especialidad</button>
            </div>
        @endif
    </div>

    <div class="card">
        <ul class="list-group list-group-flush">
            @foreach($specialties as $specialty)
                <li class="list-group-item d-flex align-items-center gap-3 py-3 {{ $specialty->active ? '' : 'text-muted' }}">
                    <span class="rounded-3 flex-shrink-0" style="width:2.2rem;height:2.2rem;background: {{ $specialty->color }}"></span>
                    <div class="flex-fill">
                        <div class="fw-semibold">{{ $specialty->name }} @unless($specialty->active)<span class="badge bg-secondary">Inactiva</span>@endunless</div>
                        <div class="small text-muted">{{ $specialty->procedures_count }} {{ $specialty->procedures_count === 1 ? 'procedimiento' : 'procedimientos' }}</div>
                    </div>
                    @if($canEdit)
                        <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#sp-{{ $specialty->id }}" title="Editar"><i class="bi bi-pencil"></i></button>
                        <form method="POST" action="{{ route('specialties.destroy', $specialty) }}" onsubmit="return confirm('{{ $specialty->procedures_count ? '¿Desactivar ' . e($specialty->name) . '? Tiene procedimientos.' : '¿Eliminar ' . e($specialty->name) . '?' }}')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-light text-danger" title="{{ $specialty->procedures_count ? 'Desactivar' : 'Eliminar' }}"><i class="bi bi-trash"></i></button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
</div>

@if($canEdit)
    @foreach($modals as $modal)
        @php
            $s = $modal['specialty'];
            $reopen = $errors->any() && old('_modal') === $modal['id'];
        @endphp
        <div class="modal fade" id="{{ $modal['id'] }}" tabindex="-1" aria-hidden="true" @if($reopen) data-reopen @endif>
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="POST" action="{{ $s ? route('specialties.update', $s) : route('specialties.store') }}">
                    @csrf
                    @if($s) @method('PUT') @endif
                    <input type="hidden" name="_modal" value="{{ $modal['id'] }}">
                    <div class="modal-header"><h5 class="modal-title">{{ $s ? 'Editar especialidad' : 'Nueva especialidad' }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                    <div class="modal-body row g-3">
                        <div class="col-9">
                            <label class="form-label" for="{{ $modal['id'] }}-name">Nombre</label>
                            <input type="text" id="{{ $modal['id'] }}-name" name="name" class="form-control" maxlength="80" value="{{ $reopen ? old('name') : $s?->name }}" required>
                        </div>
                        <div class="col-3">
                            <label class="form-label" for="{{ $modal['id'] }}-color">Color</label>
                            <input type="color" id="{{ $modal['id'] }}-color" name="color" class="form-control form-control-color w-100" value="{{ $reopen ? old('color') : ($s?->color ?? '#0e9f9a') }}">
                        </div>
                        @if($s)
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" name="active" value="1" id="{{ $modal['id'] }}-active" @checked($reopen ? old('active') : $s->active)>
                                    <label class="form-check-label" for="{{ $modal['id'] }}-active">Activa</label>
                                </div>
                            </div>
                        @endif
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Guardar</button></div>
                </form>
            </div>
        </div>
    @endforeach
@endif
@include('admin.finance.partials.styles')
@endsection
