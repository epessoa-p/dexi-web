<?php

namespace App\Providers;

use App\Support\Settings;
use App\Support\Tenancy;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Settings::class);

        $this->app->singleton(Tenancy::class, function ($app) {
            $tenancy = new Tenancy();

            // En consola (artisan, seeders, colas) no hay clínica activa y el
            // fail-closed dejaría todo vacío. En tests SÍ arranca cerrado: los
            // tests de aislamiento fijan su propia clínica y deben ser reales.
            if ($app->runningInConsole() && ! $app->runningUnitTests()) {
                $tenancy->unrestrict();
            }

            return $tenancy;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Las listas paginan con el marcado de Bootstrap 5 (la vista por defecto es Tailwind).
        Paginator::useBootstrapFive();

        // @module('caja') … @endmodule — muestra el bloque solo si el plan de la
        // clínica activa incluye ALGUNO de los módulos (el superadmin ve todo).
        // Es solo cosmético: las rutas revalidan con el middleware `plan:`.
        Blade::if('module', function (string ...$modules) {
            $user = auth()->user();

            if (! $user) {
                return false;
            }

            if ($user->is_super_admin) {
                return true;
            }

            $company = app(Tenancy::class)->company();

            foreach ($modules as $module) {
                if ($company?->planAllows($module)) {
                    return true;
                }
            }

            return false;
        });
    }
}
