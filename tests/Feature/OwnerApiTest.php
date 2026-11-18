<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Company;
use App\Models\CommissionRule;
use App\Models\ConsultationProcedure;
use App\Models\Patient;
use App\Models\PaymentMethod;
use App\Models\Personal;
use App\Models\Procedure;
use App\Models\User;
use App\Support\Cash;
use App\Support\Consultations;
use App\Support\Sales;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API de la fase 6 de la app: el resumen para el dueño, las comisiones de
 * cada odontólogo y pagar una liquidación, y cambiar la contraseña.
 */
class OwnerApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $demo;

    private User $admin;

    private User $dentist;

    private Personal $ana;

    private $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->demo = Company::where('ruc', '1000000001')->firstOrFail();
        $this->admin = User::where('email', 'admin@clinicademo.test')->firstOrFail();
        $this->dentist = User::where('email', 'odontologo@clinicademo.test')->firstOrFail();

        app(Tenancy::class)->runFor($this->demo->id, function () {
            auth()->setUser($this->admin);
            PaymentMethod::ensureDefaults();
            Procedure::loadSuggested();
            Procedure::where('code', 'GEN-01')->update(['price' => 100]);
            $this->ana = Personal::create(['full_name' => 'Ana Ruiz', 'active' => true, 'attends_patients' => true, 'user_id' => $this->dentist->id]);
            CommissionRule::create(['applies_to' => 'todos', 'type' => 'porcentaje', 'value' => 30, 'active' => true]);
            $this->session = Cash::open(Caja::create(['name' => 'Caja 1', 'active' => true]), 0);

            // Ana atiende y se cobra 100: gana 30 de comisión.
            $maria = Patient::create(['number' => 1, 'first_name' => 'María', 'last_name' => 'Rojas', 'active' => true]);
            $consultation = Consultations::start($maria, null, $this->ana->id);
            Consultations::addProcedure($consultation, ['procedure_id' => Procedure::where('code', 'GEN-01')->value('id')]);
            Sales::create([
                'patient_id' => $maria->id, 'cash_session_id' => $this->session->id,
                'items' => ConsultationProcedure::pluck('id')->map(fn ($id) => ['source' => 'consultation', 'id' => $id])->all(),
                'payments' => [['payment_method_id' => PaymentMethod::where('type', 'cash')->value('id'), 'amount' => 100]],
            ]);
        });
        $this->app['auth']->forgetGuards();
    }

    private function api(?User $user = null)
    {
        $this->app['auth']->forgetGuards();
        $token = ($user ?? $this->admin)->createToken('test')->plainTextToken;

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Company-Id' => $this->demo->id, 'Accept' => 'application/json']);
    }

    public function test_resumen_para_el_dueno(): void
    {
        $summary = $this->api()->getJson('/api/v1/reports/summary?preset=this_month')->assertOk()
            ->assertJsonPath('period.preset', 'this_month')
            ->assertJsonPath('totals.income', 100)
            ->assertJsonPath('professionals.0.name', 'Ana Ruiz')
            ->assertJsonStructure(['totals' => ['income', 'cost', 'expenses', 'profit'], 'deltas', 'chart' => ['labels', 'income', 'expenses'], 'insights', 'attention', 'period' => ['presets']]);
        // Las negritas llegan como **texto**, sin HTML.
        $this->assertStringContainsString('**', $summary->json('insights.0.text'));
        $this->assertStringNotContainsString('<', $summary->json('insights.0.text'));
        $this->assertSame('commissions', collect($summary->json('attention'))->firstWhere('key', 'commissions')['key']);

        // El odontólogo no ve las cifras de dinero.
        $this->api($this->dentist)->getJson('/api/v1/reports/summary')->assertForbidden();
    }

    public function test_reportes_por_seccion_respetan_los_permisos(): void
    {
        // El admin (con reports.finance) ve todas las secciones, cada una con sus bloques.
        $sections = $this->api()->getJson('/api/v1/reports')->assertOk()->assertJsonPath('can_money', true)->json('sections');
        $this->assertSame(['resumen', 'finanzas', 'ventas', 'pacientes', 'profesionales', 'inventario'], array_column($sections, 'key'));
        foreach (['finanzas', 'ventas', 'pacientes', 'profesionales', 'inventario'] as $key) {
            $this->api()->getJson("/api/v1/reports/{$key}?preset=this_month")->assertOk()
                ->assertJsonPath('section', $key)->assertJsonStructure(['kpis', 'charts', 'lists', 'period' => ['label']]);
        }
        $this->api()->getJson('/api/v1/reports/finanzas')->assertJsonPath('kpis.0.label', 'Cobrado')->assertJsonPath('kpis.0.value', 100);
        $this->api()->getJson('/api/v1/reports/profesionales')->assertJsonPath('lists.0.format', 'money')->assertJsonPath('lists.0.rows.0.value', 100);

        // El odontólogo (solo reports.view): lo clínico y el inventario, sin cifras de dinero.
        $mine = $this->api($this->dentist)->getJson('/api/v1/reports')->assertOk()->assertJsonPath('can_money', false)->json('sections');
        $this->assertSame(['pacientes', 'profesionales', 'inventario'], array_column($mine, 'key'));
        $this->api($this->dentist)->getJson('/api/v1/reports/finanzas')->assertForbidden();
        $this->api($this->dentist)->getJson('/api/v1/reports/ventas')->assertForbidden();
        $this->api($this->dentist)->getJson('/api/v1/reports/profesionales')->assertOk()
            ->assertJsonPath('lists.0.format', 'count')->assertJsonPath('lists.0.rows.0.value', 1)
            ->assertJsonPath('lists.0.rows.0.hint', fn ($hint) => ! str_contains($hint, 'Bs'));
        $inventory = $this->api($this->dentist)->getJson('/api/v1/reports/inventario')->assertOk();
        $this->assertNotContains('Valor del inventario', array_column($inventory->json('kpis'), 'label'));
        $this->assertNotContains('money', array_column($inventory->json('lists'), 'format'));
    }

    public function test_mis_comisiones_y_pagar_la_liquidacion(): void
    {
        $this->api($this->dentist)->getJson('/api/v1/me/commissions')->assertOk()
            ->assertJsonPath('name', 'Ana Ruiz')->assertJsonPath('pending_total', 30)->assertJsonPath('data.0.amount', 30);
        // Pero no las de todos.
        $this->api($this->dentist)->getJson('/api/v1/commissions/pending')->assertForbidden();
        // Un usuario sin ficha de odontólogo.
        $this->api()->getJson('/api/v1/me/commissions')->assertNotFound();

        $pending = $this->api()->getJson('/api/v1/commissions/pending')->assertOk()->assertJsonPath('total', 30)->assertJsonPath('data.0.personal_id', $this->ana->id);
        $this->api()->postJson("/api/v1/commissions/{$this->ana->id}/settle", [
            'period_start' => $pending->json('data.0.since'), 'period_end' => today()->toDateString(),
            'source' => 'cash', 'cash_session_id' => $this->session->id,
        ])->assertCreated()->assertJsonPath('total', 30);
        $this->api()->getJson('/api/v1/commissions/pending')->assertJsonPath('total', 0);
        $this->assertEqualsWithDelta(70, $this->session->fresh()->expectedCash(), 0.01);
    }

    public function test_cambiar_la_contrasena(): void
    {
        $other = $this->dentist->createToken('otro telefono');
        $this->api($this->dentist)->putJson('/api/v1/me/password', ['current_password' => 'mal', 'password' => 'NuevaClave2026', 'password_confirmation' => 'NuevaClave2026'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->api($this->dentist)->putJson('/api/v1/me/password', ['current_password' => SuperAdminSeeder::PASSWORD, 'password' => 'NuevaClave2026', 'password_confirmation' => 'otra'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->api($this->dentist)->putJson('/api/v1/me/password', ['current_password' => SuperAdminSeeder::PASSWORD, 'password' => 'NuevaClave2026', 'password_confirmation' => 'NuevaClave2026'])
            ->assertOk();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('NuevaClave2026', $this->dentist->fresh()->password));
        // Las otras sesiones se cerraron.
        $this->assertNull(\Laravel\Sanctum\PersonalAccessToken::find($other->accessToken->id));
    }
}
