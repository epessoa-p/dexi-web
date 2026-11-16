<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Admin\FinanceAccountController;
use App\Models\FinanceAccount;
use App\Models\FinanceMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cuentas de la tesorería en la API (Finanzas): mismas validaciones y el
 * mismo servicio (Treasury) que la web.
 */
class FinanceController extends FinanceAccountController
{
    public function accounts(): JsonResponse
    {
        $accounts = FinanceAccount::with('branch')->orderByDesc('active')->orderBy('name')->get();

        return response()->json([
            'total' => round((float) $accounts->where('active', true)->sum('balance'), 2),
            'types' => collect(FinanceAccount::TYPES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'data' => $accounts->map(fn (FinanceAccount $a) => self::account($a))->values(),
        ]);
    }

    public function addAccount(Request $request): JsonResponse
    {
        return response()->json(self::account($this->createAccount($request->all())), 201);
    }

    public function changeAccount(Request $request, FinanceAccount $account): JsonResponse
    {
        $account->update($this->validateAccount($request->all(), $account));

        return response()->json(self::account($account->fresh()));
    }

    /** Extracto: del más reciente al más antiguo, con el saldo después de cada movimiento. */
    public function statement(FinanceAccount $account): JsonResponse
    {
        $page = $account->movements()->with('creator')->latest('id')->paginate(30);

        return response()->json([
            'account' => self::account($account),
            'data' => collect($page->items())->map(fn (FinanceMovement $m) => self::movement($m))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function addMovement(Request $request, FinanceAccount $account): JsonResponse
    {
        $movement = $this->registerMovement($account, $request->all());

        return response()->json(['movement' => self::movement($movement), 'account' => self::account($account->fresh())], 201);
    }

    public function addTransfer(Request $request, FinanceAccount $account): JsonResponse
    {
        [$out, $in] = $this->registerTransfer($account, $request->all());

        return response()->json([
            'out' => self::movement($out),
            'in' => self::movement($in),
            'account' => self::account($account->fresh()),
        ], 201);
    }

    public function annul(FinanceMovement $movement): JsonResponse
    {
        $this->annulMovement($movement);

        return response()->json(['movement' => self::movement($movement->fresh()), 'account' => self::account($movement->account->fresh())]);
    }

    /** @return array<string, mixed> */
    public static function account(FinanceAccount $a): array
    {
        return [
            'id' => $a->id,
            'name' => $a->name,
            'type' => $a->type,
            'type_label' => $a->type_label,
            'bank_name' => $a->bank_name,
            'account_number' => $a->account_number,
            'branch_id' => $a->branch_id,
            'branch' => $a->branch?->name,
            'opening_balance' => (float) $a->opening_balance,
            'balance' => (float) $a->balance,
            'notes' => $a->notes,
            'active' => (bool) $a->active,
        ];
    }

    /** @return array<string, mixed> */
    public static function movement(FinanceMovement $m): array
    {
        return [
            'id' => $m->id,
            'type' => $m->type,
            'origin' => $m->origin,
            'origin_label' => $m->origin_label,
            'concept' => $m->concept,
            'amount' => (float) $m->amount,
            'signed_amount' => $m->signed_amount,
            'balance_after' => (float) $m->balance_after,
            'occurred_on' => $m->occurred_on?->toDateString(),
            'reference' => $m->reference,
            'expense_id' => $m->source_type === (new \App\Models\Expense)->getMorphClass() ? $m->source_id : null,
            'user' => $m->creator?->name,
            'cancelled' => $m->isCancelled(),
            'cancellable' => $m->isCancellable(),
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
