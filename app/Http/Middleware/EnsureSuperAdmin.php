<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Administración de la plataforma (clínicas, planes, suscripciones, roles,
 * configuración global): solo el operador, users.is_super_admin = true.
 *
 * Reemplaza al antiguo `check-role:super_admin`, que dependía de que existiera
 * un rol con ese slug asignado en una clínica.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_super_admin) {
            return response()->view('errors.403', [], 403);
        }

        return $next($request);
    }
}
