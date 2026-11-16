<?php

namespace App\Http\Middleware\Api;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Tercera capa: alguno de los permisos (OR, como `check-permission:` en la web). */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if ($user->is_super_admin) {
            return $next($request);
        }

        $company = $request->attributes->get('tenant_company');

        if (! $company) {
            return ApiError::companyRequired();
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermissionInCompany($permission, $company)) {
                return $next($request);
            }
        }

        return ApiError::permissionDenied($permissions);
    }
}
