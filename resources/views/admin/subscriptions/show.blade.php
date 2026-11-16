@extends('layouts.app')

@section('title', 'Suscripción de ' . ($subscription->company?->name ?? ''))

@section('page')
@php $daysLeft = $subscription->daysRemaining(); @endphp

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h1 class="mb-1">{{ $subscription->company?->name ?? 'Suscripción' }}</h1>
            <p class="text-muted mb-0">
                Plan {{ $subscription->plan?->name }} ·
                <span class="badge bg-{{ $subscription->statusColor() }}">{{ $subscription->statusLabel() }}</span>
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('subscriptions.edit', $subscription) }}" class="btn btn-primary"><i class="bi bi-pencil"></i> Editar</a>
            <a href="{{ route('subscriptions.index') }}" class="btn btn-outline-secondary">Volver</a>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">Vigencia</h6>
                    <p class="mb-1"><strong>Inicio:</strong> {{ $subscription->starts_at?->format('d/m/Y') }}</p>
                    <p class="mb-1">
                        <strong>{{ $subscription->status === 'trial' ? 'Prueba hasta' : 'Vence' }}:</strong>
                        {{ $subscription->periodEnd()?->format('d/m/Y') ?? 'Sin vencimiento' }}
                        @if($daysLeft !== null)
                            <span class="text-muted">({{ $daysLeft }} {{ Str::plural('día', $daysLeft) }})</span>
                        @endif
                    </p>
                    <p class="mb-1">
                        <strong>Gracia:</strong> {{ $subscription->grace_days }} {{ Str::plural('día', $subscription->grace_days) }}
                        @if($subscription->graceEndsAt())<span class="text-muted">(solo lectura hasta {{ $subscription->graceEndsAt()->format('d/m/Y') }})</span>@endif
                    </p>
                    <p class="mb-1"><strong>Acceso hoy:</strong>
                        @if($subscription->allowsWrite()) <span class="text-success">completo</span>
                        @elseif($subscription->allowsRead()) <span class="text-warning">solo lectura</span>
                        @else <span class="text-danger">bloqueado</span>
                        @endif
                    </p>
                    <p class="mb-1"><strong>Precio pactado:</strong> {{ $subscription->currency }} {{ number_format($subscription->price, 2) }}</p>
                    <p class="mb-1"><strong>Renovación automática:</strong> {{ $subscription->auto_renew ? 'Sí' : 'No' }}</p>
                    <p class="mb-1"><strong>Creada por:</strong> {{ $subscription->creator?->name ?? 'Sistema' }}</p>
                    @if($subscription->notes)
                        <hr>
                        <p class="mb-0 text-muted small">{{ $subscription->notes }}</p>
                    @endif

                    <hr>
                    <div class="d-flex gap-2 flex-wrap">
                        <form action="{{ route('subscriptions.renew', $subscription) }}" method="POST" class="d-flex gap-2">
                            @csrf
                            <input type="number" name="periods" min="1" max="24" value="1" class="form-control form-control-sm" style="width: 80px;">
                            <button class="btn btn-sm btn-success"><i class="bi bi-arrow-clockwise"></i> Renovar</button>
                        </form>
                        @if($subscription->status !== 'cancelled')
                            <form action="{{ route('subscriptions.cancel', $subscription) }}" method="POST" onsubmit="return confirm('¿Cancelar esta suscripción?')">
                                @csrf
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i> Cancelar</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold mb-0">Módulos y cupos</h6>
                        <a href="{{ route('subscriptions.edit', $subscription) }}" class="small">Personalizar</a>
                    </div>
                    <div class="mb-3">
                        @forelse($subscription->effectiveFeatures() as $feature)
                            <span class="badge bg-light text-dark border mb-1">{{ \App\Models\Plan::moduleLabel($feature) }}</span>
                        @empty
                            <span class="text-muted small">Sin módulos.</span>
                        @endforelse
                        @if($subscription->features_override !== null)
                            <div class="small text-warning mt-1"><i class="bi bi-sliders"></i> Módulos personalizados para esta clínica.</div>
                        @endif
                    </div>
                    @foreach($usage as $item)
                        <div class="d-flex justify-content-between small text-muted">
                            <span>{{ $item['label'] }} @if($item['overridden'])<span class="badge bg-warning text-dark">personalizado</span>@endif</span>
                            <span>{{ $item['used'] }} / {{ $item['limit'] ?? '∞' }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">Registrar pago</h6>
                    <form action="{{ route('subscriptions.payments.store', $subscription) }}" method="POST" class="row g-2">
                        @csrf
                        <div class="col-6">
                            <label class="form-label small">Monto <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0" name="amount" class="form-control form-control-sm" value="{{ old('amount', $subscription->price) }}" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small">Método <span class="text-danger">*</span></label>
                            <select name="method" class="form-select form-select-sm" required>
                                @foreach(\App\Models\SubscriptionPayment::METHODS as $value => $label)
                                    <option value="{{ $value }}" {{ old('method') === $value ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small">Fecha de pago <span class="text-danger">*</span></label>
                            <input type="date" name="paid_at" class="form-control form-control-sm" value="{{ old('paid_at', today()->toDateString()) }}" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small">Referencia</label>
                            <input type="text" name="reference" class="form-control form-control-sm" value="{{ old('reference') }}">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">Período desde</label>
                            <input type="date" name="period_start" class="form-control form-control-sm" value="{{ old('period_start') }}">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">Período hasta</label>
                            <input type="date" name="period_end" class="form-control form-control-sm" value="{{ old('period_end') }}">
                        </div>
                        <div class="col-12">
                            <label class="form-label small">Notas</label>
                            <textarea name="notes" rows="2" class="form-control form-control-sm">{{ old('notes') }}</textarea>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="renew_after_payment" name="renew_after_payment" value="1" checked>
                                <label class="form-check-label small" for="renew_after_payment">Renovar un período al registrar el pago</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-sm btn-primary w-100"><i class="bi bi-cash-coin"></i> Registrar pago</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
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
                                    <th>Período</th>
                                    <th>Registró</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($subscription->payments->sortByDesc('paid_at') as $payment)
                                    <tr>
                                        <td class="ps-3">{{ $payment->paid_at?->format('d/m/Y') }}</td>
                                        <td class="fw-semibold">{{ $payment->currency }} {{ number_format($payment->amount, 2) }}</td>
                                        <td>
                                            {{ $payment->methodLabel() }}
                                            @if($payment->reference)<div><small class="text-muted">{{ $payment->reference }}</small></div>@endif
                                        </td>
                                        <td>
                                            @if($payment->period_start || $payment->period_end)
                                                <small>{{ $payment->period_start?->format('d/m/Y') ?? '—' }} → {{ $payment->period_end?->format('d/m/Y') ?? '—' }}</small>
                                            @else
                                                <span class="text-muted">-</span>
                                            @endif
                                        </td>
                                        <td><small class="text-muted">{{ $payment->registeredBy?->name ?? '-' }}</small></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center py-5 text-muted">Todavía no hay pagos registrados.</td></tr>
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
