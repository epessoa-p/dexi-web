<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Caja;
use App\Models\Cargo;
use App\Models\Company;
use App\Models\Personal;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Renderiza cada pantalla con los distintos tipos de usuario: detecta vistas
 * rotas (500) tras cambios de modelo o de rutas.
 */
class PagesSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $demo = Company::where('ruc', '1000000001')->firstOrFail();
        $cargo = Cargo::forCompany($demo)->create(['company_id' => $demo->id, 'name' => 'Odontólogo general', 'role_id' => Role::where('slug', 'odontologo')->value('id')]);
        Personal::forCompany($demo)->create(['company_id' => $demo->id, 'cargo_id' => $cargo->id, 'full_name' => 'Ana Ruiz']);
        Caja::forCompany($demo)->create(['company_id' => $demo->id, 'name' => 'Caja principal']);
    }

    protected function clinicPages(Company $company): array
    {
        $branch = Branch::forCompany($company)->firstOrFail();

        return [
            '/dashboard',
            '/admin/cargos', '/admin/cargos/create',
            '/admin/personal', '/admin/personal/create',
            '/admin/branches', '/admin/branches/create',
            "/admin/branches/{$branch->id}", "/admin/branches/{$branch->id}/edit",
            '/admin/cajas', '/admin/cajas/create',
            '/configuracion',
            '/mi-suscripcion',
            // Finanzas y caja (plan Clínica de la demo: caja + finanzas)
            '/caja',
            '/finanzas/cuentas',
            '/finanzas/gastos', '/finanzas/gastos?tab=basico', '/finanzas/gastos/nuevo?group=proveedor',
            '/finanzas/categorias-de-gasto',
            '/proveedores', '/proveedores/create',
            '/configuracion/metodos-de-pago',
            // Pacientes
            '/pacientes', '/pacientes/nuevo', '/pacientes?filter=alerts',
            // Tarifario y agenda
            '/configuracion/tarifario', '/configuracion/especialidades',
            '/agenda', '/agenda/hoy', '/agenda/nueva', '/agenda/disponibilidad',
            // Atención clínica
            '/atencion/consultas', '/atencion/consultas?filter=abiertas',
            '/atencion/tratamientos', '/atencion/tratamientos?filter=cerrados',
            // Ventas y cobros
            '/ventas', '/ventas?filter=mes', '/ventas/cobrar', '/cuentas-por-cobrar',
            '/atencion/laboratorio', '/atencion/laboratorio?filter=pagar', '/atencion/laboratorio?filter=todos', '/atencion/laboratorio/nueva', '/atencion/laboratorio/nueva?q=ana',
            '/inventario/productos', '/inventario/productos/nuevo', '/inventario/productos?filter=bajo', '/inventario/movimientos', '/inventario/movimientos/nuevo?kind=salida', '/inventario/movimientos/nuevo?kind=traslado',
            '/compras', '/compras/nueva', '/compras?filter=todas', '/cuentas-por-pagar', '/configuracion/tarifario',
            '/equipo/comisiones', '/equipo/comisiones?from=2026-01-01', '/equipo/comisiones/reglas', '/finanzas/gastos?tab=personal',
            '/comunicaciones', '/comunicaciones?dia=hoy', '/comunicaciones?tipo=cumpleanos', '/comunicaciones/seguimientos', '/comunicaciones/historial', '/comunicaciones/mensajes',
        ];
    }

    public function test_paginas_del_admin_de_clinica(): void
    {
        $demo = Company::where('ruc', '1000000001')->firstOrFail();
        $admin = User::where('email', 'admin@clinicademo.test')->firstOrFail();

        $pages = $this->clinicPages($demo);
        $pages[] = '/admin/cargos/' . Cargo::forCompany($demo)->value('id') . '/edit';
        $pages[] = '/admin/personal/' . Personal::forCompany($demo)->value('id') . '/edit';
        $pages[] = '/admin/cajas/' . Caja::forCompany($demo)->value('id') . '/edit';

        foreach ($pages as $page) {
            $this->actingAs($admin)->get($page)->assertOk();
        }
    }

    public function test_paginas_del_superadmin_en_modo_global(): void
    {
        $super = User::where('email', 'superadmin@dexi.test')->firstOrFail();
        $company = Company::firstOrFail();
        $plan = Plan::firstOrFail();
        $subscription = Subscription::firstOrFail();
        $role = Role::where('slug', 'admin')->firstOrFail();

        $pages = [
            '/dashboard',
            '/admin/companies', '/admin/companies/create', "/admin/companies/{$company->id}", "/admin/companies/{$company->id}/edit",
            '/admin/plans', '/admin/plans/create', "/admin/plans/{$plan->id}", "/admin/plans/{$plan->id}/edit",
            '/admin/subscriptions', '/admin/subscriptions?filter=lapsed', '/admin/subscriptions/create',
            "/admin/subscriptions/{$subscription->id}", "/admin/subscriptions/{$subscription->id}/edit",
            '/admin/roles', '/admin/roles/create', "/admin/roles/{$role->id}", "/admin/roles/{$role->id}/edit",
            '/admin/global-settings',
            '/admin/users', '/admin/users/create', '/admin/cargos', '/admin/personal', '/admin/branches', '/admin/branches/create',
        ];

        foreach ($pages as $page) {
            $this->actingAs($super)->get($page)->assertOk();
        }

        // Las secciones de una sola clínica piden "entrar" primero.
        $this->actingAs($super)->get('/admin/cajas')->assertRedirect(route('companies.index'));
        $this->actingAs($super)->get('/finanzas/gastos')->assertRedirect(route('companies.index'));
    }

    public function test_paginas_del_superadmin_dentro_de_una_clinica(): void
    {
        $super = User::where('email', 'superadmin@dexi.test')->firstOrFail();
        $demo = Company::where('ruc', '1000000001')->firstOrFail();

        $this->actingAs($super)->post("/set-company/{$demo->id}")->assertRedirect('/dashboard');

        foreach ($this->clinicPages($demo) as $page) {
            $this->get($page)->assertOk();
        }

        $this->post('/exit-company')->assertRedirect('/dashboard');
        $this->assertNull(session('current_company_id'));
    }
}
