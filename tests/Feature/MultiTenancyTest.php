<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Caja;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifica las cuatro capas de ARQUITECTURA-EMPRESAS-PLANES-PERMISOS.md con
 * los datos demo: suscripción → plan → permiso → cupo, más el aislamiento.
 */
class MultiTenancyTest extends TestCase
{
    use RefreshDatabase;

    protected Company $demo;
    protected Company $sonrisa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->demo = Company::where('ruc', '1000000001')->firstOrFail();
        $this->sonrisa = Company::where('ruc', '1000000002')->firstOrFail();
    }

    protected function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    // ── §4 Aislamiento ───────────────────────────────────────────────────────

    public function test_listados_solo_muestran_datos_de_la_clinica_activa(): void
    {
        $this->actingAs($this->user('admin@sonrisa.test'))
            ->get('/admin/branches')
            ->assertOk()
            ->assertSee('Consultorio Sonrisa')
            ->assertDontSee('Consultorio Central');
    }

    public function test_un_dato_de_otra_clinica_responde_404(): void
    {
        $ajeno = Branch::forCompany($this->demo)->firstOrFail();

        $this->actingAs($this->user('admin@sonrisa.test'))
            ->get("/admin/branches/{$ajeno->id}/edit")
            ->assertNotFound();
    }

    public function test_sin_clinica_activa_el_scope_no_devuelve_nada(): void
    {
        app(Tenancy::class)->set(null);
        $this->assertSame(0, Branch::count());

        app(Tenancy::class)->set($this->demo->id);
        $this->assertSame(2, Branch::count());

        app(Tenancy::class)->unrestrict();
        $this->assertSame(3, Branch::count());
    }

    public function test_al_crear_se_autocompleta_company_id(): void
    {
        app(Tenancy::class)->set($this->sonrisa->id);

        $caja = Caja::create(['name' => 'Caja 1']);

        $this->assertSame($this->sonrisa->id, $caja->company_id);
    }

    public function test_un_admin_no_ve_usuarios_de_otra_clinica(): void
    {
        $ajeno = $this->user('admin@clinicademo.test');

        $this->actingAs($this->user('admin@sonrisa.test'))
            ->get("/admin/users/{$ajeno->id}/edit")
            ->assertNotFound();
    }

    public function test_usuario_multi_clinica_cambia_de_clinica(): void
    {
        $odontologo = $this->user('odontologo@clinicademo.test');

        $this->actingAs($odontologo)
            ->post("/set-company/{$this->sonrisa->id}")
            ->assertRedirect('/dashboard');

        $this->assertSame($this->sonrisa->id, session('current_company_id'));

        // No puede "entrar" a una clínica a la que no pertenece.
        $otra = Company::create(['name' => 'Otra']);
        $this->post("/set-company/{$otra->id}")->assertNotFound();
    }

    // ── §5 Permisos ──────────────────────────────────────────────────────────

    public function test_sin_permiso_responde_403(): void
    {
        // El odontólogo no tiene users.view.
        $this->actingAs($this->user('odontologo@clinicademo.test'))
            ->get('/admin/users')
            ->assertForbidden();
    }

    public function test_solo_el_superadmin_administra_la_plataforma(): void
    {
        $this->actingAs($this->user('admin@clinicademo.test'))->get('/admin/companies')->assertForbidden();
        $this->actingAs($this->user('superadmin@dexi.test'))->get('/admin/companies')->assertOk();
    }

    public function test_un_admin_de_clinica_no_puede_crear_superadmins(): void
    {
        $this->actingAs($this->user('admin@sonrisa.test'))->post('/admin/users', [
            'name' => 'intruso',
            'email' => 'intruso@test.test',
            'password' => 'Secreta123',
            'password_confirmation' => 'Secreta123',
            'role_id' => \App\Models\Role::where('slug', 'cajero')->value('id'),
            'is_super_admin' => 1,
        ])->assertRedirect('/admin/users');

        $intruso = $this->user('intruso@test.test');
        $this->assertFalse($intruso->is_super_admin);
        $this->assertTrue($intruso->companies()->whereKey($this->sonrisa->id)->exists());
    }

    // ── §6 Plan ──────────────────────────────────────────────────────────────

    public function test_modulo_fuera_del_plan_responde_403(): void
    {
        // Plan Consultorio (Sonrisa) incluye caja; quitémosela con un override.
        $this->sonrisa->subscription->update(['features_override' => ['agenda']]);

        $this->actingAs($this->user('admin@sonrisa.test'))
            ->get('/admin/cajas')
            ->assertForbidden()
            ->assertSee('no incluye');
    }

    public function test_modulo_del_plan_esta_disponible(): void
    {
        $this->actingAs($this->user('admin@sonrisa.test'))->get('/admin/cajas')->assertOk();
    }

    // ── §7 Suscripción ───────────────────────────────────────────────────────

    public function test_suscripcion_vencida_en_gracia_es_solo_lectura(): void
    {
        $this->sonrisa->subscription->update(['current_period_end' => now()->subDay(), 'grace_days' => 3]);

        $admin = $this->user('admin@sonrisa.test');

        $this->actingAs($admin)->get('/admin/branches')->assertOk();

        $this->actingAs($admin)
            ->post('/admin/branches', ['name' => 'Nuevo'])
            ->assertSessionHasErrors('error');

        $this->assertSame(1, Branch::forCompany($this->sonrisa)->count());
    }

    public function test_suscripcion_vencida_sin_gracia_bloquea(): void
    {
        $this->sonrisa->subscription->update(['current_period_end' => now()->subDays(10), 'grace_days' => 3]);

        $this->actingAs($this->user('admin@sonrisa.test'))
            ->get('/admin/branches')
            ->assertRedirect(route('subscription.blocked'));
    }

    public function test_suscripcion_suspendida_bloquea_aunque_tenga_fecha_vigente(): void
    {
        $this->sonrisa->subscription->update(['status' => 'suspended']);

        $this->actingAs($this->user('admin@sonrisa.test'))
            ->get('/dashboard')
            ->assertRedirect(route('subscription.blocked'));
    }

    public function test_estado_derivado_de_fechas(): void
    {
        $sub = new Subscription(['status' => 'active', 'current_period_end' => now()->subDays(2), 'grace_days' => 3]);
        $this->assertFalse($sub->allowsWrite());
        $this->assertTrue($sub->inGrace());
        $this->assertTrue($sub->allowsRead());

        $sub->current_period_end = now()->subDays(5);
        $this->assertFalse($sub->allowsRead());

        $trial = new Subscription(['status' => 'trial', 'trial_ends_at' => now()->addDay()]);
        $this->assertTrue($trial->allowsWrite());
    }

    // ── §8–9 Overrides y cupos ───────────────────────────────────────────────

    public function test_cupo_del_plan_impide_crear(): void
    {
        // Plan Consultorio: 1 consultorio, y Sonrisa ya tiene 1.
        $this->actingAs($this->user('admin@sonrisa.test'))
            ->post('/admin/branches', ['name' => 'Sede 2'])
            ->assertSessionHasErrors('error');

        $this->assertSame(1, Branch::forCompany($this->sonrisa)->count());
    }

    public function test_override_de_la_clinica_amplia_el_cupo(): void
    {
        $this->sonrisa->subscription->update(['max_branches_override' => 2]);

        $this->actingAs($this->user('admin@sonrisa.test'))
            ->post('/admin/branches', ['name' => 'Sede 2'])
            ->assertRedirect('/admin/branches');

        $this->assertSame(2, Branch::forCompany($this->sonrisa)->count());
        $this->assertSame(2, $this->sonrisa->fresh()->effectiveLimit('branches'));
    }

    // ── §12 Flujos del superadmin ────────────────────────────────────────────

    public function test_alta_de_clinica_crea_suscripcion_de_prueba_y_admin(): void
    {
        $plan = \App\Models\Plan::where('slug', 'clinica')->firstOrFail();

        $this->actingAs($this->user('superadmin@dexi.test'))->post('/admin/companies', [
            'name' => 'Clínica Nueva',
            'plan_id' => $plan->id,
            'admin_name' => 'admin_nueva',
            'admin_email' => 'admin@nueva.test',
            'admin_password' => 'Secreta123',
        ])->assertRedirect();

        $company = Company::where('name', 'Clínica Nueva')->firstOrFail();
        $this->assertTrue($company->subscription->onTrial());
        $this->assertSame('admin', $company->getRoleForUser($this->user('admin@nueva.test'))?->slug);
    }

    public function test_superadmin_edita_overrides_desde_el_formulario(): void
    {
        $sub = $this->sonrisa->subscription;

        $this->actingAs($this->user('superadmin@dexi.test'))->put("/admin/subscriptions/{$sub->id}", [
            'plan_id' => $sub->plan_id,
            'status' => 'active',
            'starts_at' => today()->toDateString(),
            'period_end' => today()->addMonth()->toDateString(),
            'grace_days' => 5,
            'max_branches_override' => 4,
            'customize_features' => 1,
            'features_override' => ['agenda', 'estadisticas'],
        ])->assertRedirect();

        $sub->refresh();
        $this->assertSame(4, $sub->effectiveLimitFor('branches'));
        $this->assertSame(['agenda', 'estadisticas'], $sub->effectiveFeatures());
        $this->assertNull($sub->max_users_override);   // vacío = hereda del plan
        $this->assertSame(5, $sub->grace_days);
    }

    // ── §11 API ──────────────────────────────────────────────────────────────

    public function test_api_me_expone_permisos_plan_y_suscripcion(): void
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@sonrisa.test',
            'password' => 'Admin@1234',
            'device_name' => 'test',
        ])->assertOk()->json('token');

        $this->withToken($token)
            ->withHeader('X-Company-Id', (string) $this->sonrisa->id)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('company.id', $this->sonrisa->id)
            ->assertJsonPath('plan.name', 'Consultorio')
            ->assertJsonPath('subscription.allows_write', true)
            ->assertJsonFragment(['branches.create']);

        // Clínica ajena → 403 company_forbidden.
        $this->withToken($token)
            ->withHeader('X-Company-Id', (string) $this->demo->id)
            ->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'company_forbidden');
    }
}
