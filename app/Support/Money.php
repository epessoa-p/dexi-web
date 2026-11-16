<?php

namespace App\Support;

use App\Models\Company;

/**
 * Formato de importes con el símbolo de moneda de la clínica (Configuración →
 * Regional). Evita escribir «Bs» o «$» a mano en las vistas.
 */
final class Money
{
    public static function format(int|float|string|null $amount, ?Company $company = null): string
    {
        return self::symbol($company) . ' ' . number_format((float) $amount, 2, '.', ',');
    }

    public static function symbol(?Company $company = null): string
    {
        $company ??= app(Tenancy::class)->company();

        return (string) (settings()->get('regional.currency_symbol', null, $company?->id) ?: 'Bs');
    }
}
