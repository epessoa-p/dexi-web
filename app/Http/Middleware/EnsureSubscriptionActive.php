<?php

namespace App\Http\Middleware;

use App\Support\SubscriptionGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Primera de las cuatro capas de autorización: ¿la suscripción de la clínica
 * permite siquiera entrar? Corre en todo el grupo `web`, antes que plan y permiso.
 *
 * Ver ARQUITECTURA §7.3.
 */
class EnsureSubscriptionActive
{
    /**
     * Rutas que nunca se bloquean: si no, un usuario con la suscripción
     * vencida quedaría atrapado sin poder ni salir ni cambiar de clínica.
     */
    protected array $except = [
        'login',
        'login.store',
        'logout',
        'select-company',
        'set-company',
        'exit-company',
        'subscription.show',
        'subscription.blocked',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), $this->except, true)) {
            return $next($request);
        }

        $company = $request->attributes->get('tenant_company');

        $decision = SubscriptionGate::decide($request->user(), $company, $request);

        if ($decision === SubscriptionGate::BLOCK) {
            return redirect()->route('subscription.blocked');
        }

        if ($decision === SubscriptionGate::READONLY) {
            return back()->withInput()->withErrors([
                'error' => 'La suscripción de la clínica venció. Puedes consultar la información, '
                         . 'pero no registrar cambios hasta renovarla.',
            ]);
        }

        return $next($request);
    }
}
