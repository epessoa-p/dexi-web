<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Plan;

/**
 * Textos y resúmenes de cupos del plan. La regla en sí vive en
 * Company::withinLimit() (override de la clínica ?? límite del plan) y la
 * verificación al crear en Controller::planLimitReached(). ARQUITECTURA §8–9.
 */
class PlanLimits
{
    public const LABELS = [
        'users' => 'usuarios',
        'personal' => 'registros de personal',
        'branches' => 'consultorios',
    ];

    /**
     * Mensaje de error para mostrar cuando el cupo está agotado.
     */
    public static function message(Company $company, string $resource): string
    {
        $limit = $company->effectiveLimit($resource);
        $label = self::LABELS[$resource] ?? $resource;
        $plan = $company->currentPlan()?->name ?? 'actual';

        return "Tu plan {$plan} permite hasta {$limit} {$label} y ya alcanzaste ese límite. "
            . 'Contacta a soporte para ampliarlo.';
    }

    /**
     * Resumen consumo/tope de todos los recursos, para las pantallas de
     * suscripción y el panel de la clínica.
     *
     * @return array<int, array{resource: string, label: string, used: int, limit: int|null, percent: int, overridden: bool}>
     */
    public static function usage(Company $company): array
    {
        $summary = [];
        $subscription = $company->subscription;

        foreach (array_keys(Plan::LIMITS) as $resource) {
            $used = $company->usageFor($resource);
            $limit = $company->effectiveLimit($resource);

            $summary[] = [
                'resource' => $resource,
                'label' => ucfirst(self::LABELS[$resource] ?? $resource),
                'used' => $used,
                'limit' => $limit,
                'percent' => $limit ? (int) min(100, round($used / max(1, $limit) * 100)) : 0,
                'overridden' => $subscription?->{'max_' . $resource . '_override'} !== null,
            ];
        }

        return $summary;
    }
}
