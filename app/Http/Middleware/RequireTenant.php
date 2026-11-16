<?php

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige una clínica activa. Lo usan los módulos cuyos datos siempre son de UNA
 * clínica (cajas, cargos, configuración…): en Modo Global el superadmin
 * primero debe "entrar como" una clínica.
 */
class RequireTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app(Tenancy::class)->hasTenant()) {
            return $next($request);
        }

        if ($request->user()?->is_super_admin) {
            return redirect()->route('companies.index')
                ->with('error', 'Esa sección es de una clínica: usa «Entrar» en la clínica con la que quieres trabajar.');
        }

        return redirect()->route('select-company')
            ->with('error', 'Selecciona una clínica para continuar.');
    }
}
