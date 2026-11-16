<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tercera capa: ¿el usuario tiene ALGUNO de los permisos? (OR)
 * `check-permission:users.create,users.edit`. Ver ARQUITECTURA §5.3.
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        // Super admin siempre puede pasar
        if ($user->is_super_admin) {
            return $next($request);
        }

        // La clínica activa ya la resolvió SetTenant; no se vuelve a consultar.
        $company = $request->attributes->get('tenant_company');

        if (! $company) {
            return redirect()->route('select-company')
                ->with('error', 'Selecciona una clínica para continuar.');
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermissionInCompany($permission, $company)) {
                return $next($request);
            }
        }

        return response()->view('errors.403', [], 403);
    }
}
