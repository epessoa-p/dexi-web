<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Admin\ExpenseController as WebExpenseController;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Personal;
use App\Models\Supplier;
use App\Support\BasicServices;
use App\Support\Expenses;
use App\Support\PaymentSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gastos, servicios básicos y categorías en la API (Finanzas). Registra y
 * anula con App\Support\Expenses, igual que la web.
 */
class ExpenseController extends WebExpenseController
{
    /** Todo lo que necesita el alta de un gasto de un grupo (pestaña). */
    public function options(Request $request): JsonResponse
    {
        ExpenseCategory::ensureDefaults();
        $group = in_array($request->query('group'), Expenses::GROUPS, true) ? $request->query('group') : 'general';
        $categories = ExpenseCategory::group($group)->selectable()->orderBy('sort_order')->orderBy('name')->get();
        $source = PaymentSource::options();

        return response()->json([
            'group' => $group,
            'categories' => $categories->map(fn (ExpenseCategory $c) => self::category($c))->values(),
            'default_category_id' => in_array($group, ['proveedor', 'personal'], true) ? ExpenseCategory::defaultFor($group)->id : null,
            'suppliers' => $group === 'proveedor' ? Supplier::where('active', true)->orderBy('name')->get(['id', 'name']) : [],
            'personal' => $group === 'personal'
                ? Personal::where('active', true)->orderBy('full_name')->get(['id', 'full_name'])->map(fn ($p) => ['id' => $p->id, 'name' => $p->full_name])
                : [],
            'branches' => Branch::where('active', true)->orderBy('name')->get(['id', 'name']),
            'sources' => collect($source['sources'])->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'accounts' => $source['accounts']->map(fn ($a) => FinanceController::account($a))->values(),
            'open_sessions' => $source['sessions']->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->caja?->name ?? "Caja #{$s->id}",
                'branch_id' => $s->caja?->branch_id,
                'expected_cash' => $s->expectedCash(),
            ])->values(),
            'preferred_session_id' => $source['preferred_session_id'],
            'preferred_account_id' => $source['preferred_account_id'],
        ]);
    }

    /** Lista paginada del período, con el total sobre todo el filtro (sin anulados). */
    public function list(Request $request): JsonResponse
    {
        $tab = array_key_exists($request->query('group'), self::TABS) ? $request->query('group') : 'todos';
        [$from, $to] = $this->period();
        $query = $this->filtered($tab, $from, $to, ['category_id' => $request->query('category'), 'source' => $request->query('source')]);
        $page = (clone $query)->with(['category', 'supplier', 'personal', 'account', 'cashSession.caja'])
            ->latest('spent_at')->latest('id')->paginate(25);

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total' => round((float) (clone $query)->valid()->sum('amount'), 2),
            'count' => (clone $query)->valid()->count(),
            'data' => collect($page->items())->map(fn (Expense $e) => self::expense($e))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function detail(Expense $expense): JsonResponse
    {
        return response()->json(self::expense($expense->load(['category', 'supplier', 'personal', 'account', 'cashSession.caja', 'user', 'canceller', 'branch'])));
    }

    /** Alta. `group` permite omitir la categoría en pagos a proveedor o personal. Acepta `receipt` (foto o PDF). */
    public function register(Request $request): JsonResponse
    {
        $expense = Expenses::register($request->except('receipt'), $request->file('receipt'));

        return response()->json(self::expense($expense->fresh(['category', 'supplier', 'personal', 'account', 'cashSession.caja', 'user'])), 201);
    }

    public function annul(Request $request, Expense $expense): JsonResponse
    {
        $reason = $request->validate(['cancel_reason' => 'required|string|max:255'], [], ['cancel_reason' => 'motivo'])['cancel_reason'];
        Expenses::cancel($expense, $reason);

        return response()->json(self::expense($expense->fresh(['category', 'supplier', 'personal', 'account', 'cashSession.caja', 'user', 'canceller'])));
    }

    /** Control del mes de los servicios básicos. */
    public function basics(Request $request): JsonResponse
    {
        $month = BasicServices::month($request->query('month'));

        return response()->json([
            'month' => $month['month']->format('Y-m'),
            'label' => $month['label'],
            'paid' => $month['paid'],
            'pending' => $month['pending'],
            'total' => $month['total'],
            'items' => array_map(fn ($item) => [
                'category' => self::category($item['category']),
                'status' => $item['status'],
                'paid_amount' => $item['paid_amount'],
                'last_amount' => $item['last_amount'],
                'last_paid_on' => $item['last_paid_on']?->toDateString(),
                'next_due' => $item['next_due']?->toDateString(),
            ], $month['items']),
        ]);
    }

    public function categories(): JsonResponse
    {
        ExpenseCategory::ensureDefaults();
        $categories = ExpenseCategory::withCount(['expenses' => fn ($q) => $q->valid()])->orderBy('sort_order')->orderBy('name')->get();

        return response()->json([
            'types' => collect(ExpenseCategory::TYPES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'title' => ExpenseCategory::GROUP_TITLES[$key]])->values(),
            'data' => $categories->map(fn (ExpenseCategory $c) => self::category($c) + ['expenses_count' => $c->expenses_count])->values(),
        ]);
    }

    public function addCategory(Request $request): JsonResponse
    {
        return response()->json(self::category(ExpenseCategory::create(ExpenseCategory::validateForm($request->all()))), 201);
    }

    public function changeCategory(Request $request, ExpenseCategory $category): JsonResponse
    {
        $category->update(ExpenseCategory::validateForm($request->all(), $category));

        return response()->json(self::category($category->fresh()));
    }

    /** @return array<string, mixed> */
    public static function category(ExpenseCategory $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'type' => $c->group,
            'type_label' => $c->type_label,
            'frequency' => $c->recurring ? ($c->frequency ?? 'mensual') : null,
            'label' => $c->service_label,
            'active' => (bool) $c->active,
        ];
    }

    /** @return array<string, mixed> */
    public static function expense(Expense $e): array
    {
        return [
            'id' => $e->id,
            'code' => $e->code,
            'concept' => $e->concept,
            'amount' => (float) $e->amount,
            'spent_at' => $e->spent_at?->toDateString(),
            'category' => $e->category ? self::category($e->category) : null,
            'group' => $e->category?->group ?? 'general',
            'supplier' => $e->supplier ? ['id' => $e->supplier->id, 'name' => $e->supplier->name] : null,
            'personal' => $e->personal ? ['id' => $e->personal->id, 'name' => $e->personal->full_name] : null,
            'payee' => $e->payee_name,
            'source' => $e->source,
            'source_label' => $e->source_label,
            'account_id' => $e->finance_account_id,
            'cash_session_id' => $e->cash_session_id,
            'reference' => $e->reference,
            'notes' => $e->notes,
            'receipt_url' => $e->receipt_url,
            'status' => $e->status,
            'cancelled' => $e->isCancelled(),
            'cancel_reason' => $e->cancel_reason,
            'cancelled_at' => $e->cancelled_at?->toIso8601String(),
            'user' => $e->user?->name,
            'created_at' => $e->created_at?->toIso8601String(),
        ];
    }
}
