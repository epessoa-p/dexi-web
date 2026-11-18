<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\CashSession;
use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\CommissionSettlement;
use App\Models\Company;
use App\Models\Consultation;
use App\Models\ConsultationProcedure;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Patient;
use App\Models\PaymentMethod;
use App\Models\Personal;
use App\Models\Procedure;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Support\Cash;
use App\Support\Consultations;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comisiones: quién atendió cada línea, la regla más específica, sobre lo
 * cobrado o al vender, descuento prorrateado, liquidación como gasto,
 * anulaciones con ajuste negativo, permisos, plan, aislamiento y API.
 */
class CommissionTest extends TestCase
{
    use RefreshDatabase;

    private Company $demo;

    private User $admin;

    private Personal $ana;

    private Personal $carlos;

    private Patient $maria;

    private Consultation $consultation;

    private CashSession $session;

    private PaymentMethod $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->demo = Company::where('ruc', '1000000001')->firstOrFail();
        $this->admin = User::where('email', 'admin@clinicademo.test')->firstOrFail();

        app(Tenancy::class)->runFor($this->demo->id, function () {
            PaymentMethod::ensureDefaults();
            $this->cash = PaymentMethod::where('type', 'cash')->firstOrFail();
            Procedure::loadSuggested();
            Procedure::where('code', 'GEN-01')->update(['price' => 100]);
            Procedure::where('code', 'OPE-01')->update(['price' => 200]);
            $this->ana = Personal::create(['full_name' => 'Ana Ruiz', 'active' => true, 'attends_patients' => true]);
            $this->carlos = Personal::create(['full_name' => 'Carlos Méndez', 'active' => true, 'attends_patients' => true]);
            $this->maria = Patient::create(['number' => 1, 'first_name' => 'María', 'last_name' => 'Rojas', 'active' => true]);

            $this->consultation = Consultations::start($this->maria, null, $this->ana->id);
            Consultations::addProcedure($this->consultation, ['procedure_id' => Procedure::where('code', 'GEN-01')->value('id')]);
            Consultations::addProcedure($this->consultation, ['procedure_id' => Procedure::where('code', 'OPE-01')->value('id'), 'tooth' => 16, 'surfaces' => ['O']]);

            // Equipo: 30 % de todo. Ana en operatoria (OPE-01): 50 %.
            CommissionRule::create(['applies_to' => 'todos', 'type' => 'porcentaje', 'value' => 30, 'active' => true]);
            CommissionRule::create(['personal_id' => $this->ana->id, 'applies_to' => 'procedimiento', 'procedure_id' => Procedure::where('code', 'OPE-01')->value('id'),
                'type' => 'porcentaje', 'value' => 50, 'active' => true]);

            $this->session = Cash::open(Caja::create(['name' => 'Caja 1', 'active' => true]), 500);
        });
    }

    private function as(?User $user = null)
    {
        $this->flushHeaders();

        return $this->actingAs($user ?? $this->admin)->withSession(['current_company_id' => $this->demo->id]);
    }

    private function sell(float $paid, array $extra = []): Sale
    {
        $items = ConsultationProcedure::where('consultation_id', $this->consultation->id)->pluck('id')
            ->map(fn ($id) => ['source' => 'consultation', 'id' => $id])->all();
        $this->withoutExceptionHandling();
        $this->as()->post(route('sales.store'), $extra + [
            'patient_id' => $this->maria->id, 'cash_session_id' => $this->session->id, 'items' => $items,
            'payments' => $paid > 0 ? [['payment_method_id' => $this->cash->id, 'amount' => $paid]] : [],
            'due_date' => now()->addDays(10)->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->withExceptionHandling();

        return Sale::allCompanies()->latest('id')->firstOrFail();
    }

    private function earned(Personal $p): float
    {
        return round((float) Commission::allCompanies()->where('personal_id', $p->id)->sum('amount'), 2);
    }

    public function test_sobre_lo_cobrado_con_la_regla_mas_especifica(): void
    {
        // Total 300: consulta 100 (30 % = 30) + resina 200 (Ana 50 % = 100) = 130 si se cobra todo.
        $sale = $this->sell(150);   // la mitad
        $this->assertSame($this->ana->id, $sale->items()->first()->personal_id);
        $this->assertSame(65.0, $this->earned($this->ana));

        $this->as()->post(route('sales.payment', $sale), ['amount' => 150, 'payment_method_id' => $this->cash->id, 'cash_session_id' => $this->session->id])
            ->assertSessionHasNoErrors();
        $this->assertSame(130.0, $this->earned($this->ana));
        $this->assertSame(0.0, $this->earned($this->carlos));
    }

    public function test_al_vender_con_descuento_prorrateado_y_monto_fijo(): void
    {
        app(Tenancy::class)->runFor($this->demo->id, function () {
            settings()->set('clinic.commission_basis', 'vendido', $this->demo->id, 'string', 'clinic');
            // Consulta GEN-01 para todo el equipo: 20 fijos (más específica que el 30 %).
            CommissionRule::create(['applies_to' => 'procedimiento', 'procedure_id' => Procedure::where('code', 'GEN-01')->value('id'),
                'type' => 'monto_fijo', 'value' => 20, 'active' => true]);
        });

        $this->sell(0, ['discount' => 30]);   // a crédito, 10 % de descuento global
        // Consulta: 20 fijo. Resina: base 200 × 0.9 = 180 → 50 % = 90.
        $this->assertSame(110.0, $this->earned($this->ana));
        $this->assertSame(180.0, (float) Commission::allCompanies()->where('type', 'porcentaje')->value('base'));
    }

    public function test_lo_suelto_va_a_quien_atendio_y_sin_regla_no_hay_comision(): void
    {
        $this->as()->post(route('sales.store'), [
            'patient_id' => $this->maria->id, 'cash_session_id' => $this->session->id, 'personal_id' => $this->carlos->id,
            'items' => [['source' => 'free', 'description' => 'Blanqueamiento', 'unit_price' => 400]],
            'payments' => [['payment_method_id' => $this->cash->id, 'amount' => 400]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(120.0, $this->earned($this->carlos));

        app(Tenancy::class)->runFor($this->demo->id, fn () => CommissionRule::query()->update(['active' => false]));
        $this->as()->post(route('sales.store'), [
            'cash_session_id' => $this->session->id, 'personal_id' => $this->carlos->id,
            'items' => [['source' => 'free', 'description' => 'Cepillo', 'unit_price' => 50]],
            'payments' => [['payment_method_id' => $this->cash->id, 'amount' => 50]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(120.0, $this->earned($this->carlos));
    }

    public function test_liquidar_paga_como_gasto_y_anular_devuelve_todo(): void
    {
        $this->sell(300);
        $this->as()->get(route('commissions.index'))->assertOk()->assertSee('Comisiones por pagar')->assertSee('Ana Ruiz');

        $this->as()->post(route('commissions.settle', $this->ana), [
            'period_start' => today()->toDateString(), 'period_end' => today()->toDateString(),
            'source' => 'cash', 'cash_session_id' => $this->session->id,
        ])->assertSessionHasNoErrors();

        $settlement = CommissionSettlement::allCompanies()->firstOrFail();
        $this->assertSame(130.0, (float) $settlement->total);
        $expense = Expense::allCompanies()->findOrFail($settlement->expense_id);
        $this->assertSame(ExpenseCategory::COMMISSIONS, $expense->category->name);
        $this->assertSame($this->ana->id, $expense->personal_id);
        $this->assertSame(500 + 300 - 130.0, $this->session->fresh()->expectedCash());
        $this->assertSame(0, Commission::allCompanies()->pending()->count());

        // El gasto no se anula suelto: se anula la liquidación.
        $this->as()->post(route('expenses.cancel', $expense), ['cancel_reason' => 'x'])->assertSessionHasErrors('cancel_reason');
        $this->as()->get(route('commissions.settlement', $settlement))->assertOk()->assertSee('L-000001');
        $this->as()->post(route('commissions.settlement.cancel', $settlement), ['cancel_reason' => 'Error'])->assertSessionHasNoErrors();
        $this->assertSame(800.0, $this->session->fresh()->expectedCash());   // los 130 volvieron a la caja
        $this->assertSame(2, Commission::allCompanies()->pending()->count());
    }

    public function test_anular_una_venta_ya_liquidada_descuenta_en_la_proxima(): void
    {
        $sale = $this->sell(300);
        $this->as()->post(route('commissions.settle', $this->ana), [
            'period_start' => today()->toDateString(), 'period_end' => today()->toDateString(), 'source' => 'cash', 'cash_session_id' => $this->session->id,
        ])->assertSessionHasNoErrors();

        $this->as()->post(route('sales.cancel', $sale), ['cancel_reason' => 'Error', 'payment_method_id' => $this->cash->id, 'cash_session_id' => $this->session->id])
            ->assertSessionHasNoErrors();
        $this->assertSame(-130.0, round((float) Commission::allCompanies()->pending()->sum('amount'), 2));

        // Una venta nueva de 300 genera 130: la próxima liquidación queda en 0 y no se puede pagar.
        app(Tenancy::class)->runFor($this->demo->id, fn () => ConsultationProcedure::query()->update(['sale_item_id' => null]));
        $this->sell(300);
        $this->as()->post(route('commissions.settle', $this->ana), [
            'period_start' => today()->toDateString(), 'period_end' => today()->toDateString(), 'source' => 'cash', 'cash_session_id' => $this->session->id,
        ])->assertSessionHasErrors('period_start');
    }

    public function test_reglas_permisos_plan_aislamiento_y_api(): void
    {
        // Regla duplicada y porcentaje imposible.
        $this->as()->post(route('commissions.rules.store'), ['applies_to' => 'todos', 'type' => 'porcentaje', 'value' => 25])->assertSessionHasErrors('applies_to');
        $this->as()->post(route('commissions.rules.store'), ['personal_id' => $this->carlos->id, 'applies_to' => 'todos', 'type' => 'porcentaje', 'value' => 120])->assertSessionHasErrors('value');
        $this->as()->get(route('commissions.rules'))->assertOk()->assertSee('Todo el equipo')->assertSee('50 %');

        // El odontólogo ve solo lo suyo; no la pantalla general.
        $this->sell(300);
        $user = User::create(['name' => 'ana', 'email' => 'ana@clinicademo.test', 'password' => 'Secreta123']);
        $user->companies()->attach($this->demo->id, ['role_id' => Role::where('slug', 'odontologo')->value('id'), 'active' => true]);
        app(Tenancy::class)->runFor($this->demo->id, fn () => $this->ana->update(['user_id' => $user->id]));
        $this->as($user)->get(route('commissions.index'))->assertForbidden();
        $this->as($user)->get(route('commissions.mine'))->assertOk()->assertSee('Resina');

        $sonrisa = User::where('email', 'admin@sonrisa.test')->firstOrFail();
        $this->actingAs($sonrisa)->get(route('commissions.index'))->assertForbidden();   // plan Consultorio
        $sonrisa->companies()->first()->subscription->update(['features_override' => ['comisiones', 'caja']]);
        $this->actingAs($sonrisa)->get(route('commissions.index'))->assertOk()->assertDontSee('Ana Ruiz');
        $this->actingAs($sonrisa)->get(route('commissions.professional', $this->ana))->assertNotFound();

        $this->as()->get(route('expenses.index', ['tab' => 'personal']))->assertOk()->assertSee('Comisiones por pagar');

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $token = $this->admin->createToken('t')->plainTextToken;
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Company-Id' => $this->demo->id])
            ->getJson('/api/v1/commissions/pending')->assertOk()->assertJsonPath('total', 130)->assertJsonPath('data.0.name', 'Ana Ruiz');
    }
}
