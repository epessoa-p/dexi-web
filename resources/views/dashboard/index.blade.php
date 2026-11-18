@extends('layouts.app')

@section('title', 'Dashboard')

@section('page')
@php
    $hour = (int) now()->format('H');
    $greeting = $hour < 12 ? 'Buenos días' : ($hour < 19 ? 'Buenas tardes' : 'Buenas noches');
    $firstName = \Illuminate\Support\Str::of(auth()->user()->name)->before(' ')->ucfirst();
@endphp
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h1>{{ $greeting }}, {{ $firstName }}</h1>
            <p class="page-subtitle">
                {{ $isGlobal ? 'Resumen de la plataforma: clínicas, suscripciones y cobros.' : 'Así va ' . ($company?->displayName() ?? 'tu clínica') . ' hoy, ' . now()->locale('es')->translatedFormat('l j \d\e F') . '.' }}
            </p>
        </div>
    </div>

    @if($isGlobal)
        <div class="row g-3 mb-4">
            @foreach([
                ['value' => $metrics['companies'], 'label' => 'Clínicas activas', 'hint' => $metrics['users'] . ' usuarios en total', 'icon' => 'bi-hospital', 'tone' => '#0e9f9a'],
                ['value' => $metrics['subscriptions'], 'label' => 'Suscripciones vigentes', 'hint' => $metrics['trials'] . ' en prueba', 'icon' => 'bi-patch-check', 'tone' => '#0284c7'],
                ['value' => $metrics['expiring'], 'label' => 'Vencen en 30 días', 'hint' => $metrics['expired'] . ' ya vencidas', 'icon' => 'bi-clock-history', 'tone' => '#ea580c'],
                ['value' => number_format($metrics['revenue'], 2), 'label' => 'Cobrado este mes', 'hint' => 'Pagos de suscripción', 'icon' => 'bi-cash-coin', 'tone' => '#7c3aed'],
            ] as $card)
                <div class="col-xl-3 col-sm-6">
                    <div class="card kpi-card">
                        <div class="kpi-icon" style="--tone: {{ $card['tone'] }}"><i class="bi {{ $card['icon'] }}"></i></div>
                        <div class="min-w-0">
                            <div class="kpi-label">{{ $card['label'] }}</div>
                            <div class="kpi-value">{{ $card['value'] }}</div>
                            <div class="kpi-hint">{{ $card['hint'] }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history"></i> Suscripciones por vencer</span>
                <a href="{{ route('subscriptions.index', ['filter' => 'expiring']) }}" class="btn btn-sm btn-light">Ver todas</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle table-stack">
                    <thead><tr><th class="ps-3">Clínica</th><th>Plan</th><th>Estado</th><th>Vence</th><th class="text-end pe-3"></th></tr></thead>
                    <tbody>
                        @forelse($expiringSoon as $subscription)
                            @php $daysLeft = $subscription->daysRemaining(); @endphp
                            <tr>
                                <td class="ps-3 fw-semibold" data-label="Clínica">{{ $subscription->company?->name }}</td>
                                <td data-label="Plan">{{ $subscription->plan?->name }}</td>
                                <td data-label="Estado"><span class="badge bg-{{ $subscription->statusColor() }}">{{ $subscription->statusLabel() }}</span></td>
                                <td data-label="Vence">
                                    {{ $subscription->periodEnd()?->format('d/m/Y') }}
                                    <small class="text-warning-emphasis d-block">{{ $daysLeft }} {{ Str::plural('día', $daysLeft) }}</small>
                                </td>
                                <td class="text-end pe-3">
                                    <a href="{{ route('subscriptions.show', $subscription) }}" class="btn btn-sm btn-light" title="Ver"><i class="bi bi-eye"></i></a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="stack-full">
                                <div class="empty-state py-4">
                                    <div class="empty-icon"><i class="bi bi-emoji-smile"></i></div>
                                    <h5 class="mb-1">Todo en orden</h5>
                                    <p class="small mb-0">Ninguna suscripción vence en los próximos 30 días.</p>
                                </div>
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        @php
            $can = fn (string $permission) => auth()->user()->hasPermissionInCompany($permission, $company);
            $money = fn ($v) => \App\Support\Money::format($v, $company);
            // Accesos rápidos: solo los que el plan y el rol permiten.
            $actions = array_filter([
                isset($finance['income_today']) && $can('sales.create') ? ['Cobrar', 'bi-cart-plus', route('sales.create'), '#059669'] : null,
                $today && $can('appointments.create') ? ['Agendar cita', 'bi-calendar-plus', route('appointments.create'), '#7c3aed'] : null,
                $messages !== null ? [$messages ? "WhatsApp por enviar ({$messages})" : 'Comunicaciones', 'bi-whatsapp', route('communications.index'), '#25a244'] : null,
                $can('patients.create') ? ['Nuevo paciente', 'bi-person-plus', route('patients.create'), '#2563eb'] : null,
                $can('patients.view') ? ['Buscar paciente', 'bi-search', route('patients.index'), '#0e9f9a'] : null,
                Route::has('expenses.create') && isset($finance['expenses_month']) && $can('expenses.create')
                    ? ['Registrar gasto', 'bi-wallet2', route('expenses.create'), '#ca8a04'] : null,
                isset($finance['basics_pending']) && $can('expenses.create')
                    ? ['Pagar un servicio', 'bi-lightning-charge', route('expenses.index', ['tab' => 'basico']), '#ea580c'] : null,
                isset($finance['cash_open']) ? ['Caja', 'bi-cash-coin', route('cash.index'), '#059669'] : null,
                isset($finance['accounts_total']) ? ['Cuentas', 'bi-bank', route('finance-accounts.index'), '#2563eb'] : null,
                $can('personal.view') ? ['Equipo', 'bi-person-vcard', route('personal.index'), '#4f46e5'] : null,
                $can('settings.view') ? ['Datos de la clínica', 'bi-hospital', route('settings.edit'), '#475569'] : null,
            ]);
        @endphp

        @if($today)
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span><i class="bi bi-calendar-day"></i> Agenda de hoy
                        <span class="text-muted fw-normal small ms-1">{{ $today['total'] }} {{ $today['total'] === 1 ? 'cita' : 'citas' }} · {{ $today['done'] }} atendidas{{ $today['waiting'] ? ' · ' . $today['waiting'] . ' en sala de espera' : '' }}</span>
                    </span>
                    <span class="d-flex gap-2">
                        <a href="{{ route('appointments.today') }}" class="btn btn-sm btn-light"><i class="bi bi-hourglass-split"></i> Sala de espera</a>
                        @if($can('appointments.create'))<a href="{{ route('appointments.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-calendar-plus"></i> Nueva cita</a>@endif
                    </span>
                </div>
                @if($today['next']->isEmpty())
                    <div class="card-body text-muted small">{{ $today['total'] ? 'No quedan citas pendientes por hoy.' : 'No hay citas para hoy.' }}</div>
                @else
                    <ul class="list-group list-group-flush">
                        @foreach($today['next'] as $a)
                            <li class="list-group-item d-flex align-items-center gap-3">
                                <span class="fw-bold tabular" style="min-width:3rem">{{ $a->starts_at->format('H:i') }}</span>
                                <span class="d-inline-block rounded-circle flex-shrink-0" style="width:.6rem;height:.6rem;background: {{ $a->professional?->color }}"></span>
                                <a href="{{ route('appointments.show', $a) }}" class="flex-fill text-decoration-none text-reset min-w-0">
                                    <span class="fw-semibold">@if($a->patient?->hasAlerts())<i class="bi bi-exclamation-triangle-fill text-danger"></i> @endif{{ $a->patient?->full_name }}</span>
                                    <span class="small text-muted d-block text-truncate">{{ $a->title }} · {{ $a->professional?->full_name }}</span>
                                </a>
                                <span class="badge bg-{{ $a->status_color }}">{{ $a->status_label }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        @if($finance || $patients)
            <div class="row g-3 mb-4">
                @if($patients)
                    <div class="col-xl-3 col-sm-6">
                        <a href="{{ route('patients.index') }}" class="card kpi-card text-decoration-none text-reset">
                            <div class="kpi-icon" style="--tone:#2563eb"><i class="bi bi-people"></i></div>
                            <div class="min-w-0">
                                <div class="kpi-label">Pacientes activos</div>
                                <div class="kpi-value">{{ number_format($patients['total']) }}</div>
                                <div class="kpi-hint">{{ $patients['new_month'] }} {{ $patients['new_month'] === 1 ? 'nuevo' : 'nuevos' }} este mes</div>
                            </div>
                        </a>
                    </div>
                @endif
                @isset($finance['income_today'])
                    <div class="col-xl-3 col-sm-6">
                        <a href="{{ route('sales.index') }}" class="card kpi-card text-decoration-none text-reset">
                            <div class="kpi-icon" style="--tone:#059669"><i class="bi bi-cash-coin"></i></div>
                            <div class="min-w-0">
                                <div class="kpi-label">Cobrado hoy</div>
                                <div class="kpi-value">{{ $money($finance['income_today']) }}</div>
                                <div class="kpi-hint">{{ $money($finance['income_month']) }} en el mes</div>
                            </div>
                        </a>
                    </div>
                    <div class="col-xl-3 col-sm-6">
                        <a href="{{ route('receivables.index') }}" class="card kpi-card text-decoration-none text-reset">
                            <div class="kpi-icon" style="--tone:#dc3545"><i class="bi bi-hourglass-split"></i></div>
                            <div class="min-w-0">
                                <div class="kpi-label">Por cobrar</div>
                                <div class="kpi-value">{{ $money($finance['receivable']) }}</div>
                                <div class="kpi-hint">saldos de pacientes</div>
                            </div>
                        </a>
                    </div>
                @endisset
                @isset($finance['expenses_month'])
                    <div class="col-xl-3 col-sm-6">
                        <a href="{{ route('expenses.index', ['tab' => 'todos']) }}" class="card kpi-card text-decoration-none text-reset">
                            <div class="kpi-icon" style="--tone:#ca8a04"><i class="bi bi-wallet2"></i></div>
                            <div class="min-w-0">
                                <div class="kpi-label">Gastos de {{ now()->locale('es')->translatedFormat('F') }}</div>
                                <div class="kpi-value">{{ $money($finance['expenses_month']) }}</div>
                                <div class="kpi-hint">{{ $finance['expenses_count'] }} {{ $finance['expenses_count'] === 1 ? 'gasto' : 'gastos' }} registrados</div>
                            </div>
                        </a>
                    </div>
                    <div class="col-xl-3 col-sm-6">
                        <a href="{{ route('expenses.index', ['tab' => 'basico']) }}" class="card kpi-card text-decoration-none text-reset">
                            <div class="kpi-icon" style="--tone: {{ $finance['basics_pending'] ? '#ea580c' : '#059669' }}"><i class="bi {{ $finance['basics_pending'] ? 'bi-lightning-charge' : 'bi-check2-circle' }}"></i></div>
                            <div class="min-w-0">
                                <div class="kpi-label">Servicios básicos del mes</div>
                                <div class="kpi-value">{{ $finance['basics_pending'] ? $finance['basics_pending'] . ' pendientes' : 'Al día' }}</div>
                                <div class="kpi-hint">{{ $finance['basics_total'] - $finance['basics_pending'] }} de {{ $finance['basics_total'] }} pagados</div>
                            </div>
                        </a>
                    </div>
                @endisset
                @isset($finance['accounts_total'])
                    <div class="col-xl-3 col-sm-6">
                        <a href="{{ route('finance-accounts.index') }}" class="card kpi-card text-decoration-none text-reset">
                            <div class="kpi-icon" style="--tone:#2563eb"><i class="bi bi-bank"></i></div>
                            <div class="min-w-0">
                                <div class="kpi-label">Saldo en cuentas</div>
                                <div class="kpi-value">{{ $money($finance['accounts_total']) }}</div>
                                <div class="kpi-hint">{{ $finance['accounts_count'] }} {{ $finance['accounts_count'] === 1 ? 'cuenta activa' : 'cuentas activas' }}</div>
                            </div>
                        </a>
                    </div>
                @endisset
                @isset($finance['cash_open'])
                    <div class="col-xl-3 col-sm-6">
                        <a href="{{ route('cash.index') }}" class="card kpi-card text-decoration-none text-reset">
                            <div class="kpi-icon" style="--tone:#059669"><i class="bi bi-cash-coin"></i></div>
                            <div class="min-w-0">
                                <div class="kpi-label">{{ $finance['cash_open'] ? 'Efectivo en cajas abiertas' : 'Caja' }}</div>
                                <div class="kpi-value">{{ $finance['cash_open'] ? $money($finance['cash_expected']) : 'Cerrada' }}</div>
                                <div class="kpi-hint">{{ $finance['cash_open'] ? $finance['cash_open'] . ' ' . ($finance['cash_open'] === 1 ? 'turno abierto' : 'turnos abiertos') : 'Ábrela para cobrar y pagar en efectivo' }}</div>
                            </div>
                        </a>
                    </div>
                @endisset
            </div>
        @endif

        @if($lab && ($lab['late'] || $lab['today'] || $lab['install']))
            <div class="d-flex flex-wrap gap-2 mb-3">
                @if($lab['late'])
                    <a href="{{ route('lab-orders.index', ['filter' => 'atrasados']) }}" class="alert alert-danger d-flex align-items-center gap-2 mb-0 py-2 text-decoration-none flex-fill">
                        <i class="bi bi-gem"></i> <span><strong>{{ $lab['late'] }}</strong> {{ $lab['late'] === 1 ? 'trabajo de laboratorio atrasado' : 'trabajos de laboratorio atrasados' }}</span> <i class="bi bi-chevron-right ms-auto"></i>
                    </a>
                @endif
                @if($lab['today'])
                    <a href="{{ route('lab-orders.index') }}" class="alert alert-info d-flex align-items-center gap-2 mb-0 py-2 text-decoration-none flex-fill">
                        <i class="bi bi-truck"></i> <span><strong>{{ $lab['today'] }}</strong> {{ $lab['today'] === 1 ? 'trabajo llega' : 'trabajos llegan' }} hoy del laboratorio</span> <i class="bi bi-chevron-right ms-auto"></i>
                    </a>
                @endif
                @if($lab['install'])
                    <a href="{{ route('lab-orders.index', ['filter' => 'recibidos']) }}" class="alert alert-primary d-flex align-items-center gap-2 mb-0 py-2 text-decoration-none flex-fill">
                        <i class="bi bi-box-arrow-in-down"></i> <span><strong>{{ $lab['install'] }}</strong> por instalar: agenda al paciente</span> <i class="bi bi-chevron-right ms-auto"></i>
                    </a>
                @endif
            </div>
        @endif

        @if($inventory && ($inventory['low'] || $inventory['expiring']))
            <div class="d-flex flex-wrap gap-2 mb-4">
                @if($inventory['low'])
                    <a href="{{ route('products.index', ['filter' => 'bajo']) }}" class="alert alert-warning d-flex align-items-center gap-2 mb-0 py-2 text-decoration-none flex-fill">
                        <i class="bi bi-box-seam"></i> <span><strong>{{ $inventory['low'] }}</strong> {{ $inventory['low'] === 1 ? 'producto está' : 'productos están' }} en o bajo el mínimo</span> <i class="bi bi-chevron-right ms-auto"></i>
                    </a>
                @endif
                @if($inventory['expiring'])
                    <a href="{{ route('products.index', ['filter' => 'vencer']) }}" class="alert alert-danger d-flex align-items-center gap-2 mb-0 py-2 text-decoration-none flex-fill">
                        <i class="bi bi-hourglass-bottom"></i> <span><strong>{{ $inventory['expiring'] }}</strong> con lotes vencidos o por vencer</span> <i class="bi bi-chevron-right ms-auto"></i>
                    </a>
                @endif
            </div>
        @endif

        @if($actions)
            <h2 class="h6 text-muted text-uppercase fw-bold mb-2" style="letter-spacing:.05em">Accesos rápidos</h2>
            <div class="row g-2 g-md-3 mb-4">
                @foreach($actions as [$label, $icon, $url, $tone])
                    <div class="col-6 col-md-4 col-xl-2">
                        <a href="{{ $url }}" class="quick-action"><i class="bi {{ $icon }}" style="--tone: {{ $tone }}"></i>{{ $label }}</a>
                    </div>
                @endforeach
            </div>
        @endif

        @if($patients && ($patients['birthdays']->isNotEmpty() || $patients['missing_medical']))
            <div class="row g-3 mb-4">
                @if($patients['birthdays']->isNotEmpty())
                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header">🎂 Cumplen años hoy</div>
                            <ul class="list-group list-group-flush">
                                @foreach($patients['birthdays'] as $birthday)
                                    <li class="list-group-item d-flex align-items-center gap-2">
                                        <span class="avatar">{{ $birthday->initials }}</span>
                                        <a href="{{ route('patients.show', $birthday) }}" class="flex-fill text-decoration-none text-reset fw-semibold">{{ $birthday->full_name }}
                                            <span class="small text-muted fw-normal d-block">{{ $birthday->age_label }}</span></a>
                                        @php $greeting = \App\Support\Communications::render('cumpleanos', $birthday); @endphp
                                        @if($messages !== null && $can('communications.create'))
                                            @include('admin.communications.partials.sender', ['kind' => 'cumpleanos', 'patient' => $birthday, 'message' => $greeting,
                                                'sent' => \App\Models\Communication::where('kind', 'cumpleanos')->where('patient_id', $birthday->id)->where('created_at', '>=', today())->first(),
                                                'class' => 'btn-sm', 'label' => 'Saludar'])
                                        @elseif($birthday->whatsapp_link)
                                            <a href="{{ \App\Support\Communications::link($birthday, $greeting) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success"><i class="bi bi-whatsapp"></i> Saludar</a>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endif
                @if($patients['missing_medical'])
                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-body d-flex gap-3 align-items-center">
                                <div class="kpi-icon" style="--tone:#ea580c"><i class="bi bi-clipboard2-pulse"></i></div>
                                <div class="flex-fill">
                                    <div class="fw-semibold">{{ $patients['missing_medical'] }} {{ $patients['missing_medical'] === 1 ? 'paciente sin anamnesis' : 'pacientes sin anamnesis' }}</div>
                                    <div class="small text-muted">Pregunta por alergias, enfermedades y medicación en su próxima visita.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @endif

        <div class="row g-3">
            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-patch-check"></i> Suscripción</span>
                        @if($subscription)
                            <span class="badge bg-{{ $subscription->statusColor() }}">{{ $subscription->statusLabel() }}</span>
                        @endif
                    </div>
                    <div class="card-body">
                        @if($subscription)
                            <div class="fs-5 fw-bold">{{ $subscription->plan?->name }}</div>
                            <div class="text-muted mb-3">
                                {{ $subscription->status === 'trial' ? 'Prueba hasta' : 'Vence' }}
                                {{ $subscription->periodEnd()?->format('d/m/Y') ?? 'sin vencimiento' }}
                            </div>
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                @foreach(\App\Models\Plan::MODULES as $key => $label)
                                    @if($company->planAllows($key))
                                        <span class="badge bg-light text-dark border fw-normal"><i class="bi bi-check2 text-success"></i> {{ $label }}</span>
                                    @endif
                                @endforeach
                            </div>
                            <a href="{{ route('subscription.show') }}" class="btn btn-sm btn-outline-primary">Ver detalles</a>
                        @else
                            <p class="text-muted mb-0">Tu clínica todavía no tiene un plan asignado.</p>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header"><i class="bi bi-speedometer"></i> Uso del plan</div>
                    <div class="card-body">
                        @forelse($usage as $item)
                            <div class="mb-3">
                                <div class="d-flex justify-content-between small mb-1">
                                    <span class="fw-semibold">{{ $item['label'] }}</span>
                                    <span class="text-muted tabular">{{ $item['used'] }} / {{ $item['limit'] ?? 'Ilimitado' }}</span>
                                </div>
                                @if($item['limit'])
                                    <div class="progress" style="height: 8px;" role="progressbar" aria-label="{{ $item['label'] }}" aria-valuenow="{{ $item['percent'] }}" aria-valuemin="0" aria-valuemax="100">
                                        <div class="progress-bar {{ $item['percent'] >= 100 ? 'bg-danger' : ($item['percent'] >= 80 ? 'bg-warning' : 'bg-primary') }}" style="width: {{ $item['percent'] }}%"></div>
                                    </div>
                                @endif
                            </div>
                        @empty
                            <p class="text-muted mb-0">Sin plan asignado, no hay límites que mostrar.</p>
                        @endforelse
                        <div class="d-flex gap-4 text-muted small mt-3 pt-3 border-top">
                            <span><i class="bi bi-person-gear"></i> {{ $metrics['users'] }} usuarios</span>
                            <span><i class="bi bi-person-badge"></i> {{ $metrics['personal'] }} en el equipo</span>
                            <span><i class="bi bi-diagram-2"></i> {{ $metrics['branches'] }} consultorios</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
