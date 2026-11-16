<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    public function index()
    {
        $plans = Plan::withCount(['subscriptions as active_subscriptions_count' => fn ($q) => $q->writable()])
            ->orderBy('sort_order')
            ->orderBy('price')
            ->paginate(15);

        return view('admin.plans.index', compact('plans'));
    }

    public function create()
    {
        return view('admin.plans.create');
    }

    public function store()
    {
        try {
            $validated = $this->validated();

            Plan::create($validated);

            return redirect()->route('plans.index')->with('success', 'Plan creado exitosamente.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Error al crear plan', ['message' => $exception->getMessage()]);
            return back()->withInput()->withErrors(['error' => 'No fue posible crear el plan.']);
        }
    }

    public function show(Plan $plan)
    {
        $plan->load(['subscriptions' => fn ($q) => $q->with('company')->orderByPeriodEnd()->limit(50)]);

        return view('admin.plans.show', compact('plan'));
    }

    public function edit(Plan $plan)
    {
        return view('admin.plans.edit', compact('plan'));
    }

    public function update(Plan $plan)
    {
        try {
            $plan->update($this->validated($plan));

            return redirect()->route('plans.index')->with('success', 'Plan actualizado exitosamente.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Error al actualizar plan', ['plan_id' => $plan->id, 'message' => $exception->getMessage()]);
            return back()->withInput()->withErrors(['error' => 'No fue posible actualizar el plan.']);
        }
    }

    public function destroy(Plan $plan)
    {
        if ($plan->subscriptions()->exists()) {
            return back()->withErrors([
                'error' => 'No puedes eliminar un plan que tiene suscripciones asociadas. Desactívalo en su lugar.',
            ]);
        }

        $plan->delete();

        return redirect()->route('plans.index')->with('success', 'Plan eliminado exitosamente.');
    }

    /**
     * Reglas compartidas por store() y update().
     */
    protected function validated(?Plan $plan = null): array
    {
        $validated = request()->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:100', 'alpha_dash', Rule::unique('plans', 'slug')->ignore($plan?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['required', 'string', 'size:3'],
            'billing_period' => ['required', Rule::in(array_keys(Plan::PERIODS))],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'max_users' => ['nullable', 'integer', 'min:1'],
            'max_personal' => ['nullable', 'integer', 'min:1'],
            'max_branches' => ['nullable', 'integer', 'min:1'],
            'features' => ['nullable', 'array'],
            'features.*' => [Rule::in(array_keys(Plan::MODULES))],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        // Los campos `nullable` ausentes en la request no vienen en $validated,
        // así que se normalizan explícitamente.
        return [
            ...$validated,
            'slug' => Str::slug(($validated['slug'] ?? null) ?: $validated['name']),
            'currency' => Str::upper($validated['currency']),
            'description' => $validated['description'] ?? null,
            'max_users' => $validated['max_users'] ?? null,
            'max_personal' => $validated['max_personal'] ?? null,
            'max_branches' => $validated['max_branches'] ?? null,
            'features' => array_values(array_filter($validated['features'] ?? [])),
            'sort_order' => $validated['sort_order'] ?? 0,
            'active' => request()->boolean('active'),
        ];
    }
}
