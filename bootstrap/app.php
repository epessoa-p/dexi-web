<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Corren en TODA petición web. SetTenant es no-op para invitados, y
        // EnsureSubscriptionActive exime login, logout y selección de clínica.
        // Orden por request: auth → set-tenant → subscription → plan → permiso
        // → (en el controlador) planLimitReached al crear. ARQUITECTURA §10.
        $middleware->appendToGroup('web', [
            \App\Http\Middleware\SetTenant::class,
            \App\Http\Middleware\EnsureSubscriptionActive::class,
        ]);

        // La clínica activa debe fijarse ANTES del route model binding: si no,
        // el CompanyScope corre sin clínica (fail-closed) y toda ruta /{modelo}
        // de un tenant responde 404.
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\SetTenant::class,
        );

        // API: la clínica sale del header X-Company-Id, y también antes del binding.
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\Api\ResolveCompany::class,
        );

        $middleware->alias([
            // Web
            'set-tenant' => \App\Http\Middleware\SetTenant::class,
            'subscription' => \App\Http\Middleware\EnsureSubscriptionActive::class,
            'plan' => \App\Http\Middleware\CheckPlanModule::class,
            'check-permission' => \App\Http\Middleware\CheckPermission::class,
            'check-role' => \App\Http\Middleware\CheckRole::class,
            'check-company' => \App\Http\Middleware\CheckCompany::class,
            'tenant' => \App\Http\Middleware\RequireTenant::class,
            'super-admin' => \App\Http\Middleware\EnsureSuperAdmin::class,

            // API
            'api.tenant' => \App\Http\Middleware\Api\ResolveCompany::class,
            'api.subscription' => \App\Http\Middleware\Api\EnsureSubscription::class,
            'api.plan' => \App\Http\Middleware\Api\RequirePlan::class,
            'api.permission' => \App\Http\Middleware\Api\RequirePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // La API siempre responde JSON, aunque el cliente olvide el Accept.
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
    })->create();
