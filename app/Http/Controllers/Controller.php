<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Support\PlanLimits;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Cuarta capa (ARQUITECTURA §9): ¿la clínica ya agotó el cupo del recurso?
     * Solo se comprueba al CREAR. El superadmin no tiene tope.
     *
     * @param  string  $key  'users' | 'personal' | 'branches'
     */
    protected function planLimitReached(?int $companyId, string $key): bool
    {
        if (! $companyId || auth()->user()?->is_super_admin) {
            return false;
        }

        $company = Company::find($companyId);

        return $company !== null && ! $company->withinLimit($key);
    }

    /**
     * Estado del cupo para pintar o deshabilitar el botón "Crear".
     *
     * @return array{reached: bool, usage: int, max: int|null, unlimited: bool}
     */
    protected function planLimitStatus(?int $companyId, string $key): array
    {
        $company = $companyId ? Company::find($companyId) : null;

        if (! $company) {
            return ['reached' => false, 'usage' => 0, 'max' => null, 'unlimited' => true];
        }

        $max = $company->effectiveLimit($key);

        return [
            'reached' => $this->planLimitReached($companyId, $key),
            'usage' => $company->usageFor($key),
            'max' => $max,
            'unlimited' => $max === null,
        ];
    }

    /**
     * Clínica a la que va un registro nuevo: la activa si la hay; si no
     * (superadmin en Modo Global), la que eligió en el formulario.
     */
    protected function targetCompanyId(): ?int
    {
        $user = auth()->user();

        if ($current = $user->getCurrentCompany()) {
            return $current->id;
        }

        return $user->is_super_admin ? (request()->integer('company_id') ?: null) : null;
    }

    /** Opciones del selector de clínica de los formularios. */
    protected function companyOptions()
    {
        $user = auth()->user();

        if ($current = $user->getCurrentCompany()) {
            return collect([$current]);
        }

        return $user->is_super_admin ? Company::where('active', true)->orderBy('name')->get() : collect();
    }

    /** Mensaje estándar cuando el cupo está agotado. */
    protected function planLimitMessage(int $companyId, string $key): string
    {
        return PlanLimits::message(Company::findOrFail($companyId), $key);
    }
}
