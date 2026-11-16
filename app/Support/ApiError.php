<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Errores de la API con un `code` estable (ARQUITECTURA §11): el cliente decide
 * qué hacer con el código; el mensaje es para mostrarlo tal cual.
 */
class ApiError
{
    public static function make(int $status, string $code, string $message, array $extra = []): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message] + $extra, $status);
    }

    public static function companyRequired(): JsonResponse
    {
        return self::make(409, 'company_required', 'Elige con qué clínica quieres trabajar (header X-Company-Id).');
    }

    public static function companyForbidden(): JsonResponse
    {
        return self::make(403, 'company_forbidden', 'No perteneces a esa clínica o está desactivada.');
    }

    public static function permissionDenied(array $permissions): JsonResponse
    {
        return self::make(403, 'permission_denied', 'Tu rol no tiene permiso para esta acción.', ['required' => $permissions]);
    }

    public static function planModuleMissing(array $modules): JsonResponse
    {
        return self::make(403, 'plan_module_missing', 'El plan de la clínica no incluye este módulo.', ['modules' => $modules]);
    }

    public static function subscriptionInactive(): JsonResponse
    {
        return self::make(403, 'subscription_inactive', 'La suscripción de la clínica está suspendida o vencida.');
    }

    public static function subscriptionReadonly(): JsonResponse
    {
        return self::make(403, 'subscription_grace_readonly', 'La suscripción venció: puedes consultar, pero no registrar cambios hasta renovarla.');
    }
}
