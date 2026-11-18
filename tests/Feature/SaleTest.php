<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Consultation;
use App\Models\ConsultationProcedure;
use App\Models\Patient;
use App\Models\PaymentMethod;
use App\Models\Personal;
use App\Models\Procedure;
use App\Models\Role;
use App\Models\Sale;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Support\Cash;
use App\Support\Consultations;
use App\Support\Sales;
use App\Support\Tenancy;
use App\Support\TreatmentPlans;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ventas y cobros: cobrar lo realizado y los planes una sola vez, vuelto solo
 * en efectivo, saldo y abonos (FIFO), cobro por adelantado del plan, anulación
 * con devolución, caja obligatoria, permisos, plan y aislamiento.
 */
class SaleTest extends TestCase
{
    use RefreshDatabase;

    private Company $demo;

    private User $admin;

    private Patient $maria;

    private Consultation $consultation;

    private CashSession $session;

    private PaymentMethod $cash;

    private PaymentMethod $qr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->demo = Company::where('ruc', '1000000001')->firstOrFail();
        $this->admin = User::where('email', 'admin@clinicademo.test')->firstOrFail();

        app(Tenancy::class)->runFor($this->demo->id, function () {
            PaymentMethod::ensureDefaults();
            $this->cash = PaymentMethod::where('type', 'cash')->firstOrFail();
            $this->qr = PaymentMethod::where('type', 'qr')->firstOrFail();
            Procedure::loadSuggested();
            Procedure::where('code', 'GEN-01')->update(['price' => 100]);
            Procedure::where('code', 'OPE-01')->update(['price' => 180]);
            Procedure::where('code', 'END-01')->update(['price' => 700]);
            $ana = Personal::create(['full_name' => 'Ana Ruiz', 'active' => true, 'attends_patients' => true]);
            $this->maria = Patient::create(['number' => 1, 'first_name' => 'María', 'last_name' => 'Rojas', 'active' => true]);

            $this->consultation = Consultations::start($this->maria, null, $ana->id);
            Consultations::addProcedure($this->consultation, ['procedure_id' => Procedure::where('code', 'GEN-01')->value('id')]);
            Consultations::addProcedure($this->consultation, ['procedure_id' => Procedure::where('code', 'OPE-01')->value('id'), 'tooth' => 16, 'surfaces' => ['O']]);

            $caja = Caja::create(['name' => 'Caja 1', 'active' => true]);
            $this->session = Cash::open($caja, 50);
        });
    }

    private function as(?User $user = null)
    {
        $this->flushHeaders();

        return $this->actingAs($user ?? $this->admin)->withSession(['current_company_id' => $this->demo->id]);
    }

    private function consultationItems(): array
    {
        return ConsultationProcedure::where('consultation_id', $this->consultation->id)->pluck('id')
            ->map(fn ($id) => ['source' => 'consultation', 'id' => $id])->all();
    }

    private function sale(): Sale
    {
        return Sale::allCompanies()->latest('id')->firstOrFail();
    }

    public function test_cobrar_lo_realizado_en_la_consulta_con_vuelto_en_efectivo(): void
    {
        $this->as()->get(route('sales.create', ['patient_id' => $this->maria->id, 'consultation_id' => $this->consultation->id]))->assertOk()
            ->assertSee('Pendiente de cobro')->assertSee('Resina simple (1 cara) · pieza 16 O');

        // Total 280; paga 300 en efectivo: 20 de vuelto.
        $this->as()->post(route('sales.store'), [
            'patient_id' => $this->maria->id, 'cash_session_id' => $this->session->id,
            'items' => $this->consultationItems(),
            'payments' => [['payment_method_id' => $this->cash->id, 'amount' => 300]],
        ])->assertSessionHasNoErrors();

        $sale = $this->sale();
        $this->assertSame('V-000001', $sale->code);
        $this->assertSame('280.00', $sale->total);
        $this->assertSame('280.00', $sale->paid);
        $this->assertSame('0.00', $sale->balance);
        $this->assertSame(330.0, $this->session->fresh()->expectedCash()); // 50 de apertura + 280
        $this->assertSame('sale', CashMovement::allCompanies()->latest('id')->value('type'));

        // Ya no está pendiente y no se cobra dos veces.
        $this->assertTrue(app(Tenancy::class)->runFor($this->demo->id, fn () => Sales::pendingFor($this->maria)['consultation']->isEmpty()));
        $this->as()->post(route('sales.store'), ['patient_id' => $this->maria->id, 'items' => $this->consultationItems(),
            'payments' => [['payment_method_id' => $this->cash->id, 'amount' => 280]]])->assertSessionHasErrors('items');

        // Con el procedimiento cobrado, la consulta no deja quitarlo.
        $cp = ConsultationProcedure::where('consultation_id', $this->consultation->id)->first();
        $this->as()->delete(route('consultations.procedures.destroy', [$this->consultation, $cp]))->assertSessionHasErrors('error');

        $this->as()->get(route('sales.show', $sale))->assertOk()->assertSee('Pagada');
        $this->as()->get(route('sales.receipt', $sale))->assertOk()->assertSee('RECIBO V-000001');
    }

    public function test_reglas_de_pago_vuelto_referencia_saldo_y_caja(): void
    {
        $items = $this->consultationItems();

        // Solo el efectivo da vuelto.
        $this->as()->post(route('sales.store'), ['patient_id' => $this->maria->id, 'items' => $items,
            'payments' => [['payment_method_id' => $this->qr->id, 'amount' => 300, 'reference' => 'X1']]])->assertSessionHasErrors('payments');
        // QR pide referencia.
        $this->as()->post(route('sales.store'), ['patient_id' => $this->maria->id, 'items' => $items,
            'payments' => [['payment_method_id' => $this->qr->id, 'amount' => 100]]])->assertSessionHasErrors('payments');
        // Sin paciente no puede quedar saldo.
        $this->as()->post(route('sales.store'), ['items' => [['source' => 'free', 'description' => 'Cepillo', 'unit_price' => 30]],
            'payments' => [['payment_method_id' => $this->cash->id, 'amount' => 10]]])->assertSessionHasErrors('payments');
        // Sin caja abierta no se cobra.
        app(Tenancy::class)->runFor($this->demo->id, fn () => Cash::close($this->session, 50));
        $this->as()->post(route('sales.store'), ['items' => [['source' => 'free', 'description' => 'Cepillo', 'unit_price' => 30]],
            'payments' => [['payment_method_id' => $this->cash->id, 'amount' => 30]]])->assertSessionHasErrors('cash_session_id');
        // …pero a crédito total sí (no entra dinero).
        $this->as()->post(route('sales.store'), ['patient_id' => $this->maria->id, 'items' => $items, 'discount' => 30, 'due_date' => now()->addWeek()->toDateString()])
            ->assertSessionHasNoErrors();
        $sale = $this->sale();
        $this->assertSame('250.00', $sale->total);
        $this->assertSame('250.00', $sale->balance);
        $this->assertNotNull($sale->due_date);
    }

    public function test_saldo_abonos_y_abono_del_paciente_aplicado_a_lo_mas_antiguo(): void
    {
        // Venta 1: 280, paga 100 en QR.  Venta 2: 100 suelto, a crédito.
        $this->as()->post(route('sales.store'), ['patient_id' => $this->maria->id, 'items' => $this->consultationItems(),
            'payments' => [['payment_method_id' => $this->qr->id, 'amount' => 100, 'reference' => 'QR-1']]])->assertSessionHasNoErrors();
        $first = $this->sale();
        $this->assertSame('180.00', $first->balance);
        $this->as()->post(route('sales.store'), ['patient_id' => $this->maria->id, 'items' => [['source' => 'free', 'description' => 'Control', 'unit_price' => 100]]])->assertSessionHasNoErrors();
        $second = $this->sale();

        // Abono a una venta: no más que el saldo.
        $this->as()->post(route('sales.payment', $first), ['payment_method_id' => $this->cash->id, 'amount' => 500])->assertSessionHasErrors('amount');
        $this->as()->post(route('sales.payment', $first), ['payment_method_id' => $this->cash->id, 'amount' => 80])->assertSessionHasNoErrors();
        $this->assertSame('100.00', $first->fresh()->balance);

        // Abono a la cuenta del paciente: 150 → 100 a la más antigua, 50 a la siguiente.
        $this->as()->get(route('receivables.index'))->assertOk()->assertSee('María Rojas')->assertSee('200.00');
        $this->as()->post(route('receivables.pay', $this->maria), ['payment_method_id' => $this->cash->id, 'amount' => 150])->assertSessionHasNoErrors();
        $this->assertSame('0.00', $first->fresh()->balance);
        $this->assertSame('50.00', $second->fresh()->balance);
        $this->assertSame('receivable', CashMovement::allCompanies()->latest('id')->value('type'));
        $this->as()->post(route('receivables.pay', $this->maria), ['payment_method_id' => $this->cash->id, 'amount' => 999])->assertSessionHasErrors('amount');

        $this->as()->get(route('patients.show', [$this->maria, 'tab' => 'pagos']))->assertOk()->assertSee('V-000002')->assertSee('50.00');
    }

    public function test_plan_cobrado_por_adelantado_no_se_cobra_otra_vez_al_hacerlo(): void
    {
        $plan = app(Tenancy::class)->runFor($this->demo->id, function () {
            $plan = TreatmentPlans::create($this->maria, ['title' => 'Endo']);
            TreatmentPlans::addItem($plan, ['procedure_id' => Procedure::where('code', 'END-01')->value('id'), 'tooth' => 21]);
            $plan->update(['discount' => 50]);
            TreatmentPlans::setStatus($plan, 'aprobado', 'María Rojas');

            return $plan;
        });
        $item = $plan->items()->first();

        // El descuento del plan se propone al cobrarlo.
        $this->as()->get(route('sales.create', ['patient_id' => $this->maria->id, 'plan_id' => $plan->id]))->assertOk()->assertSee('value="50"', false);

        $this->as()->post(route('sales.store'), ['patient_id' => $this->maria->id, 'cash_session_id' => $this->session->id, 'discount' => 50,
            'items' => [['source' => 'plan', 'id' => $item->id]],
            'payments' => [['payment_method_id' => $this->cash->id, 'amount' => 650]]])->assertSessionHasNoErrors();
        $this->assertNotNull($item->fresh()->sale_item_id);
        $this->assertSame(650.0, Sales::billedForPlan($plan->id));

        // Se hace en la consulta («Hecho hoy»): queda cobrado, no aparece como pendiente.
        $this->as()->post(route('consultations.plan-item', [$this->consultation, $item]))->assertSessionHasNoErrors();
        $done = ConsultationProcedure::where('consultation_id', $this->consultation->id)->where('tooth', 21)->firstOrFail();
        $this->assertSame($item->fresh()->sale_item_id, $done->sale_item_id);
        $pending = app(Tenancy::class)->runFor($this->demo->id, fn () => Sales::pendingFor($this->maria));
        $this->assertFalse($pending['consultation']->contains('id', $done->id));
        $this->assertTrue($pending['plan']->isEmpty());

        $this->as()->get(route('treatment-plans.show', $plan))->assertOk()->assertSee('Cobrado');
    }

    public function test_anular_devuelve_el_dinero_y_lo_atendido_vuelve_a_pendiente(): void
    {
        $this->as()->post(route('sales.store'), ['patient_id' => $this->maria->id, 'cash_session_id' => $this->session->id, 'items' => $this->consultationItems(),
            'payments' => [['payment_method_id' => $this->cash->id, 'amount' => 280]]]);
        $sale = $this->sale();

        $this->as()->post(route('sales.cancel', $sale), ['cancel_reason' => 'Cobrado por error'])->assertSessionHasErrors('payment_method_id');
        $this->as()->post(route('sales.cancel', $sale), ['cancel_reason' => 'Cobrado por error', 'payment_method_id' => $this->cash->id, 'cash_session_id' => $this->session->id])
            ->assertSessionHasNoErrors();

        $sale->refresh();
        $this->assertSame('anulada', $sale->status);
        $this->assertSame(50.0, $this->session->fresh()->expectedCash()); // el efectivo salió
        $this->assertSame('refund', CashMovement::allCompanies()->latest('id')->value('type'));
        $this->assertCount(2, app(Tenancy::class)->runFor($this->demo->id, fn () => Sales::pendingFor($this->maria))['consultation']);
        $this->as()->post(route('sales.cancel', $sale), ['cancel_reason' => 'otra vez'])->assertSessionHasErrors('cancel_reason');
    }

    public function test_permisos_plan_y_aislamiento(): void
    {
        $this->as()->post(route('sales.store'), ['patient_id' => $this->maria->id, 'cash_session_id' => $this->session->id, 'items' => $this->consultationItems(),
            'payments' => [['payment_method_id' => $this->cash->id, 'amount' => 280]]]);
        $sale = $this->sale();

        // Cajero: cobra, pero no anula.
        $cajero = User::create(['name' => 'caj', 'email' => 'caj@clinicademo.test', 'password' => 'Secreta123']);
        $cajero->companies()->attach($this->demo->id, ['role_id' => Role::where('slug', 'cajero')->value('id'), 'active' => true]);
        $this->as($cajero)->get(route('sales.create'))->assertOk();
        $this->as($cajero)->post(route('sales.cancel', $sale), ['cancel_reason' => 'x'])->assertForbidden();

        $sonrisa = User::where('email', 'admin@sonrisa.test')->firstOrFail();
        $sonrisa->companies()->first()->subscription->update(['features_override' => ['agenda']]);
        $this->actingAs($sonrisa)->get(route('sales.index'))->assertForbidden();
        $sonrisa->companies()->first()->subscription->update(['features_override' => ['ventas']]);
        $this->actingAs($sonrisa)->get(route('sales.show', $sale))->assertNotFound();
        // Un procedimiento de otra clínica no se cobra (aunque se adivine el id).
        $this->actingAs($sonrisa)->post(route('sales.store'), ['items' => [['source' => 'consultation', 'id' => ConsultationProcedure::first()->id]]])->assertSessionHasErrors('items');

        $this->as()->get(route('sales.index', ['filter' => 'todas']))->assertOk()->assertSee('V-000001');
        $this->as()->get(route('dashboard'))->assertOk()->assertSee('Cobrado hoy');
    }
}
