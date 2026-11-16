<?php

use App\Support\Settings;

if (!function_exists('settings')) {
    /**
     * Instancia del gestor de configuraciones.
     */
    function settings(): Settings
    {
        return app(Settings::class);
    }
}

if (!function_exists('setting')) {
    /**
     * Valor de una configuración de la clínica indicada (o de la actual si se
     * omite). Pasa $companyId = null explícitamente para leer la global.
     */
    function setting(string $key, mixed $default = null, int|false|null $companyId = false): mixed
    {
        if ($companyId === false) {
            $companyId = auth()->check() ? auth()->user()->getCurrentCompany()?->id : null;
        }

        return settings()->get($key, $default, $companyId);
    }
}

if (!function_exists('global_setting')) {
    /**
     * Valor de una configuración global del SaaS.
     */
    function global_setting(string $key, mixed $default = null): mixed
    {
        return settings()->get($key, $default, null);
    }
}
