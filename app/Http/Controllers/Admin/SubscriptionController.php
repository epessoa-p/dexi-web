<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Suscripciones (1:1 con la clínica). Solo el superadmin — ruta con `super-admin`.
 * Aquí también se editan los overrides por clínica (ARQUITECTURA §8).
 */
class SubscriptionController extends Controller
{
    public const FILTERS = [
        'writable' => 'Al día / en prueba',
        'expiring' => 'Vencen en 30 días',
        'lapsed' => 'Vencidas',
        'blocked' => 'Suspendidas / canceladas',
    ];

    public function index()
    {
        $filter = request('filter');

        $subscriptions = Subscription::with(['company', 'plan'])
            ->when($filter === 'writable', fn ($q) => $q->writable())
            ->when($filter === 'expiring', fn ($q) => $q->expiringWithin(30))
            ->when($filter === 'lapsed', fn ($q) => $q->lapsed())
            ->when($filter === 'blocked', fn ($q) => $q->whereIn('status', ['suspended', 'cancelled']))
            ->orderByPeriodEnd()
            ->paginate(15)
            ->withQueryString();

        return view('admin.subscriptions.index', [
            'subscriptions' => $subscriptions,
            'filter' => $filter,
            'counts' => [
                'total' => Subscription::count(),
                'writable' => Subscription::writable()->count(),
                'expiring' => Subscription::expiringWithin(30)->count(),
                'lapsed' => Subscription::lapsed()->count(),
            ],
        ]);
    }

    public function create()
    {
        return view('admin.subscriptions.create', [
            // 1:1 — solo las clínicas que aún no tienen suscripción.
            'companies' => Company::whereDoesntHave('subscription')->orderBy('name')->get(),
            'plans' => Plan::active()->orderBy('sort_order')->get(),
            'selectedCompanyId' => request()->integer('company_id') ?: null,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request, null);
        $plan = Plan::findOrFail($validated['plan_id']);

        $subscription = Subscription::create([
            ...$this->attributes($validated, $plan, $request),
            'company_id' => $validated['company_id'],
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('subscriptions.show', $subscription)->with('success', 'Suscripción asignada exitosamente.');
    }

    public function show(Subscription $subscription)
    {
        $subscription->load(['company', 'plan', 'creator', 'payments.registeredBy']);

        return view('admin.subscriptions.show', [
            'subscription' => $subscription,
            'usage' => \App\Support\PlanLimits::usage($subscription->company),
        ]);
    }

    public function edit(Subscription $subscription)
    {
        return view('admin.subscriptions.edit', [
            'subscription' => $subscription->load('company', 'plan'),
            'companies' => collect(),
            'plans' => Plan::orderBy('sort_order')->get(),
        ]);
    }

    public function update(Request $request, Subscription $subscription)
    {
        $validated = $this->validated($request, $subscription);
        $plan = Plan::findOrFail($validated['plan_id']);

        $attributes = $this->attributes($validated, $plan, $request);
        $attributes['cancelled_at'] = $validated['status'] === 'cancelled' ? ($subscription->cancelled_at ?? now()) : null;

        $subscription->update($attributes);

        return redirect()->route('subscriptions.show', $subscription)->with('success', 'Suscripción actualizada exitosamente.');
    }

    /**
     * Extiende la vigencia uno o varios períodos del plan.
     */
    public function renew(Subscription $subscription)
    {
        $validated = request()->validate([
            'periods' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);

        $subscription->loadMissing('plan')->renew((int) ($validated['periods'] ?? 1));

        return back()->with('success', 'Suscripción renovada hasta ' . ($subscription->current_period_end?->format('d/m/Y') ?? 'sin vencimiento') . '.');
    }

    public function cancel(Subscription $subscription)
    {
        $subscription->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'auto_renew' => false,
        ]);

        return back()->with('success', 'Suscripción cancelada: la clínica ya no puede entrar.');
    }

    /**
     * Registra un pago manual y, si se pide, renueva la suscripción.
     */
    public function storePayment(Subscription $subscription)
    {
        $validated = request()->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'method' => ['required', Rule::in(array_keys(SubscriptionPayment::METHODS))],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_at' => ['required', 'date'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($subscription, $validated) {
            $subscription->payments()->create([
                ...$validated,
                'company_id' => $subscription->company_id,
                'currency' => $subscription->currency,
                'registered_by' => auth()->id(),
            ]);

            if (request()->boolean('renew_after_payment')) {
                $subscription->loadMissing('plan')->renew(1);
            }
        });

        return back()->with('success', 'Pago registrado exitosamente.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    protected function validated(Request $request, ?Subscription $subscription): array
    {
        $limitRule = ['nullable', 'integer', 'min:0', 'max:100000'];

        return $request->validate([
            'company_id' => $subscription
                ? ['prohibited']
                : ['required', 'exists:companies,id', Rule::unique('subscriptions', 'company_id')],
            'plan_id' => ['required', 'exists:plans,id'],
            'status' => ['required', Rule::in(array_keys(Subscription::STATUSES))],
            'starts_at' => ['required', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'grace_days' => ['required', 'integer', 'min:0', 'max:60'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],

            // Overrides: vacío = heredar del plan; 0 = ninguno permitido.
            'max_users_override' => $limitRule,
            'max_personal_override' => $limitRule,
            'max_branches_override' => $limitRule,
            'customize_features' => ['nullable', 'boolean'],
            'features_override' => ['nullable', 'array'],
            'features_override.*' => [Rule::in(array_keys(Plan::MODULES))],
        ], [
            'company_id.unique' => 'Esa clínica ya tiene una suscripción: edítala en lugar de crear otra.',
        ]);
    }

    /**
     * Normaliza el formulario a columnas. La fecha de fin única del formulario
     * se guarda en trial_ends_at (prueba) o en current_period_end (resto).
     */
    protected function attributes(array $validated, Plan $plan, Request $request): array
    {
        $periodEnd = ! empty($validated['period_end'])
            ? Carbon::parse($validated['period_end'])->endOfDay()
            : $this->defaultPeriodEnd($plan, $validated['starts_at'], $validated['status']);

        $isTrial = $validated['status'] === 'trial';

        return [
            'plan_id' => $plan->id,
            'status' => $validated['status'],
            'starts_at' => $validated['starts_at'],
            'trial_ends_at' => $isTrial ? $periodEnd : null,
            'current_period_end' => $isTrial ? null : $periodEnd,
            'grace_days' => $validated['grace_days'],
            'price' => $validated['price'] ?? $plan->price,
            'currency' => $plan->currency,
            'auto_renew' => $request->boolean('auto_renew'),
            'notes' => $validated['notes'] ?? null,
            'max_users_override' => $validated['max_users_override'] ?? null,
            'max_personal_override' => $validated['max_personal_override'] ?? null,
            'max_branches_override' => $validated['max_branches_override'] ?? null,
            // Sin "personalizar módulos" se heredan los del plan (NULL).
            'features_override' => $request->boolean('customize_features')
                ? array_values($validated['features_override'] ?? [])
                : null,
        ];
    }

    /**
     * Fecha de fin sugerida cuando el formulario la deja vacía.
     * null = sin vencimiento (plan vitalicio activo).
     */
    protected function defaultPeriodEnd(Plan $plan, string $startsAt, string $status): ?Carbon
    {
        $start = Carbon::parse($startsAt);

        if ($status === 'trial') {
            $days = $plan->trial_days ?: (int) settings()->get('saas.default_trial_days', 15, null);

            return $start->copy()->addDays($days)->endOfDay();
        }

        $months = $plan->periodMonths();

        return $months === null ? null : $start->copy()->addMonthsNoOverflow($months)->endOfDay();
    }
}
