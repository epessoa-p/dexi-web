<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\Personal;
use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Cash;
use App\Support\Purchases;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * API de la fase 5 de la app: laboratorio (orden nueva y su recorrido),
 * inventario (escáner, entradas, salidas y conteo, recibir una compra) y el
 * comprobante del gasto con el token. Permisos y aislamiento.
 */
class OperationsApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $demo;

    private User $admin;

    private Patient $maria;

    private Personal $ana;

    private Supplier $lab;

    private Product $anestesia;

    private Product $guantes;

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
            $this->anestesia = Product::create(['name' => 'Lidocaína 2%', 'barcode' => '7791234567890', 'unit' => 'cartucho', 'min_stock' => 10, 'track_batches' => true, 'active' => true]);
            $this->guantes = Product::create(['name' => 'Guantes de nitrilo', 'code' => 'GUA-01', 'unit' => 'par', 'active' => true]);
        });
    }

    private function api(?User $user = null)
    {
        $this->app['auth']->forgetGuards();
        $token = ($user ?? $this->admin)->createToken('test')->plainTextToken;

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Company-Id' => $this->demo->id, 'Accept' => 'application/json']);
    }

    public function test_laboratorio_orden_nueva_y_su_recorrido(): void
    {
        $this->api()->getJson("/api/v1/lab-orders/options?patient_id={$this->maria->id}")->assertOk()
            ->assertJsonPath('labs.0.name', 'LAB CERÁMICO')->assertJsonPath('shades.1', 'A2')
            ->assertJsonStructure(['work_types' => [['key', 'label']], 'materials', 'professionals', 'plan_items']);

        $order = $this->api()->postJson('/api/v1/lab-orders', [
            'patient_id' => $this->maria->id, 'supplier_id' => $this->lab->id, 'personal_id' => $this->ana->id,
            'work_type' => 'corona', 'teeth' => '16', 'material' => 'Zirconio', 'shade' => 'A2', 'due_at' => today()->addDays(7)->toDateString(),
        ])->assertCreated()->assertJsonPath('status', 'enviado')->assertJsonPath('summary', 'Corona · Zirconio · pieza 16 · A2')
            ->assertJsonPath('next.0.label', 'Llegó del laboratorio');
        $id = $order->json('id');

        $this->api()->getJson('/api/v1/lab-orders')->assertOk()->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('filters.0.count', 1);
        $this->api()->postJson("/api/v1/lab-orders/{$id}/status", ['status' => 'recibido'])->assertOk()
            ->assertJsonPath('status', 'recibido')->assertJsonPath('next.0.label', 'Instalado en boca')->assertJsonPath('next.1.label', 'Devolver para ajuste');
        $this->api()->postJson("/api/v1/lab-orders/{$id}/status", ['status' => 'en_proceso', 'note' => 'Contacto alto'])->assertOk()
            ->assertJsonPath('returns_count', 1);
        $this->api()->postJson("/api/v1/lab-orders/{$id}/status", ['status' => 'instalado'])->assertStatus(422);
        $this->api()->getJson("/api/v1/lab-orders?patient_id={$this->maria->id}")->assertJsonPath('filter', 'todos')->assertJsonCount(1, 'data');
        $this->api()->getJson("/api/v1/lab-orders/{$id}")->assertJsonCount(3, 'log');
    }

    public function test_inventario_escaner_movimientos_y_conteo(): void
    {
        $this->api()->getJson('/api/v1/products/scan/7791234567890')->assertOk()->assertJsonPath('name', 'Lidocaína 2%')->assertJsonPath('stock', 0);
        $this->api()->getJson('/api/v1/products/scan/GUA-01')->assertOk()->assertJsonPath('name', 'Guantes de nitrilo');
        $this->api()->getJson('/api/v1/products/scan/000')->assertNotFound();

        $this->api()->getJson('/api/v1/stock-movements/options')->assertOk()->assertJsonStructure(['branches', 'default_branch', 'in_reasons', 'out_reasons']);

        // La anestesia lleva lote: sin lote no entra.
        $this->api()->postJson('/api/v1/stock-movements', ['kind' => 'entrada', 'product_id' => $this->anestesia->id, 'quantity' => 20, 'reason' => 'initial'])
            ->assertStatus(422);
        $entry = $this->api()->postJson('/api/v1/stock-movements', [
            'kind' => 'entrada', 'product_id' => $this->anestesia->id, 'quantity' => 20, 'reason' => 'initial',
            'lot' => 'L-77', 'expires_at' => today()->addDays(30)->toDateString(),
        ])->assertCreated()->assertJsonPath('stock', 20)->assertJsonPath('batches.0.lot', 'L-77')->assertJsonPath('batches.0.expiring', true);

        $this->api()->postJson('/api/v1/stock-movements', ['kind' => 'salida', 'product_id' => $this->anestesia->id, 'quantity' => 12, 'reason' => 'internal_use'])
            ->assertCreated()->assertJsonPath('stock', 8)->assertJsonPath('low', true);
        $this->api()->postJson('/api/v1/stock-movements', ['kind' => 'salida', 'product_id' => $this->anestesia->id, 'quantity' => 50, 'reason' => 'loss'])
            ->assertStatus(422);
        // Conteo físico del lote: había 7 (con lotes, se cuenta lote por lote).
        $this->api()->postJson('/api/v1/stock-movements', ['kind' => 'ajuste', 'product_id' => $this->anestesia->id, 'quantity' => 7])->assertStatus(422);
        $this->api()->postJson('/api/v1/stock-movements', ['kind' => 'ajuste', 'product_id' => $this->anestesia->id, 'quantity' => 7, 'product_batch_id' => $entry->json('batches.0.id')])
            ->assertCreated()->assertJsonPath('stock', 7)->assertJsonPath('movements.0.type', 'adjustment');
        $this->api()->postJson('/api/v1/stock-movements', ['kind' => 'traslado', 'product_id' => $this->anestesia->id, 'quantity' => 1])->assertStatus(422);

        $this->api()->getJson('/api/v1/products?filter=bajo')->assertOk()->assertJsonPath('counts.low', 1)->assertJsonPath('counts.expiring', 1)
            ->assertJsonPath('data.0.name', 'Lidocaína 2%');
        $this->api()->getJson('/api/v1/products?q=guantes')->assertJsonCount(1, 'data');
    }

    public function test_recibir_una_compra(): void
    {
        $purchase = app(Tenancy::class)->runFor($this->demo->id, function () {
            auth()->setUser($this->admin);

            return Purchases::create(['supplier_id' => $this->lab->id, 'purchased_at' => today()->toDateString(), 'items' => [
                ['product_id' => $this->guantes->id, 'quantity' => 10, 'unit_cost' => 2],
                ['product_id' => $this->anestesia->id, 'quantity' => 5, 'unit_cost' => 6],
            ]]);
        });
        $pending = $this->api()->getJson('/api/v1/purchases/pending')->assertOk()->assertJsonPath('data.0.id', $purchase->id)
            ->assertJsonPath('data.0.items.0.pending', 10);
        $items = collect($pending->json('data.0.items'))->keyBy('product_id');

        $this->api()->postJson("/api/v1/purchases/{$purchase->id}/receive", ['lines' => [
            ['item_id' => $items[$this->guantes->id]['id'], 'quantity' => 10],
        ]])->assertCreated()->assertJsonPath('status', 'parcial');
        $this->api()->postJson("/api/v1/purchases/{$purchase->id}/receive", ['lines' => [
            ['item_id' => $items[$this->anestesia->id]['id'], 'quantity' => 5, 'lot' => 'A1', 'expires_at' => today()->addYear()->toDateString()],
        ]])->assertCreated()->assertJsonPath('status', 'recibida');
        $this->api()->getJson('/api/v1/purchases/pending')->assertJsonCount(0, 'data');
        $this->assertSame(10.0, $this->guantes->fresh()->stockIn());
    }

    public function test_gasto_con_foto_del_comprobante_y_permisos(): void
    {
        Storage::fake('local');
        $session = app(Tenancy::class)->runFor($this->demo->id, fn () => Cash::open(Caja::create(['name' => 'Caja 1', 'active' => true]), 500));
        $category = app(Tenancy::class)->runFor($this->demo->id, function () {
            ExpenseCategory::ensureDefaults();

            return ExpenseCategory::group('general')->selectable()->firstOrFail();
        });

        $expense = $this->api()->post('/api/v1/finance/expenses', [
            'group' => 'general', 'expense_category_id' => $category->id, 'concept' => 'Agua para la sala', 'amount' => 30,
            'spent_at' => today()->toDateString(), 'source' => 'cash', 'cash_session_id' => $session->id,
            'receipt' => UploadedFile::fake()->image('factura.jpg'),
        ])->assertCreated();
        $path = $expense->json('receipt_path');
        $this->assertSame("/finance/expenses/{$expense->json('id')}/receipt", $path);
        $this->api()->get('/api/v1' . $path)->assertOk();

        // El cajero no tiene laboratorio ni movimientos de inventario.
        $user = User::create(['name' => 'caj', 'email' => 'caj@clinicademo.test', 'password' => 'Secreta123']);
        $user->companies()->attach($this->demo->id, ['role_id' => Role::where('slug', 'cajero')->value('id'), 'active' => true]);
        $this->api($user)->getJson('/api/v1/lab-orders')->assertForbidden();
        $this->api($user)->postJson('/api/v1/stock-movements', ['kind' => 'ajuste', 'product_id' => $this->guantes->id, 'quantity' => 1])->assertForbidden();

        // Otra clínica no ve el producto.
        $sonrisa = User::where('email', 'admin@sonrisa.test')->firstOrFail();
        $this->app['auth']->forgetGuards();
        $token = $sonrisa->createToken('t')->plainTextToken;
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Company-Id' => $sonrisa->companies()->first()->id, 'Accept' => 'application/json'])
            ->getJson("/api/v1/products/{$this->guantes->id}")->assertNotFound();
        $this->assertSame(0, LabOrder::allCompanies()->count());
    }
}
