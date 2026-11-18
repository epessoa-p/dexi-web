<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Caja;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Consultation;
use App\Models\ConsultationProcedure;
use App\Models\ExpenseCategory;
use App\Models\Patient;
use App\Models\PaymentMethod;
use App\Models\Personal;
use App\Models\Procedure;
use App\Models\Role;
use App\Models\User;
use App\Support\Cash;
use App\Support\Consultations;
use App\Support\Expenses;
use App\Support\Reports\ReportData;
use App\Support\Reports\ReportPeriod;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Reportes: cifras (cobrado, costo, gastos, utilidad), ventas por origen,
 * pacientes y citas, profesionales, exportación a Excel, permisos (dinero
 * aparte) y plan.
 */
class ReportTest extends TestCase
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

        app(Tenancy::class)->runFor($this->demo->id, function () {
            PaymentMethod::ensureDefaults();
            Procedure::loadSuggested();
            Procedure::where('code', 'GEN-01')->update(['price' => 100]);
            Procedure::where('code', 'OPE-01')->update(['price' => 200]);
            $ana = Personal::create(['full_name' => 'Ana Ruiz', 'active' => true, 'attends_patients' => true]);
            $maria = Patient::create(['number' => 1, 'first_name' => 'María', 'last_name' => 'Rojas', 'active' => true, 'referral_source' => array_key_first(Patient::REFERRAL_SOURCES)]);
            $consultation = Consultations::start($maria, null, $ana->id);
            Consultations::addProcedure($consultation, ['procedure_id' => Procedure::where('code', 'GEN-01')->value('id')]);
            Consultations::addProcedure($consultation, ['procedure_id' => Procedure::where('code', 'OPE-01')->value('id'), 'tooth' => 16, 'surfaces' => ['O']]);
            $session = Cash::open(Caja::create(['name' => 'Caja 1', 'active' => true]), 500);
            $cash = PaymentMethod::where('type', 'cash')->value('id');

            // Venta de 300, cobrada 250 (50 queda a crédito).
            \App\Support\Sales::create(['patient_id' => $maria->id, 'cash_session_id' => $session->id,
                'items' => ConsultationProcedure::where('consultation_id', $consultation->id)->pluck('id')->map(fn ($id) => ['source' => 'consultation', 'id' => $id])->all(),
                'payments' => [['payment_method_id' => $cash, 'amount' => 250]], 'due_date' => today()->addDays(5)->toDateString()]);

            // Gasto de 80 desde la caja.
            ExpenseCategory::ensureDefaults();
            Expenses::register(['group' => 'general', 'expense_category_id' => ExpenseCategory::group('general')->value('id'), 'concept' => 'Luz',
                'amount' => 80, 'spent_at' => today()->toDateString(), 'source' => 'cash', 'cash_session_id' => $session->id]);

            // Citas: una atendida y una a la que no vino.
            foreach (['atendida', 'no_asistio'] as $i => $status) {
                $start = today()->setTime(9 + $i, 0);
                Appointment::create(['patient_id' => $maria->id, 'personal_id' => $ana->id, 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(30), 'status' => $status]);
            }
        });
    }

    private function as(?User $user = null)
    {
        $this->flushHeaders();

        return $this->actingAs($user ?? $this->admin)->withSession(['current_company_id' => $this->demo->id]);
    }

    public function test_cifras_del_periodo(): void
    {
        app(Tenancy::class)->runFor($this->demo->id, function () {
            $data = new ReportData(ReportPeriod::fromRequest(Request::create('/', 'GET', ['preset' => 'this_month'])));
            $t = $data->finance()['totals'];
            $this->assertSame(250.0, $t['income']);
            $this->assertSame(80.0, $t['expenses']);
            $this->assertSame(170.0, $t['profit']);
            $this->assertSame(50.0, $data->finance()['receivable']);

            $sales = $data->sales();
            $this->assertSame(300.0, $sales['total']);
            $this->assertSame('Atendido en consulta', $sales['byType']->first()['label']);
            $this->assertSame('Resina simple (1 cara)', $sales['procedures']->first()['label']);

            $clinic = $data->clinic();
            $this->assertSame(1, $clinic['count']);
            $this->assertSame(50, $clinic['appointments']['attendance']);

            $pro = $data->professionals()->first();
            $this->assertSame('Ana Ruiz', $pro['professional']->full_name);
            $this->assertSame(300.0, $pro['done']);
        });
    }

    public function test_pantallas_y_excel(): void
    {
        $this->as()->get(route('reports.index'))->assertRedirect(route('reports.index', ['section' => 'resumen']));
        foreach (['resumen', 'finanzas', 'ventas', 'pacientes', 'profesionales', 'inventario'] as $section) {
            $this->as()->get(route('reports.index', ['section' => $section]))->assertOk();
        }
        foreach (['this_year', 'last_month', 'all'] as $preset) {
            $this->as()->get(route('reports.index', ['section' => 'resumen', 'preset' => $preset]))->assertOk();
        }
        $this->as()->get(route('reports.index', ['section' => 'resumen']))->assertSee('Utilidad estimada')->assertSee('Cobraste');
        $this->as()->get(route('reports.index', ['section' => 'pacientes']))->assertSee('50 %');

        $response = $this->as()->get(route('reports.export', ['section' => 'resumen']));
        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));
    }

    public function test_permisos_y_plan(): void
    {
        // El odontólogo ve lo clínico, pero no el dinero.
        $user = User::create(['name' => 'od', 'email' => 'od@clinicademo.test', 'password' => 'Secreta123']);
        $user->companies()->attach($this->demo->id, ['role_id' => Role::where('slug', 'odontologo')->value('id'), 'active' => true]);
        $this->as($user)->get(route('reports.index'))->assertRedirect(route('reports.index', ['section' => 'pacientes']));
        $this->as($user)->get(route('reports.index', ['section' => 'finanzas']))->assertForbidden();
        $this->as($user)->get(route('reports.index', ['section' => 'profesionales']))->assertOk()->assertDontSee('Comisiones');
        $this->as($user)->get(route('reports.export', ['section' => 'pacientes']))->assertForbidden();

        $cajero = User::create(['name' => 'caj', 'email' => 'caj@clinicademo.test', 'password' => 'Secreta123']);
        $cajero->companies()->attach($this->demo->id, ['role_id' => Role::where('slug', 'cajero')->value('id'), 'active' => true]);
        $this->as($cajero)->get(route('reports.index'))->assertForbidden();

        $sonrisa = User::where('email', 'admin@sonrisa.test')->firstOrFail();
        $this->actingAs($sonrisa)->get(route('reports.index'))->assertForbidden();   // plan Consultorio
    }
}
