@php
    $plansJson = $plans->mapWithKeys(fn ($p) => [$p->id => [
        'features' => $p->features ?? [],
        'max_users' => $p->max_users,
        'max_personal' => $p->max_personal,
        'max_branches' => $p->max_branches,
    ]]);
    $customize = (bool) old('customize_features', $subscription?->features_override !== null);
    $selectedFeatures = old('features_override', $subscription?->features_override ?? $subscription?->plan?->features ?? []);
    $defaultGrace = (int) settings()->get('saas.default_grace_days', 3, null);
@endphp

<div class="container-fluid" style="max-width: 960px;">
    <div class="page-header">
        <div>
            <h1 class="mb-1">{{ $subscription ? 'Suscripción de ' . $subscription->company?->name : 'Asignar suscripción' }}</h1>
            <p class="text-muted mb-0">Cada clínica tiene una única suscripción. Si dejas el vencimiento vacío se calcula según el plan.</p>
        </div>
        <a href="{{ $subscription ? route('subscriptions.show', $subscription) : route('subscriptions.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>

    @if($errors->any())
        <div class="alert alert-danger">
            <strong>Revisa los siguientes errores:</strong>
            <ul class="mb-0 mt-1 ps-3">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form action="{{ $action }}" method="POST">
        @csrf
        @if($method !== 'POST') @method($method) @endif

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3">Plan y vigencia</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Clínica <span class="text-danger">*</span></label>
                        @if($subscription)
                            <input type="text" class="form-control" value="{{ $subscription->company?->name }}" disabled>
                        @else
                            <select name="company_id" class="form-select" required>
                                <option value="">Seleccionar clínica</option>
                                @foreach($companies as $company)
                                    <option value="{{ $company->id }}" @selected((string) old('company_id', $selectedCompanyId ?? null) === (string) $company->id)>{{ $company->name }}</option>
                                @endforeach
                            </select>
                            @if($companies->isEmpty())
                                <div class="form-text text-warning">Todas las clínicas ya tienen suscripción.</div>
                            @endif
                        @endif
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Plan <span class="text-danger">*</span></label>
                        <select name="plan_id" id="plan_id" class="form-select" required>
                            <option value="">Seleccionar plan</option>
                            @foreach($plans as $plan)
                                <option value="{{ $plan->id }}" @selected((string) old('plan_id', $subscription?->plan_id) === (string) $plan->id)>
                                    {{ $plan->name }} — {{ $plan->currency }} {{ number_format($plan->price, 2) }} / {{ $plan->periodLabel() }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Estado <span class="text-danger">*</span></label>
                        <select name="status" class="form-select" required>
                            @foreach(\App\Models\Subscription::STATUSES as $value => $label)
                                <option value="{{ $value }}" @selected(old('status', $subscription?->status ?? 'trial') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Suspendida / cancelada cortan el acceso al instante.</div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Inicio <span class="text-danger">*</span></label>
                        <input type="date" name="starts_at" class="form-control" value="{{ old('starts_at', optional($subscription?->starts_at)->toDateString() ?? today()->toDateString()) }}" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Vence (fin de la prueba o del periodo)</label>
                        <input type="date" name="period_end" class="form-control" value="{{ old('period_end', optional($subscription?->periodEnd())->toDateString()) }}">
                        <div class="form-text">Vacío = según el plan (o sin vencimiento si es vitalicio).</div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Días de gracia <span class="text-danger">*</span></label>
                        <input type="number" min="0" max="60" name="grace_days" class="form-control" value="{{ old('grace_days', $subscription?->grace_days ?? $defaultGrace) }}" required>
                        <div class="form-text">Tras vencer, entra solo para consultar.</div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Precio pactado</label>
                        <input type="number" step="0.01" min="0" name="price" class="form-control" value="{{ old('price', $subscription?->price) }}" placeholder="Precio del plan">
                    </div>

                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check form-switch">
                            <input type="hidden" name="auto_renew" value="0">
                            <input class="form-check-input" type="checkbox" id="auto_renew" name="auto_renew" value="1" @checked(old('auto_renew', $subscription?->auto_renew ?? false))>
                            <label class="form-check-label" for="auto_renew">Renovación automática</label>
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Notas internas</label>
                        <textarea name="notes" rows="2" class="form-control">{{ old('notes', $subscription?->notes) }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-1">Cupos de esta clínica</h6>
                <p class="text-muted small mb-3">Déjalos vacíos para usar los del plan. Un valor aquí <strong>reemplaza</strong> al del plan solo para esta clínica (0 = ninguno permitido).</p>
                <div class="row g-3">
                    @foreach(\App\Models\Plan::LIMITS as $key => $label)
                        <div class="col-md-4">
                            <label class="form-label">Máximo de {{ Str::lower($label) }}</label>
                            <input type="number" min="0" name="max_{{ $key }}_override" class="form-control"
                                   value="{{ old('max_' . $key . '_override', $subscription?->{'max_' . $key . '_override'}) }}"
                                   data-plan-limit="{{ $key }}" placeholder="Del plan">
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="form-check form-switch mb-2">
                    <input type="hidden" name="customize_features" value="0">
                    <input class="form-check-input" type="checkbox" id="customize_features" name="customize_features" value="1" @checked($customize)>
                    <label class="form-check-label fw-bold" for="customize_features">Personalizar módulos de esta clínica</label>
                </div>
                <p class="text-muted small mb-3">Apagado: la clínica tiene exactamente los módulos de su plan. Encendido: la lista de abajo <strong>reemplaza</strong> por completo a la del plan.</p>
                <div class="row g-2" id="features_box">
                    @foreach(\App\Models\Plan::MODULES as $value => $label)
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="feature_{{ $value }}" name="features_override[]" value="{{ $value }}"
                                       @checked(in_array($value, $selectedFeatures, true))>
                                <label class="form-check-label" for="feature_{{ $value }}">{{ $label }}</label>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="d-flex gap-2 mb-4">
            <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> {{ $subscription ? 'Guardar cambios' : 'Asignar suscripción' }}</button>
            <a href="{{ route('subscriptions.index') }}" class="btn btn-light border">Cancelar</a>
        </div>
    </form>
</div>

<script>
    (function () {
        const plans = @json($plansJson);
        const planSelect = document.getElementById('plan_id');
        const toggle = document.getElementById('customize_features');
        const box = document.getElementById('features_box');

        // Muestra el cupo del plan como placeholder y, sin personalizar, marca sus módulos.
        function sync() {
            const plan = plans[planSelect.value];
            document.querySelectorAll('[data-plan-limit]').forEach(input => {
                const value = plan ? plan['max_' + input.dataset.planLimit] : null;
                input.placeholder = plan ? 'Del plan: ' + (value ?? 'ilimitado') : 'Del plan';
            });
            box.querySelectorAll('input').forEach(cb => {
                cb.disabled = !toggle.checked;
                if (!toggle.checked && plan) cb.checked = plan.features.includes(cb.value);
            });
        }

        planSelect.addEventListener('change', sync);
        toggle.addEventListener('change', sync);
        sync();
    })();
</script>
