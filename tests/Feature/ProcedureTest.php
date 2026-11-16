<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Procedure;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Tarifario: catálogo sugerido, alta/edición, precio rápido, ajuste por %, especialidades, permisos y API. */
class ProcedureTest extends TestCase
{
    use RefreshDatabase;

    private Company $demo;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->demo = Company::where('ruc', '1000000001')->firstOrFail();
        $this->admin = User::where('email', 'admin@clinicademo.test')->firstOrFail();
    }

    private function as(?User $user = null)
    {
        $this->flushHeaders();

        return $this->actingAs($user ?? $this->admin)->withSession(['current_company_id' => $this->demo->id]);
    }

    private function procedure(string $code): Procedure
    {
        return Procedure::allCompanies()->where('company_id', $this->demo->id)->where('code', $code)->firstOrFail();
    }

    public function test_primera_vez_ofrece_el_catalogo_sugerido_y_lo_carga_sin_duplicar(): void
    {
        $this->as()->get(route('procedures.index'))->assertOk()->assertSee('Cargar catálogo sugerido');

        $total = collect(Procedure::SUGGESTED)->flatten(1)->count();
        $this->as()->post(route('procedures.suggested'))->assertRedirect(route('procedures.index', ['filter' => 'no_price']));
        $this->assertSame($total, Procedure::allCompanies()->where('company_id', $this->demo->id)->count());
        $this->assertSame('Endodoncia', $this->procedure('END-01')->specialty->name);
        $this->assertSame('pieza', $this->procedure('END-01')->applies_to);

        // Otra vez no duplica.
        $this->as()->post(route('procedures.suggested'));
        $this->assertSame($total, Procedure::allCompanies()->where('company_id', $this->demo->id)->count());

        $this->as()->get(route('procedures.index', ['filter' => 'no_price']))->assertOk()->assertSee('Endodoncia unirradicular')->assertSee('Enter');
    }

    public function test_alta_edicion_codigo_unico_y_rango_de_precio(): void
    {
        $this->as()->post(route('procedures.store'), [
            'name' => 'Resina estética', 'code' => 'ope-99', 'price' => 150, 'price_max' => 250, 'duration_minutes' => 45, 'applies_to' => 'cara',
        ])->assertSessionHasNoErrors();
        $resina = $this->procedure('OPE-99');
        $this->assertSame('250.00', $resina->price_max);
        $this->assertSame(45, $resina->duration_minutes);

        $this->as()->post(route('procedures.store'), ['name' => 'Otra', 'code' => 'OPE-99', 'price' => 1, 'duration_minutes' => 30, 'applies_to' => 'pieza'])
            ->assertSessionHasErrors('code');
        $this->as()->post(route('procedures.store'), ['name' => 'Mal', 'price' => 200, 'price_max' => 100, 'duration_minutes' => 30, 'applies_to' => 'pieza'])
            ->assertSessionHasErrors('price_max');

        $this->as()->put(route('procedures.update', $resina), [
            'name' => 'Resina estética', 'code' => 'OPE-99', 'price' => 180, 'duration_minutes' => 60, 'applies_to' => 'cara', 'color' => '#ff0000',
        ])->assertSessionHasNoErrors();
        $resina->refresh();
        $this->assertSame('180.00', $resina->price);
        $this->assertNull($resina->price_max);
        $this->assertFalse($resina->active); // el switch no vino
        $this->assertSame('#ff0000', $resina->calendar_color);
    }

    public function test_precio_rapido_y_ajuste_por_porcentaje(): void
    {
        $this->as()->post(route('procedures.suggested'));
        $consulta = $this->procedure('GEN-01');
        $endo = $this->procedure('END-01');

        $this->as()->patchJson(route('procedures.price', $consulta), ['price' => 100])->assertOk()->assertJsonPath('price', 100);
        $this->as()->patch(route('procedures.price', $endo), ['price' => 333])->assertSessionHasNoErrors();

        // +10 % solo a Endodoncia, redondeado a 0.50.
        $this->as()->post(route('procedures.adjust'), ['percent' => 10, 'specialty_id' => $endo->specialty_id, 'round' => 1])->assertSessionHasNoErrors();
        $this->assertSame('366.50', $endo->fresh()->price);
        $this->assertSame('100.00', $consulta->fresh()->price);

        // Todo el tarifario −5 %; los que no tienen precio siguen en 0.
        $this->as()->post(route('procedures.adjust'), ['percent' => -5])->assertSessionHasNoErrors();
        $this->assertSame('95.00', $consulta->fresh()->price);
        $this->assertSame('0.00', $this->procedure('GEN-02')->price);

        $this->as()->post(route('procedures.adjust'), ['percent' => 0])->assertSessionHasErrors('percent');
    }

    public function test_especialidades_con_procedimientos_solo_se_desactivan(): void
    {
        $this->as()->get(route('specialties.index'))->assertOk()->assertSee('Endodoncia');
        $this->as()->post(route('procedures.suggested'));
        $endo = Specialty::allCompanies()->where('company_id', $this->demo->id)->where('name', 'Endodoncia')->firstOrFail();

        $this->as()->delete(route('specialties.destroy', $endo))->assertSessionHasNoErrors();
        $this->assertFalse($endo->fresh()->active);

        $this->as()->post(route('specialties.store'), ['name' => 'Estética facial', 'color' => '#123456'])->assertSessionHasNoErrors();
        $this->as()->post(route('specialties.store'), ['name' => 'Estética facial', 'color' => '#123456'])->assertSessionHasErrors('name');
        $new = Specialty::allCompanies()->where('name', 'Estética facial')->firstOrFail();
        $this->as()->delete(route('specialties.destroy', $new));
        $this->assertNull($new->fresh());
    }

    public function test_aislamiento_permisos_y_api(): void
    {
        $this->as()->post(route('procedures.suggested'));
        $endo = $this->procedure('END-01');

        $sonrisa = User::where('email', 'admin@sonrisa.test')->firstOrFail();
        $this->actingAs($sonrisa)->patch(route('procedures.price', $endo), ['price' => 1])->assertNotFound();

        $cajero = User::create(['name' => 'caj', 'email' => 'caj@clinicademo.test', 'password' => 'Secreta123']);
        $cajero->companies()->attach($this->demo->id, ['role_id' => Role::where('slug', 'cajero')->value('id'), 'active' => true]);
        $this->as($cajero)->get(route('procedures.index'))->assertOk()->assertDontSee('Ajustar precios');
        $this->as($cajero)->patch(route('procedures.price', $endo), ['price' => 1])->assertForbidden();

        app(Tenancy::class)->runFor($this->demo->id, fn () => $endo->update(['price' => 450]));
        $token = $this->admin->createToken('t')->plainTextToken;
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json', 'X-Company-Id' => (string) $this->demo->id])
            ->getJson('/api/v1/procedures?q=unirradicular')->assertOk()
            ->assertJsonPath('data.0.code', 'END-01')->assertJsonPath('data.0.price', 450)->assertJsonPath('data.0.applies_to', 'pieza');
    }
}
