<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCompanyRequest;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Support\PlanLimits;
use Illuminate\Support\Facades\DB;

/**
 * Clínicas (tenants). Solo el superadmin — ruta con `super-admin`.
 */
class CompanyController extends Controller
{
    public function index()
    {
        $companies = Company::with('subscription.plan')->orderBy('name')->paginate(15);

        return view('admin.companies.index', compact('companies'));
    }

    public function create()
    {
        return view('admin.companies.create', [
            'plans' => Plan::active()->orderBy('sort_order')->get(),
            'defaultPlanId' => settings()->get('saas.default_plan_id', null, null),
        ]);
    }

    /**
     * Alta de clínica (ARQUITECTURA §12.5): clínica → suscripción de prueba →
     * usuario administrador con rol `admin`. Todo o nada.
     */
    public function store(StoreCompanyRequest $request)
    {
        $data = $request->validated();

        $company = DB::transaction(function () use ($data) {
            $company = Company::create(collect($data)->only(['name', 'ruc', 'address', 'phone', 'email', 'description'])->all());

            if (! empty($data['plan_id'])) {
                $plan = Plan::findOrFail($data['plan_id']);
                $trialDays = $plan->trial_days ?: (int) settings()->get('saas.default_trial_days', 15, null);

                Subscription::create([
                    'company_id' => $company->id,
                    'plan_id' => $plan->id,
                    'status' => 'trial',
                    'starts_at' => today(),
                    'trial_ends_at' => now()->addDays($trialDays)->endOfDay(),
                    'grace_days' => (int) settings()->get('saas.default_grace_days', 3, null),
                    'price' => $plan->price,
                    'currency' => $plan->currency,
                    'created_by' => auth()->id(),
                ]);
            }

            if (! empty($data['admin_email'])) {
                $admin = User::create([
                    'name' => $data['admin_name'],
                    'email' => $data['admin_email'],
                    'password' => $data['admin_password'],
                    'active' => true,
                ]);

                $admin->companies()->attach($company->id, [
                    'role_id' => Role::where('slug', 'admin')->value('id'),
                    'active' => true,
                ]);
            }

            return $company;
        });

        return redirect()->route('companies.show', $company)->with('success', 'Clínica creada exitosamente');
    }

    public function show(Company $company)
    {
        $company->load('subscription.plan');

        return view('admin.companies.show', [
            'company' => $company,
            'subscription' => $company->subscription,
            'usage' => PlanLimits::usage($company),
            'users' => $company->users()->orderBy('name')->paginate(10),
            'roles' => Role::pluck('name', 'id'),
        ]);
    }

    public function edit(Company $company)
    {
        return view('admin.companies.edit', compact('company'));
    }

    public function update(StoreCompanyRequest $request, Company $company)
    {
        $company->update(collect($request->validated())->only(['name', 'ruc', 'address', 'phone', 'email', 'description'])->all()
            + ['active' => $request->boolean('active', $company->active)]);

        return redirect()->route('companies.show', $company)->with('success', 'Clínica actualizada exitosamente');
    }

    public function destroy(Company $company)
    {
        // Si el superadmin estaba "dentro" de esta clínica, vuelve al Modo Global.
        if ((int) session('current_company_id') === $company->id) {
            session()->forget('current_company_id');
        }

        $company->delete();

        return redirect()->route('companies.index')->with('success', 'Clínica eliminada exitosamente');
    }
}
