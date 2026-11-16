<?php

namespace App\Http\Controllers;

use App\Support\PlanLimits;
use App\Support\SubscriptionGate;

/**
 * Vista de solo lectura de la suscripción de la clínica activa.
 */
class SubscriptionController extends Controller
{
    public function show()
    {
        $company = auth()->user()->getCurrentCompany();

        abort_if($company === null, 403, 'No tienes una clínica activa.');

        return view('subscription.show', [
            'company' => $company,
            'subscription' => $company->subscription?->load('plan'),
            'usage' => PlanLimits::usage($company),
            'payments' => $company->subscriptionPayments()->latest('paid_at')->limit(24)->get(),
        ]);
    }

    /**
     * Pantalla de bloqueo cuando la suscripción no permite ni consultar.
     */
    public function blocked()
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Si ya no está bloqueada (renovación, superadmin…), no tiene sentido mostrarla.
        if (SubscriptionGate::decide($user, $company, request()) !== SubscriptionGate::BLOCK) {
            return redirect()->route('dashboard');
        }

        return view('subscription.blocked', [
            'company' => $company,
            'subscription' => $company?->subscription,
            'hasOtherCompanies' => $user->activeCompanies()->count() > 1,
        ]);
    }
}
