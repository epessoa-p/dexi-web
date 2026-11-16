<?php

namespace App\Http\Middleware\Api;

use App\Models\Company;
use App\Support\ApiError;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Equivalente API de SetTenant + RequireTenant: la clínica activa sale del
 * header `X-Company-Id` (ARQUITECTURA §11). Corre DESPUÉS de auth:sanctum.
 * El usuario debe ser miembro activo de una clínica activa; el superadmin
 * puede entrar a cualquiera.
 *
 *   api.tenant           → la clínica es obligatoria (409 company_required)
 *   api.tenant:optional  → si no viene, se sigue sin clínica (p. ej. GET /me)
 */
class ResolveCompany
{
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $tenancy = app(Tenancy::class);
        $user = $request->user();
        $header = $request->header('X-Company-Id');

        // Sin clínica elegida nada se filtra «por accidente»: fail-closed.
        $tenancy->set(null);
        $request->attributes->set('tenant_company', null);

        if ($header === null || $header === '') {
            return $mode === 'optional' ? $next($request) : ApiError::companyRequired();
        }

        $company = ctype_digit((string) $header) ? Company::where('active', true)->find((int) $header) : null;

        $member = $company && ($user->is_super_admin || $user->activeCompanies()->whereKey($company->id)->exists());

        if (! $member) {
            return ApiError::companyForbidden();
        }

        $tenancy->setCompany($company);

        $timezone = settings()->get('regional.timezone', null, $company->id);
        if ($timezone && in_array($timezone, timezone_identifiers_list(), true)) {
            config(['app.timezone' => $timezone]);
            date_default_timezone_set($timezone);
        }

        $request->attributes->set('tenant_company', $company);

        return $next($request);
    }
}
