@extends('layouts.app')

@section('title', 'Planes')

@section('page')
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h1><i class="bi bi-box-seam"></i> Planes</h1>
            <p class="page-subtitle">Lo que vende Dexi: módulos incluidos, cupos y precio de cada plan.</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('plans.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nuevo plan</a>
        </div>
    </div>

    <div class="row g-3">
        @forelse($plans as $plan)
            <div class="col-md-6 col-xl-4">
                <div class="card h-100 {{ $plan->active ? '' : 'opacity-75' }}">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <h2 class="h5 mb-0">{{ $plan->name }}</h2>
                                <small class="text-muted"><code>{{ $plan->slug }}</code></small>
                            </div>
                            <span class="badge {{ $plan->active ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary' }}">{{ $plan->active ? 'Activo' : 'Inactivo' }}</span>
                        </div>
                        <div class="mb-2">
                            <span class="fs-3 fw-bold tabular">{{ $plan->currency }} {{ number_format($plan->price, 2) }}</span>
                            <span class="text-muted">/ {{ mb_strtolower($plan->periodLabel()) }}</span>
                        </div>
                        @if($plan->description)<p class="small text-muted">{{ $plan->description }}</p>@endif
                        <div class="small mb-3 d-flex flex-wrap gap-3 text-muted">
                            <span><i class="bi bi-person-gear"></i> {{ $plan->max_users ?? '∞' }} usuarios</span>
                            <span><i class="bi bi-person-vcard"></i> {{ $plan->max_personal ?? '∞' }} personal</span>
                            <span><i class="bi bi-diagram-2"></i> {{ $plan->max_branches ?? '∞' }} consultorios</span>
                            <span><i class="bi bi-hourglass-split"></i> {{ $plan->trial_days }} {{ Str::plural('día', $plan->trial_days) }} de prueba</span>
                        </div>
                        <div class="d-flex flex-wrap gap-1 mb-3">
                            @foreach(\App\Models\Plan::MODULES as $key => $label)
                                @if($plan->allows($key))
                                    <span class="badge bg-light text-dark border fw-normal"><i class="bi bi-check2 text-success"></i> {{ $label }}</span>
                                @endif
                            @endforeach
                        </div>
                        <div class="mt-auto d-flex justify-content-between align-items-center pt-2 border-top">
                            <span class="small text-muted">{{ $plan->active_subscriptions_count }} {{ $plan->active_subscriptions_count === 1 ? 'clínica' : 'clínicas' }}</span>
                            <span class="text-nowrap">
                                <a href="{{ route('plans.show', $plan) }}" class="btn btn-sm btn-light" title="Ver"><i class="bi bi-eye"></i></a>
                                <a href="{{ route('plans.edit', $plan) }}" class="btn btn-sm btn-light" title="Editar"><i class="bi bi-pencil"></i></a>
                                <form action="{{ route('plans.destroy', $plan) }}" method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar el plan {{ e($plan->name) }}?')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-light text-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
                                </form>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12">
                <div class="card"><div class="empty-state">
                    <div class="empty-icon"><i class="bi bi-box-seam"></i></div>
                    <h5 class="mb-1">Todavía no hay planes</h5>
                    <p class="small mb-3">Crea el primero para poder asignar suscripciones.</p>
                    <a href="{{ route('plans.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nuevo plan</a>
                </div></div>
            </div>
        @endforelse
    </div>
    <div class="mt-3 d-flex justify-content-center">{{ $plans->links() }}</div>
</div>
@endsection
