@extends('layouts.app')

@section('title', 'Mi suscripción')

@section('page')
<div class="container-fluid" style="max-width: 1100px;">
    <div class="page-header">
        <div>
            <h1 class="mb-1"><i class="bi bi-patch-check"></i> Mi suscripción</h1>
            <p class="text-muted mb-0">Estado del plan de {{ $company->displayName() }}.</p>
        </div>
    </div>

    @if(!$subscription)
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-exclamation-circle text-muted" style="font-size: 2.5rem;"></i>
                <h5 class="mt-3 fw-semibold">Sin suscripción asignada</h5>
                <p class="text-muted mb-0">Contacta a soporte para activar un plan para tu clínica.</p>
                @include('partials.support-contact')
            </div>
        </div>
    @else
        @php $daysLeft = $subscription->daysRemaining(); @endphp

        <div class="row g-4">
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <div class="text-muted small">Plan actual</div>
                                <div class="fs-4 fw-bold">{{ $subscription->plan?->name ?? '-' }}</div>
                            </div>
                            <span class="badge bg-{{ $subscription->statusColor() }}">{{ $subscription->statusLabel() }}</span>
                        </div>

                        <p class="mb-1"><strong>Precio:</strong> {{ $subscription->currency }} {{ number_format($subscription->price, 2) }} / {{ $subscription->plan?->periodLabel() }}</p>
                        <p class="mb-1"><strong>Inicio:</strong> {{ $subscription->starts_at?->format('d/m/Y') }}</p>
                        <p class="mb-1">
                            <strong>{{ $subscription->status === 'trial' ? 'Prueba hasta' : 'Vence' }}:</strong> {{ $subscription->periodEnd()?->format('d/m/Y') ?? 'Sin vencimiento' }}
                            @if($daysLeft !== null)
                                <span class="text-muted">({{ $daysLeft }} {{ Str::plural('día', $daysLeft) }} restantes)</span>
                            @endif
                        </p>

                        @if($subscription->inGrace())
                            <div class="alert alert-danger small mt-2 mb-0">Solo lectura hasta el {{ $subscription->graceEndsAt()?->format('d/m/Y') }}. Renueva para volver a registrar cambios.</div>
                        @endif

                        @if($subscription->effectiveFeatures())
                            <hr>
                            <div class="text-muted small mb-2">Módulos incluidos</div>
                            @foreach($subscription->effectiveFeatures() as $feature)
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle me-1 mb-1">{{ \App\Models\Plan::moduleLabel($feature) }}</span>
                            @endforeach
                        @endif

                        @include('partials.support-contact')
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body">
                        <h6 class="fw-bold mb-3">Consumo del plan</h6>
                        @foreach($usage as $item)
                            <div class="mb-3">
                                <div class="d-flex justify-content-between small mb-1">
                                    <span>{{ $item['label'] }}</span>
                                    <span class="text-muted">{{ $item['used'] }} / {{ $item['limit'] ?? 'Ilimitado' }}</span>
                                </div>
                                @if($item['limit'])
                                    <div class="progress" style="height: 7px;">
                                        <div class="progress-bar {{ $item['percent'] >= 100 ? 'bg-danger' : ($item['percent'] >= 80 ? 'bg-warning' : '') }}"
                                             role="progressbar" style="width: {{ $item['percent'] }}%"
                                             aria-valuenow="{{ $item['percent'] }}" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-body p-0">
                        <div class="p-3 border-bottom"><h6 class="fw-bold mb-0">Historial de pagos</h6></div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-3">Fecha</th>
                                        <th>Monto</th>
                                        <th>Método</th>
                                        <th>Referencia</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($payments as $payment)
                                        <tr>
                                            <td class="ps-3">{{ $payment->paid_at?->format('d/m/Y') }}</td>
                                            <td class="fw-semibold">{{ $payment->currency }} {{ number_format($payment->amount, 2) }}</td>
                                            <td>{{ $payment->methodLabel() }}</td>
                                            <td><small class="text-muted">{{ $payment->reference ?: '-' }}</small></td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="text-center py-4 text-muted">Sin pagos registrados.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
