<?php

namespace App\Http\Middleware\Api;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Segunda capa: ¿el plan incluye alguno de estos módulos? (OR, como `plan:` en la web). */
class RequirePlan
{
    public function handle(Request $request, Closure $next, string ...$modules): Response
    {
        if ($request->user()->is_super_admin) {
            return $next($request);
        }

        $company = $request->attributes->get('tenant_company');

        if (! $company) {
            return ApiError::companyRequired();
        }

        foreach ($modules as $module) {
            if ($company->planAllows($module)) {
                return $next($request);
            }
        }

        return ApiError::planModuleMissing($modules);
    }
}
