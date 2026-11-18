<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Personal;
use App\Models\Supplier;
use App\Support\BasicServices;
use App\Support\Expenses;
use App\Support\PaymentSource;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Gastos, en pestañas que no se repiten: Generales · Proveedores ·
 * Personal · Servicios básicos · Todos. Se registran con el origen del dinero
 * (cuenta o caja) y no se editan: se anulan.
 */
class ExpenseController extends Controller
{
    public const TABS = [
        'general' => ['Generales', 'bi-receipt'],
        'proveedor' => ['Proveedores', 'bi-truck'],
        'personal' => ['Personal', 'bi-person-badge'],
        'basico' => ['Servicios básicos', 'bi-lightning-charge'],
        'todos' => ['Todos', 'bi-list-ul'],
    ];

    /** Botón de alta de cada pestaña. */
    public const CREATE_LABELS = [
        'general' => 'Nuevo gasto',
        'proveedor' => 'Pago a proveedor',
        'personal' => 'Pago a personal',
        'basico' => 'Pago de servicio',
    ];

    public function index()
    {
        ExpenseCategory::ensureDefaults();
        $tab = array_key_exists(request('tab'), self::TABS) ? request('tab') : 'general';
        [$from, $to] = $this->period();

        $query = $this->filtered($tab, $from, $to, request()->only(['category_id', 'source', 'status']));
        $valid = (clone $query)->valid();

        return view('admin.expenses.index', [
            'tab' => $tab,
            'expenses' => (clone $query)->with(['category', 'supplier', 'personal', 'account', 'cashSession.caja'])
                ->latest('spent_at')->latest('id')->paginate(25)->withQueryString(),
            'total' => round((float) $valid->sum('amount'), 2),
            'count' => (clone $query)->valid()->count(),
            'categories' => ExpenseCategory::when($tab !== 'todos', fn ($q) => $q->group($tab))->orderBy('name')->get(),
            'sources' => PaymentSource::available() + (Expense::where('source', 'external')->exists() ? ['external' => 'Pagado por fuera'] : []),
            'basics' => $tab === 'basico' ? BasicServices::month(request('month')) : null,
            // Pestaña Personal: lo que se debe en comisiones, de cualquier fecha (§6).
            'commissionsPending' => $tab === 'personal' && \App\Support\Commissions::enabled()
                && auth()->user()->hasPermissionInCompany('commissions.view', app(\App\Support\Tenancy::class)->company())
                ? \App\Support\Commissions::pending() : null,
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function create()
    {
        ExpenseCategory::ensureDefaults();
        $group = in_array(request('group'), Expenses::GROUPS, true) ? request('group') : 'general';

        return view('admin.expenses.create', [
            'group' => $group,
            'categories' => ExpenseCategory::group($group)->selectable()->orderBy('sort_order')->orderBy('name')->get(),
            'suppliers' => $group === 'proveedor' ? Supplier::where('active', true)->orderBy('name')->get(['id', 'name']) : collect(),
            'people' => $group === 'personal' ? Personal::where('active', true)->orderBy('full_name')->get(['id', 'full_name']) : collect(),
            'branches' => Branch::where('active', true)->orderBy('name')->get(['id', 'name']),
            'source' => PaymentSource::options(),
            // Desde «Registrar» del control de servicios: el servicio y el último importe ya puestos.
            'preset' => [
                'expense_category_id' => request('category_id'),
                'amount' => request('amount'),
                'supplier_id' => request('supplier_id'),
                'personal_id' => request('personal_id'),
            ],
        ]);
    }

    public function store()
    {
        $expense = Expenses::register(request()->except('receipt'), request()->file('receipt'));
        $tab = $expense->category?->group ?? 'general';

        return redirect()->route('expenses.index', ['tab' => $tab])->with('success', "Gasto {$expense->code} registrado: {$expense->concept}.");
    }

    public function show(Expense $expense)
    {
        return view('admin.expenses.show', [
            'expense' => $expense->load(['category', 'supplier', 'personal', 'account', 'cashSession.caja', 'paymentMethod', 'user', 'canceller', 'branch']),
        ]);
    }

    /** El comprobante vive en el disco privado: solo lo abre quien puede ver el gasto. */
    public function receipt(Expense $expense)
    {
        abort_unless($expense->receipt && Storage::disk('local')->exists($expense->receipt), 404);

        return Storage::disk('local')->response($expense->receipt);
    }

    public function cancel(Expense $expense)
    {
        $reason = request()->validate(['cancel_reason' => 'required|string|max:255'], [], ['cancel_reason' => 'motivo'])['cancel_reason'];
        Expenses::cancel($expense, $reason);

        return back()->with('success', "Gasto {$expense->code} anulado: el dinero volvió a donde salió.");
    }

    // ---- Compartido con la API ----

    /** @return array{0: Carbon, 1: Carbon} por defecto, el mes en curso */
    protected function period(): array
    {
        $from = request('from') ? Carbon::parse(request('from'))->startOfDay() : now()->startOfMonth();
        $to = request('to') ? Carbon::parse(request('to'))->endOfDay() : now()->endOfMonth();

        return $from->lte($to) ? [$from, $to] : [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
    }

    /** Gastos de una pestaña en el período, con los filtros extra. */
    protected function filtered(string $tab, Carbon $from, Carbon $to, array $filters = []): Builder
    {
        return Expense::query()
            ->whereBetween('spent_at', [$from->toDateString(), $to->toDateString()])
            // Los gastos viejos sin categoría van con los generales.
            ->when($tab === 'general', fn ($q) => $q->where(fn ($w) => $w->whereHas('category', fn ($c) => $c->group('general'))->orWhereNull('expense_category_id')))
            ->when(! in_array($tab, ['todos', 'general'], true), fn ($q) => $q->whereHas('category', fn ($c) => $c->group($tab)))
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('expense_category_id', $id))
            ->when($filters['source'] ?? null, fn ($q, $source) => $q->where('source', $source))
            ->when(($filters['status'] ?? null) === 'anulado', fn ($q) => $q->where('status', 'anulado'));
    }
}
