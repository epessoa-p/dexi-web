<?php

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija la clínica activa de la petición. Corre en todo el grupo `web`, y
 * ANTES del route model binding (ver bootstrap/app.php).
 *
 *   - Invitado           → sin clínica (fail-closed). No rompe el login:
 *                          User y Company son modelos globales.
 *   - Superadmin         → Modo Global (sin filtro), salvo que haya elegido
 *                          "entrar como" una clínica concreta.
 *   - Usuario de clínica → la de su sesión (si sigue siendo miembro activo),
 *                          o la primera que tenga.
 *
 * Ver ARQUITECTURA §4.1.
 */
class SetTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenancy = app(Tenancy::class);
        $user = $request->user();

        if (! $user) {
            $tenancy->set(null);

            return $next($request);
        }

        $sessionCompanyId = session('current_company_id');

        if ($user->is_super_admin) {
            $sessionCompanyId ? $tenancy->set((int) $sessionCompanyId) : $tenancy->unrestrict();
        } else {
            // La sesión se revalida en cada petición: si le quitaron el acceso a
            // esa clínica (o la desactivaron) no puede seguir operando en ella.
            $companyId = $sessionCompanyId && $user->activeCompanies()->whereKey($sessionCompanyId)->exists()
                ? (int) $sessionCompanyId
                : $user->activeCompanies()->value('companies.id');

            if ($companyId) {
                session(['current_company_id' => $companyId]);
            } else {
                session()->forget('current_company_id');
            }

            $tenancy->set($companyId ? (int) $companyId : null);
        }

        $company = $tenancy->company();

        // Agenda y registros clínicos en la hora local de la clínica.
        $timezone = $company ? settings()->get('regional.timezone', null, $company->id) : null;
        if ($timezone && in_array($timezone, timezone_identifiers_list(), true)) {
            config(['app.timezone' => $timezone]);
            date_default_timezone_set($timezone);
        }

        // Los controladores y las vistas leen de aquí, sin repetir la consulta.
        $request->attributes->set('tenant_company', $company);
        view()->share('tenantCompany', $company);
        view()->share('globalMode', $tenancy->isUnrestricted());

        return $next($request);
    }
}
