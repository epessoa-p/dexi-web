<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Caja;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinanceAccount;
use App\Models\FinanceMovement;
use App\Models\Personal;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finanzas (ESTRUCTURA_CUENTAS_Y_GASTOS.md §8): el saldo nunca descuadra,
 * gastos por tipo con origen del dinero (cuenta o caja), la anulación devuelve
 * el dinero, control del mes de los servicios básicos y la API para la app.
 *
 * Datos demo: Clínica Dental Demo (plan Clínica: caja + finanzas) y Clínica
 * Sonrisa (plan Consultorio: caja sin finanzas).
 */
class FinanceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Caja $caja;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->company = Company::where('ruc', '1000000001')->firstOrFail();
        $this->user = User::where('email', 'admin@clinicademo.test')->firstOrFail();
        $branch = Branch::forCompany($this->company)->firstOrFail();
        $this->caja = $this->inTenant(fn () => Caja::create(['branch_id' => $branch->id, 'name' => 'Caja 1', 'active' => true]));
        $this->inTenant(fn () => ExpenseCategory::ensureDefaults());
    }

    private function as()
    {
        // Las cabeceras de la API (Accept: json) no deben seguir en las llamadas web.
        $this->flushHeaders();

        return $this->actingAs($this->user)->withSession(['current_company_id' => $this->company->id]);
    }

    private function inTenant(callable $callback): mixed
    {
        return app(Tenancy::class)->runFor($this->company->id, $callback);
    }

    private function api()
    {
        $this->app['auth']->forgetGuards();
        $token = $this->user->createToken('test')->plainTextToken;

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json', 'X-Company-Id' => (string) $this->company->id]);
    }

    private function account(string $name, float $opening): FinanceAccount
    {
        $this->as()->post(route('finance-accounts.store'), ['name' => $name, 'type' => 'banco', 'opening_balance' => $opening])->assertSessionHasNoErrors();

        return FinanceAccount::allCompanies()->where('name', mb_strtoupper($name))->firstOrFail();
    }

    private function category(string $name): ExpenseCategory
    {
        return ExpenseCategory::allCompanies()->where('company_id', $this->company->id)->where('name', $name)->firstOrFail();
    }

    private function openCash(float $amount): CashSession
    {
        $this->as()->post(route('cash.open', $this->caja), ['opening_amount' => $amount])->assertSessionHasNoErrors();

        return CashSession::allCompanies()->where('status', 'open')->latest('id')->firstOrFail();
    }

    /** El saldo siempre es la suma de su extracto. */
    private function assertBalanced(FinanceAccount $account, float $expected): void
    {
        $account = $account->fresh();
        $sum = FinanceMovement::allCompanies()->where('finance_account_id', $account->id)->get()->sum(fn ($m) => $m->signed_amount);
        $this->assertSame($expected, (float) $account->balance);
        $this->assertSame($expected, round($sum, 2));
    }

    public function test_cuentas_alta_movimientos_transferencia_y_anulacion_sin_descuadrar(): void
    {
        $bank = $this->account('Banco Unión', 1000);
        $petty = $this->account('Caja chica', 0);
        $this->assertSame('BANCO UNIÓN', $bank->name);
        $this->assertBalanced($bank, 1000.0);
        $this->assertSame('apertura', FinanceMovement::allCompanies()->where('finance_account_id', $bank->id)->value('origin'));

        $this->as()->post(route('finance-accounts.move', $bank), ['type' => 'ingreso', 'amount' => 250, 'concept' => 'Aporte'])->assertSessionHasNoErrors();
        $this->as()->post(route('finance-accounts.move', $bank), ['type' => 'egreso', 'amount' => 50, 'concept' => 'Comisión bancaria'])->assertSessionHasNoErrors();
        // No sale más de lo que hay.
        $this->as()->post(route('finance-accounts.move', $bank), ['type' => 'egreso', 'amount' => 5000, 'concept' => 'X'])->assertSessionHasErrors('amount');
        $this->assertBalanced($bank, 1200.0);

        $this->as()->post(route('finance-accounts.transfer', $bank), ['to_account_id' => $petty->id, 'amount' => 300])->assertSessionHasNoErrors();
        $this->assertBalanced($bank, 900.0);
        $this->assertBalanced($petty, 300.0);
        $out = FinanceMovement::allCompanies()->where('finance_account_id', $bank->id)->where('origin', 'transferencia')->firstOrFail();
        $this->assertNotNull($out->transfer_movement_id);

        // Anular la transferencia anula sus dos patas.
        $this->as()->post(route('finance-accounts.movements.cancel', $out))->assertSessionHasNoErrors();
        $this->assertBalanced($bank, 1200.0);
        $this->assertBalanced($petty, 0.0);
        $this->assertNotNull($out->fresh()->cancelled_at);
        $this->assertNotNull($out->transferPair->fresh()->cancelled_at);
        $this->as()->post(route('finance-accounts.movements.cancel', $out))->assertSessionHasErrors('movement');

        // Con saldo no se elimina; en cero, sí (el historial queda).
        $this->as()->delete(route('finance-accounts.destroy', $bank))->assertSessionHasErrors('error');
        $this->as()->delete(route('finance-accounts.destroy', $petty))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($petty);

        $this->as()->get(route('finance-accounts.index'))->assertOk()->assertSee('BANCO UNIÓN');
        $this->as()->get(route('finance-accounts.show', $bank))->assertOk()->assertSee('Comisión bancaria')->assertSee('Saldo inicial');
    }

    public function test_gastos_desde_una_cuenta_con_descripcion_automatica_y_anulacion(): void
    {
        $bank = $this->account('Banco', 1000);
        $supplier = $this->inTenant(fn () => Supplier::create(['name' => 'Distribuidora Sur', 'active' => true]));
        $person = $this->inTenant(fn () => Personal::create(['full_name' => 'Tania Molina', 'active' => true]));
        $base = ['source' => 'account', 'finance_account_id' => $bank->id, 'spent_at' => now()->toDateString()];

        // General: la descripción es obligatoria.
        $this->as()->post(route('expenses.store'), $base + ['group' => 'general', 'expense_category_id' => $this->category('OTROS')->id, 'amount' => 100])
            ->assertSessionHasErrors('concept');
        // Proveedor: sin categoría (se elige sola) y la descripción se completa.
        $this->as()->post(route('expenses.store'), $base + ['group' => 'proveedor', 'amount' => 200])->assertSessionHasErrors('supplier_id');
        $this->as()->post(route('expenses.store'), $base + ['group' => 'proveedor', 'supplier_id' => $supplier->id, 'amount' => 200])->assertSessionHasNoErrors();
        $this->as()->post(route('expenses.store'), $base + ['group' => 'personal', 'personal_id' => $person->id, 'amount' => 300])->assertSessionHasNoErrors();
        // No se registra con fecha futura.
        $this->as()->post(route('expenses.store'), ['spent_at' => now()->addDay()->toDateString()] + $base + ['group' => 'personal', 'personal_id' => $person->id, 'amount' => 1])
            ->assertSessionHasErrors('spent_at');

        $toSupplier = Expense::allCompanies()->where('supplier_id', $supplier->id)->firstOrFail();
        $this->assertSame('Pago a DISTRIBUIDORA SUR', $toSupplier->concept);
        $this->assertSame('PAGO A PROVEEDORES', $toSupplier->category->name);
        $this->assertSame('G-000001', $toSupplier->code);
        $this->assertSame('Pago a Tania Molina', Expense::allCompanies()->where('personal_id', $person->id)->value('concept'));
        $this->assertBalanced($bank, 500.0);

        $this->as()->get(route('expenses.index', ['tab' => 'proveedor']))->assertOk()->assertSee('Pago a DISTRIBUIDORA SUR')->assertDontSee('Pago a Tania Molina');
        $this->as()->get(route('expenses.show', $toSupplier))->assertOk()->assertSee('DISTRIBUIDORA SUR');
        $this->as()->get(route('suppliers.show', $supplier))->assertOk()->assertSee($toSupplier->code);

        // El movimiento de un gasto no se anula desde la cuenta, sino desde el gasto.
        $movement = FinanceMovement::allCompanies()->findOrFail($toSupplier->finance_movement_id);
        $this->as()->post(route('finance-accounts.movements.cancel', $movement))->assertSessionHasErrors('movement');

        $this->as()->post(route('expenses.cancel', $toSupplier), ['cancel_reason' => 'Duplicado'])->assertSessionHasNoErrors();
        $this->assertSame('anulado', $toSupplier->fresh()->status);
        $this->assertBalanced($bank, 700.0);
        $this->as()->post(route('expenses.cancel', $toSupplier), ['cancel_reason' => 'Otra vez'])->assertSessionHasErrors('cancel_reason');
    }

    public function test_gastos_desde_la_caja_salen_en_efectivo_y_vuelven_al_anular(): void
    {
        $session = $this->openCash(100);
        $general = ['group' => 'general', 'expense_category_id' => $this->category('LIMPIEZA Y MANTENIMIENTO')->id, 'spent_at' => now()->toDateString(), 'source' => 'cash'];

        // No sale más efectivo del que hay en el cajón.
        $this->as()->post(route('expenses.store'), $general + ['concept' => 'Desinfectante', 'amount' => 150, 'cash_session_id' => $session->id])->assertSessionHasErrors('amount');
        $this->as()->post(route('expenses.store'), $general + ['concept' => 'Desinfectante', 'amount' => 40, 'cash_session_id' => $session->id])->assertSessionHasNoErrors();
        $this->assertSame(60.0, $session->fresh()->expectedCash());
        $expense = Expense::allCompanies()->latest('id')->firstOrFail();
        $this->assertSame('cash', $expense->source);
        $this->assertSame('cash', $expense->paymentMethod->type);

        // La caja se cierra; al anular, el dinero vuelve a otro turno abierto.
        $this->as()->post(route('cash.close', $session), ['counted_cash' => 60])->assertSessionHasNoErrors();
        $this->assertSame('0.00', $session->fresh()->difference);
        $this->as()->post(route('expenses.cancel', $expense), ['cancel_reason' => 'Error'])->assertSessionHasErrors('cancel_reason');
        $other = $this->openCash(0);
        $this->as()->post(route('expenses.cancel', $expense), ['cancel_reason' => 'Error'])->assertSessionHasNoErrors();
        $this->assertSame(40.0, $other->fresh()->expectedCash());
    }

    public function test_servicios_basicos_control_del_mes_mensual_y_anual(): void
    {
        $bank = $this->account('Banco', 5000);
        $light = $this->category('LUZ');
        $this->as()->post(route('expense-categories.store'), ['name' => 'Hosting', 'type' => 'basico', 'frequency' => 'anual'])->assertSessionHasNoErrors();
        $hosting = $this->category('HOSTING');
        $this->assertTrue($hosting->recurring);
        $this->assertSame('anual', $hosting->frequency);

        $pay = fn (ExpenseCategory $c, float $amount, string $date) => $this->as()->post(route('expenses.store'), [
            'group' => 'basico', 'expense_category_id' => $c->id, 'amount' => $amount, 'spent_at' => $date,
            'source' => 'account', 'finance_account_id' => $bank->id,
        ])->assertSessionHasNoErrors();

        $pay($light, 180, now()->subMonthNoOverflow()->startOfMonth()->addDays(4)->toDateString());
        $pay($hosting, 600, now()->subMonthsNoOverflow(3)->toDateString());

        $basics = $this->api()->getJson('/api/v1/finance/basics')->assertOk()->json();
        $items = collect($basics['items'])->keyBy('category.name');
        $this->assertSame('pendiente', $items['LUZ']['status']);
        $this->assertSame(180, $items['LUZ']['last_amount']);
        $this->assertSame('pagado', $items['HOSTING']['status']);
        $this->assertSame(now()->subMonthsNoOverflow(3)->addYear()->toDateString(), $items['HOSTING']['next_due']);

        // El mes pasado la luz sí estaba pagada; la descripción dice el mes.
        $last = $this->api()->getJson('/api/v1/finance/basics?month=' . now()->subMonthNoOverflow()->format('Y-m'))->json();
        $this->assertSame('pagado', collect($last['items'])->firstWhere('category.name', 'LUZ')['status']);
        $this->assertStringStartsWith('LUZ · ', Expense::allCompanies()->where('expense_category_id', $light->id)->value('concept'));

        $this->as()->get(route('expenses.index', ['tab' => 'basico']))->assertOk()->assertSee('HOSTING')->assertSee('Registrar');
    }

    public function test_cada_grupo_trae_solo_sus_categorias_y_la_categoria_con_gastos_solo_se_desactiva(): void
    {
        $options = fn (string $group) => collect($this->api()->getJson("/api/v1/finance/options?group={$group}")->assertOk()->json('categories'))->pluck('name');

        $this->assertTrue($options('basico')->contains('LUZ'));
        $this->assertFalse($options('basico')->contains('COMIDA'));
        $this->assertTrue($options('general')->contains('COMIDA'));
        $this->assertFalse($options('general')->contains('LUZ'));
        $this->assertSame(['PAGO A PROVEEDORES'], $options('proveedor')->all());
        $this->assertSame(['account', 'cash'], collect($this->api()->getJson('/api/v1/finance/options')->json('sources'))->pluck('key')->all());

        $bank = $this->account('Banco', 100);
        $food = $this->category('COMIDA');
        $this->as()->post(route('expenses.store'), ['group' => 'general', 'expense_category_id' => $food->id, 'concept' => 'Almuerzo', 'amount' => 10,
            'spent_at' => now()->toDateString(), 'source' => 'account', 'finance_account_id' => $bank->id])->assertSessionHasNoErrors();
        // Una categoría de otro grupo no entra por esta pestaña.
        $this->as()->post(route('expenses.store'), ['group' => 'basico', 'expense_category_id' => $food->id, 'amount' => 10,
            'spent_at' => now()->toDateString(), 'source' => 'account', 'finance_account_id' => $bank->id])->assertSessionHasErrors('expense_category_id');

        $this->as()->delete(route('expense-categories.destroy', $food))->assertSessionHasNoErrors();
        $this->assertFalse($food->fresh()->active);
        $this->as()->delete(route('expense-categories.destroy', $this->category('PEDIDOS Y ENVÍOS')))->assertSessionHasNoErrors();
        $this->assertNull(ExpenseCategory::allCompanies()->where('company_id', $this->company->id)->where('name', 'PEDIDOS Y ENVÍOS')->first());

        $this->as()->get(route('expense-categories.index'))->assertOk()->assertSee('Servicios básicos')->assertSee('Pagos a personal');
    }

    public function test_api_de_cuentas_y_gastos_con_totales_sin_anulados(): void
    {
        $account = $this->api()->postJson('/api/v1/finance/accounts', ['name' => 'Banco BNB', 'type' => 'banco', 'opening_balance' => 800])
            ->assertCreated()->assertJsonPath('balance', 800)->json();
        $this->api()->postJson("/api/v1/finance/accounts/{$account['id']}/movements", ['type' => 'egreso', 'amount' => 0, 'concept' => 'X'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');

        $register = fn (float $amount, string $concept) => $this->api()->postJson('/api/v1/finance/expenses', [
            'group' => 'general', 'expense_category_id' => $this->category('OTROS')->id, 'concept' => $concept, 'amount' => $amount,
            'spent_at' => now()->toDateString(), 'source' => 'account', 'finance_account_id' => $account['id'],
        ])->assertCreated();
        $kept = $register(100, 'Bolsas')->json();
        $gone = $register(50, 'Error')->json();
        $this->assertSame('BANCO BNB', $kept['source_label']);

        $this->api()->patchJson("/api/v1/finance/expenses/{$gone['id']}/cancel", ['cancel_reason' => 'Mal cargado'])
            ->assertOk()->assertJsonPath('cancelled', true);

        $this->api()->getJson('/api/v1/finance/expenses?group=general')->assertOk()
            ->assertJsonPath('total', 100)->assertJsonPath('count', 1)->assertJsonCount(2, 'data');
        $this->api()->getJson("/api/v1/finance/accounts/{$account['id']}/movements")->assertOk()
            ->assertJsonPath('account.balance', 700)
            ->assertJsonPath('data.0.origin', 'anulacion')
            ->assertJsonPath('data.1.cancelled', true);
        $this->api()->getJson('/api/v1/finance/accounts')->assertOk()->assertJsonPath('total', 700);
    }

    public function test_sin_finanzas_no_hay_cuentas_y_se_puede_anotar_pagado_por_fuera(): void
    {
        // Clínica Sonrisa: plan Consultorio (caja, sin finanzas).
        $basic = Company::where('ruc', '1000000002')->firstOrFail();
        $user = User::where('email', 'admin@sonrisa.test')->firstOrFail();
        app(Tenancy::class)->runFor($basic->id, fn () => ExpenseCategory::ensureDefaults());
        $other = ExpenseCategory::allCompanies()->where('company_id', $basic->id)->where('name', 'OTROS')->firstOrFail();
        $as = fn () => $this->actingAs($user)->withSession(['current_company_id' => $basic->id]);

        $as()->get(route('finance-accounts.index'))->assertForbidden();
        $as()->post(route('expenses.store'), ['group' => 'general', 'expense_category_id' => $other->id,
            'concept' => 'Pago por banco', 'amount' => 20, 'spent_at' => now()->toDateString(), 'source' => 'account'])->assertSessionHasErrors('source');
        $as()->post(route('expenses.store'), ['group' => 'general', 'expense_category_id' => $other->id,
            'concept' => 'Pago por banco', 'amount' => 20, 'spent_at' => now()->toDateString(), 'source' => 'external'])->assertSessionHasNoErrors();
        $this->assertSame('external', Expense::allCompanies()->where('company_id', $basic->id)->value('source'));
        $as()->get(route('expenses.create', ['group' => 'general']))->assertOk()->assertSee('Pagado por fuera');

        // La API también corta las cuentas.
        $token = $user->createToken('t')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json', 'X-Company-Id' => (string) $basic->id])
            ->getJson('/api/v1/finance/accounts')->assertForbidden()->assertJsonPath('code', 'plan_module_missing');
    }

    public function test_aislamiento_entre_clinicas(): void
    {
        $bank = $this->account('Banco', 100);
        $sonrisa = User::where('email', 'admin@sonrisa.test')->firstOrFail();
        $sonrisa->companies()->first()->subscription->update(['features_override' => ['caja', 'finanzas']]);

        $this->actingAs($sonrisa)->get(route('finance-accounts.show', $bank))->assertNotFound();
        $this->actingAs($sonrisa)->get(route('finance-accounts.index'))->assertOk()->assertDontSee('BANCO');
    }

    public function test_cajero_registra_gastos_pero_no_ve_cuentas(): void
    {
        $cajero = User::create(['name' => 'cajero_demo', 'email' => 'cajero@clinicademo.test', 'password' => 'Secreta123']);
        $cajero->companies()->attach($this->company->id, ['role_id' => \App\Models\Role::where('slug', 'cajero')->value('id'), 'active' => true]);

        $this->actingAs($cajero)->get(route('expenses.index'))->assertOk();
        $this->actingAs($cajero)->get(route('cash.index'))->assertOk();
        $this->actingAs($cajero)->get(route('finance-accounts.index'))->assertForbidden();
    }

    public function test_las_pantallas_cargan(): void
    {
        $bank = $this->account('Banco', 10);
        $supplier = $this->inTenant(fn () => Supplier::create(['name' => 'Depósito Dental', 'active' => true]));
        $session = $this->openCash(50);

        foreach (['general', 'proveedor', 'personal', 'basico', 'todos'] as $tab) {
            $this->as()->get(route('expenses.index', ['tab' => $tab]))->assertOk();
        }
        foreach (['general', 'proveedor', 'personal', 'basico'] as $group) {
            $this->as()->get(route('expenses.create', ['group' => $group]))->assertOk()->assertSee('¿De dónde sale el dinero?');
        }
        foreach ([
            route('finance-accounts.index'), route('finance-accounts.show', $bank), route('expense-categories.index'),
            route('suppliers.index'), route('suppliers.create'), route('suppliers.show', $supplier), route('suppliers.edit', $supplier),
            route('cash.index'), route('cash.show', $session), route('payment-methods.index'), route('dashboard'),
        ] as $url) {
            $this->as()->get($url)->assertOk();
        }
    }
}
