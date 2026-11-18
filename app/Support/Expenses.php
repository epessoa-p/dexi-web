<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Personal;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Registro y anulación de gastos. Una sola validación para la web y
 * la API: no pueden divergir.
 *
 * - `group` (general, basico, proveedor, personal) es la pestaña desde la que
 *   se carga; en proveedor y personal la categoría se elige sola.
 * - Si no escriben la descripción: «Pago a Distribuidora Sur», «Luz · noviembre 2026»…
 *   Un gasto general sí la exige.
 */
final class Expenses
{
    public const GROUPS = ['general', 'basico', 'proveedor', 'personal'];

    public static function register(array $input, ?UploadedFile $receipt = null): Expense
    {
        $companyId = app(Tenancy::class)->id();
        $exists = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $companyId);

        $group = in_array($input['group'] ?? null, self::GROUPS, true) ? $input['group'] : null;
        if (empty($input['expense_category_id']) && in_array($group, ['proveedor', 'personal'], true)) {
            $input['expense_category_id'] = ExpenseCategory::defaultFor($group)->id;
        }

        $validated = validator($input + ['receipt' => $receipt], [
            'expense_category_id' => ['required', $exists('expense_categories')->where('active', true)->whereNot('name', ExpenseCategory::COMMISSIONS)],
            'supplier_id' => ['nullable', $exists('suppliers')->whereNull('deleted_at')],
            'personal_id' => ['nullable', $exists('personal')->whereNull('deleted_at')],
            'concept' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'spent_at' => ['required', 'date', 'before_or_equal:today'],
            'branch_id' => ['nullable', $exists('branches')],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ], [
            'expense_category_id.required' => $group === 'basico' ? 'Elige el servicio que pagas.' : 'Elige la categoría del gasto.',
            'spent_at.before_or_equal' => 'La fecha no puede ser futura.',
        ], [
            'expense_category_id' => 'categoría', 'supplier_id' => 'proveedor', 'personal_id' => 'personal',
            'concept' => 'descripción', 'amount' => 'importe', 'spent_at' => 'fecha', 'reference' => 'referencia', 'receipt' => 'comprobante',
        ])->validate();

        $category = ExpenseCategory::findOrFail($validated['expense_category_id']);
        if ($group && $category->group !== $group) {
            throw ValidationException::withMessages(['expense_category_id' => 'Esa categoría no corresponde a esta pestaña.']);
        }

        // Solo se guarda a quién se paga si la categoría lo pide.
        $supplier = $category->group === 'proveedor' && ! empty($validated['supplier_id']) ? Supplier::find($validated['supplier_id']) : null;
        $personal = $category->group === 'personal' && ! empty($validated['personal_id']) ? Personal::find($validated['personal_id']) : null;
        if ($category->group === 'proveedor' && ! $supplier) {
            throw ValidationException::withMessages(['supplier_id' => 'Elige el proveedor al que le pagas.']);
        }
        if ($category->group === 'personal' && ! $personal) {
            throw ValidationException::withMessages(['personal_id' => 'Elige a la persona a la que le pagas.']);
        }

        $concept = trim((string) ($validated['concept'] ?? '')) ?: self::autoConcept($category, $supplier?->name ?? $personal?->full_name, $validated['spent_at']);
        if ($concept === '') {
            throw ValidationException::withMessages(['concept' => 'Escribe en qué se gastó.']);
        }

        $from = PaymentSource::validate($input);

        return self::record([
            'expense_category_id' => $category->id,
            'supplier_id' => $supplier?->id,
            'personal_id' => $personal?->id,
            'concept' => $concept,
            'amount' => round((float) $validated['amount'], 2),
            'spent_at' => $validated['spent_at'],
            'branch_id' => $validated['branch_id'] ?? $from['session']?->caja?->branch_id ?? $from['account']?->branch_id,
            'reference' => $validated['reference'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ], $from, $receipt);
    }

    /**
     * Crea el gasto y saca el dinero, todo o nada.
     *
     * @param  array{source: string, account: ?\App\Models\FinanceAccount, session: ?\App\Models\CashSession}  $from
     */
    public static function record(array $data, array $from, ?UploadedFile $receipt = null): Expense
    {
        return DB::transaction(function () use ($data, $from, $receipt) {
            $companyId = app(Tenancy::class)->id();
            $number = (int) Expense::forCompany($companyId)->withTrashed()->lockForUpdate()->max('number') + 1;

            $expense = Expense::create($data + [
                'number' => $number,
                'source' => $from['source'],
                'finance_account_id' => $from['account']?->id,
                'cash_session_id' => $from['session']?->id,
                'user_id' => auth()->id(),
                'status' => 'registrado',
                'receipt' => $receipt?->store('expenses/' . $companyId, 'local'),
            ]);

            $expense->update(PaymentSource::charge($from, (float) $expense->amount, "{$expense->code} · {$expense->concept}", 'gasto',
                $expense, $expense->spent_at->toDateString(), $expense->reference));

            return $expense;
        });
    }

    /** Anula: el dinero vuelve a donde salió. */
    public static function cancel(Expense $expense, string $reason, bool $fromSettlement = false): void
    {
        DB::transaction(function () use ($expense, $reason, $fromSettlement) {
            $expense = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();

            // El pago de una liquidación se anula desde la liquidación (llega con el módulo de comisiones).
            if ($expense->commission_settlement_id && ! $fromSettlement) {
                throw ValidationException::withMessages(['cancel_reason' => 'Este gasto es el pago de una liquidación de comisiones: anúlala desde Comisiones.']);
            }

            if ($expense->isCancelled()) {
                throw ValidationException::withMessages(['cancel_reason' => 'Ese gasto ya está anulado.']);
            }

            PaymentSource::refund($expense, "Anula {$expense->code} · {$expense->concept}");
            // Si pagaba trabajos de laboratorio, vuelven a quedar por pagar.
            LabOrders::onExpenseCancelled($expense);

            $expense->update([
                'status' => 'anulado',
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'cancel_reason' => $reason,
            ]);
        });
    }

    /** La descripción que se completa sola. */
    public static function autoConcept(ExpenseCategory $category, ?string $payee, string|\DateTimeInterface $date): string
    {
        $date = Carbon::parse($date)->locale('es');

        return match ($category->group) {
            'proveedor', 'personal' => $payee ? "Pago a {$payee}" : '',
            'basico' => $category->frequency === 'anual'
                ? "{$category->name} · {$date->year}"
                : "{$category->name} · {$date->translatedFormat('F Y')}",
            default => '',
        };
    }
}
