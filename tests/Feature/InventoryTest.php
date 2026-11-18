<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Caja;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\FinanceAccount;
use App\Models\Patient;
use App\Models\PaymentMethod;
use App\Models\Personal;
use App\Models\Procedure;
use App\Models\ProcedureSupply;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Cash;
use App\Support\Consultations;
use App\Support\Stock;
use App\Support\Tenancy;
use App\Support\Treasury;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inventario y compras: existencia por consultorio con kardex, lotes FEFO,
 * ajustes y traslados, insumos por procedimiento, venta de productos,
 * compras con recepción parcial, pagos desde cuenta o caja y su anulación,
 * permisos, plan y aislamiento.
 */
class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private Company $demo;

    private User $admin;

    private Branch $main;

    private Product $anestesia;

    private Product $cepillo;

    private Supplier $supplier;

    private CashSession $session;

    private PaymentMethod $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->demo = Company::where('ruc', '1000000001')->firstOrFail();
        $this->admin = User::where('email', 'admin@clinicademo.test')->firstOrFail();

        app(Tenancy::class)->runFor($this->demo->id, function () {
            $this->main = Stock::defaultBranch();
            $this->anestesia = Product::create(['name' => 'Lidocaína 2%', 'unit' => 'cartucho', 'min_stock' => 10, 'purchase_price' => 5, 'track_batches' => true, 'active' => true]);
            $this->cepillo = Product::create(['name' => 'Cepillo dental', 'unit' => 'unidad', 'sale_price' => 25, 'purchase_price' => 10, 'for_sale' => true, 'active' => true]);
            $this->supplier = Supplier::create(['name' => 'DENTAL SUR', 'active' => true]);
            PaymentMethod::ensureDefaults();
            $this->cash = PaymentMethod::where('type', 'cash')->firstOrFail();
            $this->session = Cash::open(Caja::create(['name' => 'Caja 1', 'active' => true]), 300);
        });
    }

    private function as(?User $user = null)
    {
        $this->flushHeaders();

        return $this->actingAs($user ?? $this->admin)->withSession(['current_company_id' => $this->demo->id]);
    }

    private function stock(Product $p): float
    {
        return app(Tenancy::class)->runFor($this->demo->id, fn () => $p->fresh()->stockIn());
    }

    public function test_producto_con_stock_inicial_lotes_fefo_y_alertas(): void
    {
        $this->as()->post(route('products.store'), ['name' => 'Resina A2', 'unit' => 'jeringa', 'min_stock' => 2, 'initial_quantity' => 5])
            ->assertSessionHasNoErrors();
        $this->assertSame(5.0, $this->stock(Product::allCompanies()->where('name', 'Resina A2')->firstOrFail()));

        // Con lote: sin lote no entra.
        $this->as()->post(route('stock-movements.store'), ['kind' => 'entrada', 'product_id' => $this->anestesia->id, 'quantity' => 10, 'reason' => 'initial'])
            ->assertSessionHasErrors('lot');
        $this->as()->post(route('stock-movements.store'), ['kind' => 'entrada', 'product_id' => $this->anestesia->id, 'quantity' => 6, 'reason' => 'initial',
            'lot' => 'B', 'expires_at' => today()->addYear()->toDateString()])->assertSessionHasNoErrors();
        $this->as()->post(route('stock-movements.store'), ['kind' => 'entrada', 'product_id' => $this->anestesia->id, 'quantity' => 4, 'reason' => 'initial',
            'lot' => 'A', 'expires_at' => today()->addDays(20)->toDateString()])->assertSessionHasNoErrors();

        // La salida consume primero el lote que vence antes (A).
        $this->as()->post(route('stock-movements.store'), ['kind' => 'salida', 'product_id' => $this->anestesia->id, 'quantity' => 5, 'reason' => 'internal_use'])
            ->assertSessionHasNoErrors();
        app(Tenancy::class)->runFor($this->demo->id, function () {
            $this->assertSame(0.0, (float) $this->anestesia->batches()->where('lot', 'A')->value('quantity'));
            $this->assertSame(5.0, (float) $this->anestesia->batches()->where('lot', 'B')->value('quantity'));
        });
        // No alcanza: no sale.
        $this->as()->post(route('stock-movements.store'), ['kind' => 'salida', 'product_id' => $this->anestesia->id, 'quantity' => 50, 'reason' => 'loss'])
            ->assertSessionHasErrors('quantity');

        // 5 ≤ mínimo 10 → bajo el mínimo; y el lote A (20 días) está por vencer… pero ya sin existencia.
        $this->as()->get(route('products.index', ['filter' => 'bajo']))->assertOk()->assertSee('Lidocaína 2%')->assertDontSee('Cepillo dental');
        $this->as()->get(route('products.show', $this->anestesia))->assertOk()->assertSee('Uso interno');
        $this->as()->get(route('dashboard'))->assertOk()->assertSee('en o bajo el mínimo');
    }

    public function test_ajuste_por_conteo_y_traslado_entre_consultorios(): void
    {
        $second = app(Tenancy::class)->runFor($this->demo->id, fn () => Branch::create(['company_id' => $this->demo->id, 'name' => 'Sucursal Norte', 'active' => true]));
        $this->as()->post(route('stock-movements.store'), ['kind' => 'entrada', 'product_id' => $this->cepillo->id, 'branch_id' => $this->main->id, 'quantity' => 20, 'reason' => 'purchase'])
            ->assertSessionHasNoErrors();
        $this->as()->post(route('stock-movements.store'), ['kind' => 'ajuste', 'product_id' => $this->cepillo->id, 'branch_id' => $this->main->id, 'quantity' => 18])
            ->assertSessionHasNoErrors();
        $this->as()->post(route('stock-movements.store'), ['kind' => 'traslado', 'product_id' => $this->cepillo->id, 'branch_id' => $this->main->id, 'to_branch_id' => $second->id, 'quantity' => 8])
            ->assertSessionHasNoErrors();

        app(Tenancy::class)->runFor($this->demo->id, function () use ($second) {
            $p = $this->cepillo->fresh();
            $this->assertSame(10.0, $p->stockIn($this->main->id));
            $this->assertSame(8.0, $p->stockIn($second->id));
            $this->assertSame(-2.0, (float) StockMovement::where('reason', 'count')->value('quantity'));
        });
        $this->as()->get(route('stock-movements.index'))->assertOk()->assertSee('Traslado');
    }

    public function test_insumos_del_procedimiento_se_descuentan_y_vuelven(): void
    {
        $procedure = app(Tenancy::class)->runFor($this->demo->id, function () {
            Procedure::loadSuggested();

            return Procedure::where('code', 'OPE-01')->firstOrFail();
        });
        $glove = app(Tenancy::class)->runFor($this->demo->id, fn () => Product::create(['name' => 'Guantes', 'unit' => 'par', 'active' => true]));
        $this->as()->post(route('procedure-supplies.store', $procedure), ['product_id' => $glove->id, 'quantity' => 2])->assertSessionHasNoErrors();
        $this->as()->get(route('procedure-supplies.edit', $procedure))->assertOk()->assertSee('Guantes');

        [$consultation, $item] = app(Tenancy::class)->runFor($this->demo->id, function () use ($procedure) {
            $ana = Personal::create(['full_name' => 'Ana', 'active' => true, 'attends_patients' => true]);
            $patient = Patient::create(['number' => 1, 'first_name' => 'Rosa', 'last_name' => 'Paz', 'active' => true]);
            $consultation = Consultations::start($patient, null, $ana->id);

            return [$consultation, Consultations::addProcedure($consultation, ['procedure_id' => $procedure->id, 'tooth' => 16, 'surfaces' => ['O']])];
        });
        // Ya se hizo: se descuenta aunque no haya (queda negativo, a la vista).
        $this->assertSame(-2.0, $this->stock($glove));

        $this->as()->delete(route('consultations.procedures.destroy', [$consultation, $item]))->assertSessionHasNoErrors();
        $this->assertSame(0.0, $this->stock($glove));
    }

    public function test_venta_de_productos_sale_del_stock_y_vuelve_al_anular(): void
    {
        app(Tenancy::class)->runFor($this->demo->id, fn () => Stock::receive($this->cepillo, $this->main, 3, 'initial'));
        $this->as()->get(route('sales.create'))->assertOk()->assertSee('Cepillo dental')->assertSee('quedan 3');

        $this->as()->post(route('sales.store'), ['cash_session_id' => $this->session->id,
            'items' => [['source' => 'product', 'product_id' => $this->cepillo->id, 'quantity' => 5]],
            'payments' => [['payment_method_id' => $this->cash->id, 'amount' => 125]]])->assertSessionHasErrors('quantity');
        $this->assertSame(0, Sale::allCompanies()->count());

        $this->as()->post(route('sales.store'), ['cash_session_id' => $this->session->id,
            'items' => [['source' => 'product', 'product_id' => $this->cepillo->id, 'quantity' => 2]],
            'payments' => [['payment_method_id' => $this->cash->id, 'amount' => 50]]])->assertSessionHasNoErrors();
        $sale = Sale::allCompanies()->firstOrFail();
        $this->assertSame(50.0, (float) $sale->total);
        $this->assertSame(1.0, $this->stock($this->cepillo));

        $this->as()->post(route('sales.cancel', $sale), ['cancel_reason' => 'Devolvió', 'payment_method_id' => $this->cash->id, 'cash_session_id' => $this->session->id])
            ->assertSessionHasNoErrors();
        $this->assertSame(3.0, $this->stock($this->cepillo));
    }

    public function test_compra_recepcion_parcial_pagos_y_cuentas_por_pagar(): void
    {
        $this->as()->post(route('purchases.store'), [
            'supplier_id' => $this->supplier->id, 'purchased_at' => today()->toDateString(), 'discount' => 20, 'due_date' => today()->addDays(30)->toDateString(),
            'items' => [
                ['product_id' => $this->cepillo->id, 'quantity' => 20, 'unit_cost' => 8],
                ['product_id' => $this->anestesia->id, 'quantity' => 10, 'unit_cost' => 6],
            ],
        ])->assertSessionHasNoErrors();
        $purchase = Purchase::allCompanies()->firstOrFail();
        $this->assertSame('pedida', $purchase->status);
        $this->assertSame(200.0, (float) $purchase->total);   // 160 + 60 − 20
        $this->assertSame(0.0, $this->stock($this->cepillo));

        // Llegan los cepillos; la anestesia sin lote no entra.
        $items = $purchase->items()->get()->keyBy('product_id');
        $this->as()->post(route('purchases.receive', $purchase), ['lines' => [
            $items[$this->anestesia->id]->id => ['quantity' => 10],
        ]])->assertSessionHasErrors('lot');
        $this->as()->post(route('purchases.receive', $purchase), ['lines' => [
            $items[$this->cepillo->id]->id => ['quantity' => 20],
            $items[$this->anestesia->id]->id => ['quantity' => 0],
        ]])->assertSessionHasNoErrors();
        $this->assertSame('parcial', $purchase->fresh()->status);
        $this->assertSame(20.0, $this->stock($this->cepillo));
        // Costo real del cepillo: 8 con el descuento global repartido (200/220).
        $this->assertSame(7.27, (float) $this->cepillo->fresh()->purchase_price);

        // Pago desde la caja (cuota) y anulación del pago.
        $this->as()->post(route('purchases.pay', $purchase), ['amount' => 100, 'source' => 'cash', 'cash_session_id' => $this->session->id])->assertSessionHasNoErrors();
        $this->assertSame(200.0, $this->session->fresh()->expectedCash());
        $this->as()->get(route('purchases.payables'))->assertOk()->assertSee('DENTAL SUR')->assertSee('100.00');
        $payment = $purchase->payments()->firstOrFail();
        $this->as()->post(route('purchases.payment.cancel', [$purchase, $payment]), ['cancel_reason' => 'Error'])->assertSessionHasNoErrors();
        $this->assertSame(300.0, $this->session->fresh()->expectedCash());
        $this->assertSame(200.0, (float) $purchase->fresh()->balance);

        // No llegará la anestesia: se cierra con lo recibido y la deuda baja (160 − 20 × 160/220).
        $this->as()->post(route('purchases.close', $purchase), ['closing_notes' => 'Sin stock'])->assertSessionHasNoErrors();
        $purchase->refresh();
        $this->assertSame('recibida', $purchase->status);
        $this->assertSame(145.45, (float) $purchase->balance);

        // Pagar desde una cuenta.
        $this->as()->post(route('finance-accounts.store'), ['name' => 'Banco', 'type' => 'banco', 'opening_balance' => 1000])->assertSessionHasNoErrors();
        $account = FinanceAccount::allCompanies()->where('name', 'BANCO')->firstOrFail();
        $this->as()->post(route('purchases.pay', $purchase), ['amount' => 145.45, 'source' => 'account', 'finance_account_id' => $account->id])->assertSessionHasNoErrors();
        $this->assertSame(0.0, (float) $purchase->fresh()->balance);
        $this->assertSame(854.55, (float) FinanceAccount::allCompanies()->find($account->id)->balance);

        // Con recepciones o pagos ya no se anula.
        $this->as()->post(route('purchases.cancel', $purchase), ['cancel_reason' => 'x'])->assertSessionHasErrors('cancel_reason');
    }

    public function test_permisos_plan_y_aislamiento(): void
    {
        $asistente = User::create(['name' => 'asi', 'email' => 'asi@clinicademo.test', 'password' => 'Secreta123']);
        $asistente->companies()->attach($this->demo->id, ['role_id' => Role::where('slug', 'asistente')->value('id'), 'active' => true]);
        $this->as($asistente)->get(route('products.index'))->assertOk();
        $this->as($asistente)->get(route('stock-movements.create'))->assertOk();
        $this->as($asistente)->get(route('purchases.index'))->assertForbidden();
        $this->as($asistente)->get(route('products.create'))->assertForbidden();

        $sonrisa = User::where('email', 'admin@sonrisa.test')->firstOrFail();
        $this->actingAs($sonrisa)->get(route('products.index'))->assertForbidden();   // plan Consultorio
        $sonrisa->companies()->first()->subscription->update(['features_override' => ['inventario', 'compras']]);
        $this->actingAs($sonrisa)->get(route('products.index'))->assertOk()->assertDontSee('Cepillo dental');
        $this->actingAs($sonrisa)->get(route('products.show', $this->cepillo))->assertNotFound();
        $this->actingAs($sonrisa)->post(route('purchases.store'), ['supplier_id' => $this->supplier->id, 'purchased_at' => today()->toDateString(),
            'items' => [['product_id' => $this->cepillo->id, 'quantity' => 1, 'unit_cost' => 1]]])->assertSessionHasErrors(['supplier_id', 'items.0.product_id']);
    }
}
