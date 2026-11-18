<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\CashSession;
use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Expense;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\PaymentMethod;
use App\Models\Personal;
use App\Models\Procedure;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Support\Cash;
use App\Support\Tenancy;
use App\Support\TreatmentPlans;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Laboratorio dental: orden de trabajo, recorrido con devolución para ajuste,
 * pago de varias órdenes con un gasto (y su anulación), costo descontado de
 * la comisión, permisos, plan y aislamiento.
 */
class LabOrderTest extends TestCase
{
    use RefreshDatabase;

    private Company $demo;

    private User $admin;

    private Patient $maria;

    private Personal $ana;

    private Supplier $lab;

    private CashSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->demo = Company::where('ruc', '1000000001')->firstOrFail();
        $this->admin = User::where('email', 'admin@clinicademo.test')->firstOrFail();

        app(Tenancy::class)->runFor($this->demo->id, function () {
            $this->ana = Personal::create(['full_name' => 'Ana Ruiz', 'active' => true, 'attends_patients' => true]);
            $this->maria = Patient::create(['number' => 1, 'first_name' => 'María', 'last_name' => 'Rojas', 'active' => true]);
            $this->lab = Supplier::create(['name' => 'Lab Cerámico', 'active' => true]);
            PaymentMethod::ensureDefaults();
            $this->session = Cash::open(Caja::create(['name' => 'Caja 1', 'active' => true]), 1000);
        });
    }

    private function as(?User $user = null)
    {
        $this->flushHeaders();

        return $this->actingAs($user ?? $this->admin)->withSession(['current_company_id' => $this->demo->id]);
    }

    private function order(array $extra = []): LabOrder
    {
        $this->as()->post(route('lab-orders.store'), $extra + [
            'patient_id' => $this->maria->id, 'supplier_id' => $this->lab->id, 'personal_id' => $this->ana->id,
            'work_type' => 'corona', 'teeth' => '16', 'material' => 'Zirconio', 'shade' => 'A2',
            'sent_at' => today()->toDateString(), 'due_at' => today()->addDays(7)->toDateString(), 'cost' => 300,
        ])->assertSessionHasNoErrors();

        return LabOrder::allCompanies()->latest('id')->firstOrFail();
    }

    public function test_orden_y_su_recorrido_con_devolucion(): void
    {
        $order = $this->order();
        $this->assertSame('OT-000001', $order->code);
        $this->assertSame('Corona · Zirconio · pieza 16 · A2', $order->summary);
        $this->as()->get(route('lab-orders.show', $order))->assertOk()->assertSee('LAB CERÁMICO');
        $this->as()->get(route('lab-orders.print', $order))->assertOk()->assertSee('ORDEN DE TRABAJO')->assertSee('A2');

        // No se instala algo que no llegó.
        $this->as()->post(route('lab-orders.status', $order), ['status' => 'instalado'])->assertSessionHasErrors('status');
        $this->as()->post(route('lab-orders.status', $order), ['status' => 'recibido'])->assertSessionHasNoErrors();
        $this->as()->post(route('lab-orders.status', $order), ['status' => 'en_proceso', 'note' => 'Contacto alto'])->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame(1, $order->returns_count);
        $this->assertNull($order->received_at);
        $this->as()->post(route('lab-orders.status', $order), ['status' => 'recibido'])->assertSessionHasNoErrors();
        $this->as()->post(route('lab-orders.status', $order), ['status' => 'instalado'])->assertSessionHasNoErrors();
        $this->assertNotNull($order->fresh()->installed_at);
        $this->as()->get(route('lab-orders.show', $order))->assertSee('Devuelto al laboratorio para ajuste · Contacto alto');

        // Atrasado en la lista y en el dashboard.
        $late = $this->order(['sent_at' => today()->subDays(10)->toDateString(), 'due_at' => today()->subDays(2)->toDateString()]);
        $this->as()->get(route('lab-orders.index', ['filter' => 'atrasados']))->assertOk()->assertSee($late->code)->assertDontSee($order->code);
        $this->as()->get(route('dashboard'))->assertSee('trabajo de laboratorio atrasado');
        $this->as()->get(route('patients.show', [$this->maria, 'tab' => 'tratamiento']))->assertOk()->assertSee('Trabajos de laboratorio')->assertSee($late->code);
    }

    public function test_pagar_varias_ordenes_con_un_gasto_y_anularlo(): void
    {
        $a = $this->order();
        $b = $this->order(['work_type' => 'carilla', 'teeth' => '11', 'cost' => 250]);
        $this->as()->get(route('lab-orders.index', ['filter' => 'pagar']))->assertOk()->assertSee('550.00');

        $this->as()->post(route('lab-orders.pay', $this->lab), ['orders' => [$a->id, $b->id], 'source' => 'cash', 'cash_session_id' => $this->session->id])
            ->assertSessionHasNoErrors();
        $expense = Expense::allCompanies()->latest('id')->firstOrFail();
        $this->assertSame(550.0, (float) $expense->amount);
        $this->assertSame($this->lab->id, $expense->supplier_id);
        $this->assertStringContainsString('OT-000001, OT-000002', $expense->concept);
        $this->assertSame($expense->id, $a->fresh()->expense_id);
        $this->assertSame(450.0, $this->session->fresh()->expectedCash());

        // Pagado: no se cambia el costo ni se cancela.
        $this->as()->put(route('lab-orders.update', $a), ['supplier_id' => $this->lab->id, 'work_type' => 'corona', 'sent_at' => today()->toDateString(), 'cost' => 999])
            ->assertSessionHasErrors('cost');
        $this->as()->post(route('lab-orders.status', $a), ['status' => 'cancelado', 'note' => 'x'])->assertSessionHasErrors('note');

        $this->as()->post(route('expenses.cancel', $expense), ['cancel_reason' => 'Error'])->assertSessionHasNoErrors();
        $this->assertNull($a->fresh()->expense_id);
        $this->assertSame(1000.0, $this->session->fresh()->expectedCash());
    }

    public function test_el_costo_del_laboratorio_se_descuenta_de_la_comision(): void
    {
        [$plan, $item] = app(Tenancy::class)->runFor($this->demo->id, function () {
            Procedure::loadSuggested();
            $crown = Procedure::where('code', 'like', 'PRO-%')->orderBy('id')->firstOrFail();
            $crown->update(['price' => 1000]);
            CommissionRule::create(['applies_to' => 'todos', 'type' => 'porcentaje', 'value' => 40, 'active' => true]);
            $plan = TreatmentPlans::create($this->maria, ['title' => 'Corona', 'personal_id' => $this->ana->id]);
            TreatmentPlans::addItem($plan, ['procedure_id' => $crown->id, 'tooth' => 16]);
            TreatmentPlans::setStatus($plan, 'aprobado', 'María Rojas');

            return [$plan, $plan->items()->firstOrFail()];
        });
        $this->order(['treatment_plan_item_id' => $item->id, 'cost' => 300]);

        $cash = app(Tenancy::class)->runFor($this->demo->id, fn () => PaymentMethod::where('type', 'cash')->value('id'));
        $this->as()->post(route('sales.store'), ['patient_id' => $this->maria->id, 'cash_session_id' => $this->session->id,
            'items' => [['source' => 'plan', 'id' => $item->id]], 'payments' => [['payment_method_id' => $cash, 'amount' => 1000]]])->assertSessionHasNoErrors();

        // 40 % de (1000 − 300) = 280.
        $commission = Commission::allCompanies()->firstOrFail();
        $this->assertSame(700.0, (float) $commission->base);
        $this->assertSame(280.0, (float) $commission->amount);
    }

    public function test_permisos_plan_y_aislamiento(): void
    {
        $order = $this->order();
        $cajero = User::create(['name' => 'caj', 'email' => 'caj@clinicademo.test', 'password' => 'Secreta123']);
        $cajero->companies()->attach($this->demo->id, ['role_id' => Role::where('slug', 'cajero')->value('id'), 'active' => true]);
        $this->as($cajero)->get(route('lab-orders.index'))->assertForbidden();

        $recep = User::create(['name' => 'rec', 'email' => 'rec@clinicademo.test', 'password' => 'Secreta123']);
        $recep->companies()->attach($this->demo->id, ['role_id' => Role::where('slug', 'recepcionista')->value('id'), 'active' => true]);
        $this->as($recep)->post(route('lab-orders.status', $order), ['status' => 'recibido'])->assertSessionHasNoErrors();
        $this->as($recep)->get(route('lab-orders.create'))->assertForbidden();

        $sonrisa = User::where('email', 'admin@sonrisa.test')->firstOrFail();
        $this->actingAs($sonrisa)->get(route('lab-orders.index'))->assertForbidden();   // plan Consultorio
        $sonrisa->companies()->first()->subscription->update(['features_override' => ['laboratorio']]);
        $this->actingAs($sonrisa)->get(route('lab-orders.index'))->assertOk()->assertDontSee($order->code);
        $this->actingAs($sonrisa)->get(route('lab-orders.show', $order))->assertNotFound();
        $this->actingAs($sonrisa)->post(route('lab-orders.store'), ['patient_id' => $this->maria->id, 'supplier_id' => $this->lab->id, 'work_type' => 'corona', 'sent_at' => today()->toDateString()])
            ->assertSessionHasErrors(['patient_id', 'supplier_id']);
    }
}
