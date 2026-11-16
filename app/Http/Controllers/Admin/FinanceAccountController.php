<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\FinanceAccount;
use App\Models\FinanceMovement;
use App\Support\Tenancy;
use App\Support\Treasury;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Cuentas de la tesorería (Finanzas): banco, caja chica, capital… con su
 * extracto, ingresos y egresos manuales, transferencias y anulaciones.
 * Las validaciones viven aquí y la API las reutiliza (Api\V1\FinanceController).
 */
class FinanceAccountController extends Controller
{
    public function index()
    {
        $accounts = FinanceAccount::with('branch')->orderByDesc('active')->orderBy('name')->get();

        return view('admin.finance.accounts.index', [
            'accounts' => $accounts,
            'total' => round((float) $accounts->where('active', true)->sum('balance'), 2),
            'branches' => Branch::where('active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store()
    {
        $account = $this->createAccount(request()->all());

        return redirect()->route('finance-accounts.show', $account)->with('success', "Cuenta {$account->name} creada.");
    }

    public function update(FinanceAccount $account)
    {
        $account->update($this->validateAccount(request()->all(), $account));

        return back()->with('success', 'Cuenta actualizada.');
    }

    public function show(FinanceAccount $account)
    {
        return view('admin.finance.accounts.show', [
            'account' => $account->load('branch'),
            'movements' => $account->movements()->with(['creator', 'source'])->latest('id')->paginate(30),
            'others' => FinanceAccount::where('active', true)->whereKeyNot($account->id)->orderBy('name')->get(),
            'branches' => Branch::where('active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Solo se da de baja una cuenta en cero: su historial queda. */
    public function destroy(FinanceAccount $account)
    {
        if (round((float) $account->balance, 2) !== 0.0) {
            return back()->withErrors(['error' => 'Para eliminar la cuenta, primero deja su saldo en cero (transfiérelo a otra).']);
        }

        $account->update(['active' => false]);
        $account->delete();

        return redirect()->route('finance-accounts.index')->with('success', "Cuenta {$account->name} eliminada.");
    }

    public function move(FinanceAccount $account)
    {
        $movement = $this->registerMovement($account, request()->all());

        return back()->with('success', ($movement->isIncome() ? 'Ingreso' : 'Egreso') . ' registrado.');
    }

    public function transfer(FinanceAccount $account)
    {
        $this->registerTransfer($account, request()->all());

        return back()->with('success', 'Transferencia registrada.');
    }

    public function cancelMovement(FinanceMovement $movement)
    {
        $this->annulMovement($movement);

        return back()->with('success', 'Movimiento anulado.');
    }

    // ---- Compartido con la API ----

    protected function createAccount(array $input): FinanceAccount
    {
        $data = $this->validateAccount($input);
        $opening = round((float) validator($input, ['opening_balance' => 'nullable|numeric|min:0|max:999999999'], [], ['opening_balance' => 'saldo inicial'])
            ->validate()['opening_balance'] ?? 0, 2);

        return DB::transaction(function () use ($data, $opening) {
            $account = FinanceAccount::create($data + ['opening_balance' => $opening, 'balance' => 0]);
            if ($opening > 0) {
                Treasury::move($account, 'ingreso', $opening, 'Saldo inicial', ['origin' => 'apertura']);
            }

            return $account->fresh();
        });
    }

    /** @return array<string, mixed> */
    protected function validateAccount(array $input, ?FinanceAccount $account = null): array
    {
        $companyId = app(Tenancy::class)->id();
        $validated = validator($input, [
            'name' => ['required', 'string', 'max:80',
                Rule::unique('finance_accounts', 'name')->where('company_id', $companyId)->whereNull('deleted_at')->ignore($account?->id)],
            'type' => ['required', Rule::in(array_keys(FinanceAccount::TYPES))],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'bank_name' => ['nullable', 'string', 'max:80'],
            'account_number' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'active' => ['nullable', 'boolean'],
        ], ['name.unique' => 'Ya hay una cuenta con ese nombre.'], [
            'name' => 'nombre', 'type' => 'tipo', 'bank_name' => 'banco', 'account_number' => 'número de cuenta',
        ])->validate();

        return [
            'name' => trim($validated['name']),
            'type' => $validated['type'],
            'branch_id' => $validated['branch_id'] ?? null,
            'bank_name' => $validated['bank_name'] ?? null,
            'account_number' => $validated['account_number'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'active' => $account && array_key_exists('active', $validated) ? (bool) $validated['active'] : ($account?->active ?? true),
        ];
    }

    protected function registerMovement(FinanceAccount $account, array $input): FinanceMovement
    {
        $validated = validator($input, [
            'type' => ['required', Rule::in(['ingreso', 'egreso'])],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            'concept' => ['required', 'string', 'max:255'],
            'occurred_on' => ['nullable', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:80'],
        ], ['occurred_on.before_or_equal' => 'La fecha no puede ser futura.'], [
            'type' => 'tipo', 'amount' => 'importe', 'concept' => 'concepto', 'occurred_on' => 'fecha', 'reference' => 'referencia',
        ])->validate();

        return Treasury::move($account, $validated['type'], (float) $validated['amount'], trim($validated['concept']), [
            'origin' => 'manual',
            'occurred_on' => $validated['occurred_on'] ?? null,
            'reference' => $validated['reference'] ?? null,
        ]);
    }

    /** @return array{0: FinanceMovement, 1: FinanceMovement} */
    protected function registerTransfer(FinanceAccount $account, array $input): array
    {
        $validated = validator($input, [
            'to_account_id' => ['required', Rule::exists('finance_accounts', 'id')->where('company_id', app(Tenancy::class)->id())
                ->where('active', true)->whereNull('deleted_at')],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            'concept' => ['nullable', 'string', 'max:200'],
            'occurred_on' => ['nullable', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:80'],
        ], ['to_account_id.required' => 'Elige la cuenta de destino.', 'occurred_on.before_or_equal' => 'La fecha no puede ser futura.'], [
            'to_account_id' => 'cuenta de destino', 'amount' => 'importe', 'concept' => 'concepto', 'occurred_on' => 'fecha',
        ])->validate();

        return Treasury::transfer($account, FinanceAccount::findOrFail($validated['to_account_id']), (float) $validated['amount'],
            trim($validated['concept'] ?? '') ?: 'Transferencia', $validated['occurred_on'] ?? null, $validated['reference'] ?? null);
    }

    protected function annulMovement(FinanceMovement $movement): void
    {
        if (! $movement->isCancellable()) {
            throw ValidationException::withMessages(['movement' => match (true) {
                $movement->isCancelled() => 'Ese movimiento ya está anulado.',
                $movement->origin === 'gasto' => 'Ese movimiento es de un gasto: anúlalo desde el gasto.',
                $movement->origin === 'compra' => 'Ese movimiento es un pago de compra: no se anula desde la cuenta.',
                default => 'Ese movimiento no se puede anular.',
            }]);
        }

        Treasury::cancel($movement);
    }
}
