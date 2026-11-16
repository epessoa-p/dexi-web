@extends('layouts.app')

@section('title', $company->name)

@section('page')
<div class="page-header">
    <h1 class="mb-0"><i class="bi bi-hospital"></i> {{ $company->name }}</h1>
    <div class="d-flex gap-2">
        @if($company->active)
            <form action="{{ route('set-company', $company->id) }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-primary"><i class="bi bi-box-arrow-in-right"></i> Entrar a esta clínica</button>
            </form>
        @endif
        <a href="{{ route('companies.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h6 class="fw-bold mb-3">Información</h6>
                <p class="mb-1"><strong>RUC / NIT:</strong> {{ $company->ruc ?? '-' }}</p>
                <p class="mb-1"><strong>Email:</strong> {{ $company->email ?? '-' }}</p>
                <p class="mb-1"><strong>Teléfono:</strong> {{ $company->phone ?? '-' }}</p>
                <p class="mb-1"><strong>Dirección:</strong> {{ $company->address ?? '-' }}</p>
                <p class="mb-0">
                    <strong>Estado:</strong>
                    <span class="badge {{ $company->active ? 'bg-success' : 'bg-danger' }}">{{ $company->active ? 'Activa' : 'Inactiva' }}</span>
                </p>

                <hr>
                <div class="d-flex gap-2">
                    <a href="{{ route('companies.edit', $company) }}" class="btn btn-sm btn-primary"><i class="bi bi-pencil"></i> Editar</a>
                    <form action="{{ route('companies.destroy', $company) }}" method="POST" onsubmit="return confirm('¿Eliminar esta clínica?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Eliminar</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <h6 class="fw-bold mb-0">Suscripción</h6>
                    @if($subscription)
                        <span class="badge bg-{{ $subscription->statusColor() }}">{{ $subscription->statusLabel() }}</span>
                    @endif
                </div>

                @if($subscription)
                    @php $daysLeft = $subscription->daysRemaining(); @endphp
                    <p class="mb-1"><strong>Plan:</strong> {{ $subscription->plan?->name }}</p>
                    <p class="mb-1"><strong>Precio:</strong> {{ $subscription->currency }} {{ number_format($subscription->price, 2) }} / {{ $subscription->plan?->periodLabel() }}</p>
                    <p class="mb-1">
                        <strong>{{ $subscription->status === 'trial' ? 'Prueba hasta' : 'Vence' }}:</strong> {{ $subscription->periodEnd()?->format('d/m/Y') ?? 'Sin vencimiento' }}
                        @if($daysLeft !== null)<span class="text-muted">({{ $daysLeft }} {{ Str::plural('día', $daysLeft) }})</span>@endif
                    </p>
                    <p class="mb-1"><strong>Gracia (solo lectura):</strong> {{ $subscription->grace_days }} {{ Str::plural('día', $subscription->grace_days) }}</p>
                    <p class="mb-1">
                        <strong>Módulos:</strong>
                        @forelse($company->effectiveFeatures() as $feature)
                            <span class="badge bg-light text-dark border">{{ \App\Models\Plan::moduleLabel($feature) }}</span>
                        @empty
                            <span class="text-muted">ninguno</span>
                        @endforelse
                        @if($subscription->features_override !== null)<span class="badge bg-warning text-dark">personalizados</span>@endif
                    </p>

                    <hr>
                    <div class="mb-3">
                        @foreach($usage as $item)
                            <div class="d-flex justify-content-between small text-muted">
                                <span>{{ $item['label'] }} @if($item['overridden'])<span class="badge bg-warning text-dark">personalizado</span>@endif</span>
                                <span>{{ $item['used'] }} / {{ $item['limit'] ?? '∞' }}</span>
                            </div>
                        @endforeach
                    </div>

                    <div class="d-flex gap-2">
                        <a href="{{ route('subscriptions.show', $subscription) }}" class="btn btn-sm btn-outline-primary">Gestionar suscripción</a>
                        <a href="{{ route('subscriptions.edit', $subscription) }}" class="btn btn-sm btn-outline-secondary" title="Plan, fechas, cupos y módulos de esta clínica"><i class="bi bi-sliders"></i> Plan y cupos</a>
                        <form action="{{ route('subscriptions.renew', $subscription) }}" method="POST">
                            @csrf
                            <button class="btn btn-sm btn-outline-success"><i class="bi bi-arrow-clockwise"></i> Renovar</button>
                        </form>
                    </div>
                @else
                    <p class="text-muted">Esta clínica no tiene ninguna suscripción asignada.</p>
                    <a href="{{ route('subscriptions.create', ['company_id' => $company->id]) }}" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus-lg"></i> Asignar suscripción
                    </a>
                @endif
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="p-3 border-bottom"><h6 class="fw-bold mb-0">Usuarios de la clínica</h6></div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Nombre</th>
                                <th>Email</th>
                                <th>Rol</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($users as $user)
                                <tr>
                                    <td class="ps-3">{{ $user->name }}</td>
                                    <td>{{ $user->email }}</td>
                                    <td>{{ $roles[$user->pivot->role_id] ?? '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-center py-4 text-muted">No hay usuarios en esta clínica.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white">{{ $users->links() }}</div>
        </div>
    </div>
</div>
@endsection
