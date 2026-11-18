<?php

namespace Tests\Feature;

use App\Models\Caja;
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
use App\Models\User;
use App\Support\Cash;
use App\Support\Consultations;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API de cobros para la app: lo pendiente del paciente, cobrar con saldo,
 * cuentas por cobrar con recordatorio, abono FIFO con recibo por WhatsApp,
 * abono a una venta, anulación, permisos y aislamiento.
 */
class SaleApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $demo;

    private User $admin;

    private Patient $maria;

    private Consultation $consultation;

    private PaymentMethod $cash;

    private PaymentMethod $qr;

    private CashSession $session;

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
            $ana = Personal::create(['full_name' => 'Ana Ruiz', 'active' => true, 'attends_patients' => true]);
            $this->maria = Patient::create(['number' => 1, 'first_name' => 'María', 'last_name' => 'Rojas', 'phone' => '70011223', 'active' => true]);
            $this->consultation = Consultations::start($this->maria, null, $ana->id);
            Consultations::addProcedure($this->consultation, ['procedure_id' => Procedure::where('code', 'GEN-01')->value('id')]);
            Consultations::addProcedure($this->consultation, ['procedure_id' => Procedure::where('code', 'OPE-01')->value('id'), 'tooth' => 16, 'surfaces' => ['O']]);
            $this->session = Cash::open(Caja::create(['name' => 'Caja 1', 'active' => true]), 50);
        });
    }

    private function api(?User $user = null)
    {
        $this->app['auth']->forgetGuards();
        $token = ($user ?? $this->admin)->createToken('test')->plainTextToken;

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Company-Id' => $this->demo->id, 'Accept' => 'application/json']);
    }

    public function test_cobrar_lo_pendiente_con_saldo_y_abonar_desde_cuentas_por_cobrar(): void
    {
        $this->api()->getJson('/api/v1/sales/options')->assertOk()
            ->assertJsonPath('cash.enabled', true)->assertJsonPath('cash.sessions.0.name', 'Caja 1')
            ->assertJsonStructure(['payment_methods' => [['id', 'name', 'is_cash', 'requires_reference']], 'professionals']);

        $pending = $this->api()->getJson("/api/v1/patients/{$this->maria->id}/pending")->assertOk()
            ->assertJsonPath('pending_total', 280)->assertJsonCount(2, 'data')->assertJsonPath('data.1.tooth', 16);

        // Cobra 100 de 280: queda saldo de 180 con fecha de pago.
        $items = collect($pending->json('data'))->map(fn ($i) => ['source' => $i['source'], 'id' => $i['id']])->all();
        $sale = $this->api()->postJson('/api/v1/sales', [
            'patient_id' => $this->maria->id, 'items' => $items, 'due_date' => today()->addWeek()->toDateString(),
            'payments' => [['payment_method_id' => $this->cash->id, 'amount' => 100]],
        ])->assertCreated()->assertJsonPath('total', 280)->assertJsonPath('balance', 180)->assertJsonCount(2, 'items');
        $this->assertStringContainsString('wa.me', $sale->json('receipt.whatsapp_url'));
        $this->assertStringContainsString('Saldo: ', $sale->json('receipt.message'));

        // Ya no queda nada por cobrar y no se puede cobrar dos veces.
        $this->api()->getJson("/api/v1/patients/{$this->maria->id}/pending")->assertJsonCount(0, 'data')->assertJsonPath('debt', 180);
        $this->api()->postJson('/api/v1/sales', ['patient_id' => $this->maria->id, 'items' => $items])->assertStatus(422);

        $this->api()->getJson('/api/v1/receivables')->assertOk()->assertJsonPath('total', 180)
            ->assertJsonPath('data.0.patient.full_name', 'María Rojas')
            ->assertJsonPath('data.0.reminder.message', fn ($m) => str_contains($m, 'saldo pendiente'));

        // Abono con QR sin referencia: rechazado. Con referencia: se aplica y devuelve el recibo.
        $this->api()->postJson("/api/v1/receivables/{$this->maria->id}/pay", ['payment_method_id' => $this->qr->id, 'amount' => 80])
            ->assertStatus(422);
        $this->api()->postJson("/api/v1/receivables/{$this->maria->id}/pay", ['payment_method_id' => $this->qr->id, 'amount' => 80, 'reference' => 'QR-123'])
            ->assertCreated()->assertJsonPath('debt', 100)->assertJsonPath('applied.0.amount', 80)
            ->assertJsonPath('receipt.message', fn ($m) => str_contains($m, 'Saldo pendiente'));
        // No se abona más de lo que se debe.
        $this->api()->postJson("/api/v1/receivables/{$this->maria->id}/pay", ['payment_method_id' => $this->cash->id, 'amount' => 500])->assertStatus(422);

        // Abono directo a la venta: queda pagada.
        $this->api()->postJson("/api/v1/sales/{$sale->json('id')}/payments", ['payment_method_id' => $this->cash->id, 'amount' => 100])
            ->assertCreated()->assertJsonPath('balance', 0)->assertJsonCount(3, 'payments');

        $this->api()->getJson('/api/v1/sales?filter=hoy')->assertOk()->assertJsonPath('collected_today', 280)->assertJsonPath('data.0.code', 'V-000001');
        $this->api()->getJson("/api/v1/sales?patient_id={$this->maria->id}")->assertOk()->assertJsonPath('filter', 'todas')->assertJsonPath('debt', 0);
        $this->assertEqualsWithDelta(50 + 100 + 100, $this->session->fresh()->expectedCash(), 0.01);
    }

    public function test_anular_devuelve_y_lo_atendido_vuelve_a_pendiente(): void
    {
        $items = app(Tenancy::class)->runFor($this->demo->id, fn () => ConsultationProcedure::pluck('id')->map(fn ($id) => ['source' => 'consultation', 'id' => $id])->all());
        $id = $this->api()->postJson('/api/v1/sales', [
            'patient_id' => $this->maria->id, 'items' => $items, 'payments' => [['payment_method_id' => $this->cash->id, 'amount' => 300]],
        ])->assertCreated()->assertJsonPath('paid', 280)->json('id');

        $this->api()->postJson("/api/v1/sales/{$id}/cancel", ['cancel_reason' => 'Error'])->assertStatus(422);
        $this->api()->postJson("/api/v1/sales/{$id}/cancel", ['cancel_reason' => 'Error de cobro', 'payment_method_id' => $this->cash->id])
            ->assertOk()->assertJsonPath('cancelled', true)->assertJsonPath('receipt', null);
        $this->api()->getJson("/api/v1/patients/{$this->maria->id}/pending")->assertJsonCount(2, 'data');
    }

    public function test_permisos_y_aislamiento(): void
    {
        // El odontólogo ve las ventas pero no cobra ni ve cuentas por cobrar.
        $user = User::create(['name' => 'odo', 'email' => 'odo@clinicademo.test', 'password' => 'Secreta123']);
        $user->companies()->attach($this->demo->id, ['role_id' => Role::where('slug', 'odontologo')->value('id'), 'active' => true]);
        $this->api($user)->getJson('/api/v1/sales')->assertOk();
        $this->api($user)->getJson('/api/v1/receivables')->assertForbidden();
        $this->api($user)->getJson("/api/v1/patients/{$this->maria->id}/pending")->assertForbidden();

        $id = $this->api()->postJson('/api/v1/sales', [
            'patient_id' => $this->maria->id, 'items' => [['source' => 'free', 'description' => 'Control', 'unit_price' => 50]],
            'payments' => [['payment_method_id' => $this->cash->id, 'amount' => 50]],
        ])->assertCreated()->json('id');

        $sonrisa = User::where('email', 'admin@sonrisa.test')->firstOrFail();
        $this->app['auth']->forgetGuards();
        $token = $sonrisa->createToken('t')->plainTextToken;
        $other = $sonrisa->companies()->first()->id;
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Company-Id' => $other, 'Accept' => 'application/json'])
            ->getJson("/api/v1/sales/{$id}")->assertNotFound();
        $this->assertSame(1, Sale::allCompanies()->count());
    }
}
