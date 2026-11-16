<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Decide qué puede hacer un usuario según el estado de la suscripción de su
 * clínica. Lógica compartida por el middleware web y el de la API.
 * Ver ARQUITECTURA §7.3.
 */
class SubscriptionGate
{
    public const ALLOW = 'allow';
    public const BLOCK = 'block';
    public const READONLY = 'readonly';

    /**
     * @return self::ALLOW|self::BLOCK|self::READONLY
     */
    public static function decide(?User $user, ?Company $company, Request $request): string
    {
        // El operador de la plataforma no está sujeto a ninguna suscripción.
        if ($user?->is_super_admin) {
            return self::ALLOW;
        }

        if (! $company) {
            return self::ALLOW;   // sin clínica activa no hay nada que cobrar
        }

        // El superadmin puede apagar el bloqueo desde la configuración global:
        // las clínicas vencidas siguen operando y solo ven el aviso.
        if (! settings()->get('saas.block_on_expire', true, null)) {
            return self::ALLOW;
        }

        $subscription = $company->subscription;

        // Clínica sin suscripción: el operador todavía no le asignó un plan.
        if (! $subscription || ! $subscription->allowsRead()) {
            return self::BLOCK;
        }

        if ($subscription->inGrace() && static::isWrite($request)) {
            return self::READONLY;
        }

        return self::ALLOW;
    }

    public static function isWrite(Request $request): bool
    {
        return in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }
}
