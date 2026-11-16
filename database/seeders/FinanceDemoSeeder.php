<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Caja;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\FinanceAccount;
use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Support\Expenses;
use App\Support\Tenancy;
use App\Support\Treasury;
use Illuminate\Database\Seeder;

/**
 * Finanzas de demostración para Clínica Dental Demo: dos cuentas, una caja,
 * un proveedor y algunos gastos (la luz pagada, el alquiler pendiente), para
 * que las pantallas no arranquen vacías. Todo pasa por Treasury/Expenses,
 * igual que en la app: los saldos cuadran con su extracto.
 */
class FinanceDemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('ruc', '1000000001')->first();
        // Los tests parten de una clínica sin movimientos (saldos y numeración desde cero).
        if (! $company || app()->runningUnitTests()) {
            return;
        }

        app(Tenancy::class)->runFor($company->id, function () {
            if (FinanceAccount::exists()) {
                return;
            }

            PaymentMethod::ensureDefaults();
            ExpenseCategory::ensureDefaults();

            $branch = Branch::orderBy('id')->first();
            Caja::firstOrCreate(['name' => 'Caja recepción'], ['branch_id' => $branch?->id, 'active' => true]);

            $bank = FinanceAccount::create(['name' => 'Banco Unión', 'type' => 'banco', 'bank_name' => 'Banco Unión', 'account_number' => '1-2345678', 'balance' => 0]);
            Treasury::move($bank, 'ingreso', 8000, 'Saldo inicial', ['origin' => 'apertura', 'occurred_on' => now()->startOfMonth()->toDateString()]);
            $bank->update(['opening_balance' => 8000]);

            $petty = FinanceAccount::create(['name' => 'Caja chica', 'type' => 'caja_chica', 'balance' => 0]);
            Treasury::move($petty, 'ingreso', 500, 'Saldo inicial', ['origin' => 'apertura', 'occurred_on' => now()->startOfMonth()->toDateString()]);
            $petty->update(['opening_balance' => 500]);

            $supplier = Supplier::create(['name' => 'Depósito Dental Sur', 'tax_id' => '102030405', 'contact_name' => 'Marcela Rojas', 'phone' => '70000000', 'active' => true]);

            $fromBank = ['source' => 'account', 'account' => $bank, 'session' => null];
            $fromPetty = ['source' => 'account', 'account' => $petty, 'session' => null];
            $category = fn (string $name) => ExpenseCategory::where('name', $name)->firstOrFail();
            $day = fn (int $d) => now()->startOfMonth()->addDays(min($d, now()->day - 1))->toDateString();

            Expenses::record(['expense_category_id' => $category('LUZ')->id, 'concept' => Expenses::autoConcept($category('LUZ'), null, now()),
                'amount' => 185.50, 'spent_at' => $day(3), 'reference' => 'F-88231'], $fromBank);
            Expenses::record(['expense_category_id' => $category('INTERNET Y TELÉFONO')->id, 'concept' => Expenses::autoConcept($category('INTERNET Y TELÉFONO'), null, now()),
                'amount' => 249, 'spent_at' => $day(4)], $fromBank);
            Expenses::record(['expense_category_id' => $category('PAGO A PROVEEDORES')->id, 'supplier_id' => $supplier->id, 'concept' => 'Pago a ' . $supplier->name,
                'amount' => 1320, 'spent_at' => $day(6), 'reference' => 'Fact. 4410', 'notes' => 'Resinas, anestesia y guantes'], $fromBank);
            Expenses::record(['expense_category_id' => $category('LIMPIEZA Y MANTENIMIENTO')->id, 'concept' => 'Mantenimiento del compresor',
                'amount' => 120, 'spent_at' => $day(8)], $fromPetty);
        });
    }
}
