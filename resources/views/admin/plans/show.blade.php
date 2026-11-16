@extends('layouts.app')

@section('title', 'Plan ' . $plan->name)

@section('page')
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h1 class="mb-1">{{ $plan->name }}</h1>
            <p class="text-muted mb-0">{{ $plan->description ?: 'Sin descripción.' }}</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('plans.edit', $plan) }}" class="btn btn-primary"><i class="bi bi-pencil"></i> Editar</a>
            <a href="{{ route('plans.index') }}" class="btn btn-outline-secondary">Volver</a>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">Condiciones</h6>
                    <p class="mb-1"><strong>Precio:</strong> {{ $plan->currency }} {{ number_format($plan->price, 2) }}</p>
                    <p class="mb-1"><strong>Período:</strong> {{ $plan->periodLabel() }}</p>
                    <p class="mb-1"><strong>Prueba:</strong> {{ $plan->trial_days }} {{ Str::plural('día', $plan->trial_days) }}</p>
                    <p class="mb-1"><strong>Identificador:</strong> <code>{{ $plan->slug }}</code></p>
                    <p class="mb-0">
                        <strong>Estado:</strong>
                        <span class="badge {{ $plan->active ? 'bg-success' : 'bg-secondary' }}">{{ $plan->active ? 'Activo' : 'Inactivo' }}</span>
                    </p>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">Límites</h6>
                    <p class="mb-1"><strong>Usuarios:</strong> {{ $plan->max_users ?? 'Ilimitado' }}</p>
                    <p class="mb-1"><strong>Personal:</strong> {{ $plan->max_personal ?? 'Ilimitado' }}</p>
                    <p class="mb-0"><strong>Consultorios:</strong> {{ $plan->max_branches ?? 'Ilimitado' }}</p>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">Funcionalidades</h6>
                    @forelse($plan->features ?? [] as $feature)
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle me-1 mb-1">{{ $feature }}</span>
                    @empty
                        <p class="text-muted mb-0">Sin funcionalidades marcadas.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <div class="p-3 border-bottom"><h6 class="fw-bold mb-0">Clínicas con este plan</h6></div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Clínica</th>
                                    <th>Estado</th>
                                    <th>Inicio</th>
                                    <th>Vence</th>
                                    <th class="text-end pe-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($plan->subscriptions as $subscription)
                                    <tr>
                                        <td class="ps-3">{{ $subscription->company?->name ?? '-' }}</td>
                                        <td><span class="badge bg-{{ $subscription->statusColor() }}">{{ $subscription->statusLabel() }}</span></td>
                                        <td>{{ $subscription->starts_at?->format('d/m/Y') }}</td>
                                        <td>{{ $subscription->periodEnd()?->format('d/m/Y') ?? 'Sin vencimiento' }}</td>
                                        <td class="text-end pe-3">
                                            <a href="{{ route('subscriptions.show', $subscription) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center py-4 text-muted">Ninguna clínica tiene este plan todavía.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
