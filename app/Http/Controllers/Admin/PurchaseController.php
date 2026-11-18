<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\Supplier;
use App\Support\Money;
use App\Support\PaymentSource;
use App\Support\Purchases;

/** Compras › Compras (P-000001), recepción, pagos en cuotas y Cuentas por pagar. */
class PurchaseController extends Controller
{
    public const FILTERS = [
        'abiertas' => ['Pendientes', 'bi-hourglass-split'],
        'recibir' => ['Por recibir', 'bi-truck'],
        'pagar' => ['Por pagar', 'bi-cash-stack'],
        'todas' => ['Todas', 'bi-list-ul'],
    ];

    public function index()
    {
        $filter = array_key_exists(request('filter'), self::FILTERS) ? request('filter') : 'abiertas';
        $base = Purchase::query()->when(request('supplier'), fn ($q, $s) => $q->where('supplier_id', $s));
        $scope = fn ($q, string $f) => match ($f) {
            'recibir' => $q->whereIn('status', ['pedida', 'parcial']),
            'pagar' => $q->payable(),
            'abiertas' => $q->where('status', '!=', 'anulada')->where(fn ($w) => $w->whereIn('status', ['pedida', 'parcial'])->orWhere('balance', '>', 0)),
            default => $q,
        };

        return view('admin.purchases.index', [
            'purchases' => $scope((clone $base)->with(['supplier', 'branch']), $filter)->latest('purchased_at')->latest('id')->paginate(25)->withQueryString(),
            'filter' => $filter,
            'counts' => collect(self::FILTERS)->mapWithKeys(fn ($v, $k) => [$k => $scope(clone $base, $k)->count()]),
            'payable' => round((float) Purchase::payable()->sum('balance'), 2),
            'overdue' => Purchase::payable()->whereNotNull('due_date')->whereDate('due_date', '<', today())->count(),
            'suppliers' => Supplier::where('active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create()
    {
        return view('admin.purchases.create', [
            'suppliers' => Supplier::where('active', true)->orderBy('name')->get(['id', 'name']),
            'branches' => Branch::where('active', true)->orderBy('name')->get(),
            'products' => Product::where('active', true)->orderBy('name')->get(['id', 'name', 'presentation', 'unit', 'purchase_price', 'track_batches']),
            'source' => PaymentSource::options(),
            'preselected' => Product::whereIn('id', (array) request('products', []))->get(['id', 'name', 'presentation', 'purchase_price', 'track_batches', 'min_stock']),
        ]);
    }

    public function store()
    {
        $purchase = Purchases::create(request()->all() + ['receive_now' => request()->boolean('receive_now')]);

        return redirect()->route('purchases.show', $purchase)->with('success', "Compra {$purchase->code} registrada.");
    }

    public function show(Purchase $purchase)
    {
        return view('admin.purchases.show', [
            'purchase' => $purchase->load(['supplier', 'branch', 'user', 'items.product', 'items.purchase', 'receptions.items.item.product', 'receptions.user',
                'payments.account', 'payments.cashSession.caja', 'payments.user']),
            'source' => PaymentSource::options(),
        ]);
    }

    public function receive(Purchase $purchase)
    {
        $lines = collect(request('lines', []))->map(fn ($line, $itemId) => ['item_id' => $itemId] + (array) $line)->values()->all();
        Purchases::receive($purchase, ['lines' => $lines, 'notes' => request('notes')]);

        return back()->with('success', 'Recepción registrada: la mercadería entró al stock.');
    }

    public function pay(Purchase $purchase)
    {
        $payment = Purchases::pay($purchase, request()->all());

        return back()->with('success', 'Pago de ' . Money::format($payment->amount) . ' registrado.');
    }

    public function cancelPayment(Purchase $purchase, PurchasePayment $payment)
    {
        abort_unless($payment->purchase_id === $purchase->id, 404);
        Purchases::cancelPayment($payment, request()->validate(['cancel_reason' => 'required|string|max:255'], [], ['cancel_reason' => 'motivo'])['cancel_reason']);

        return back()->with('success', 'Pago anulado: el dinero volvió a donde salió.');
    }

    public function close(Purchase $purchase)
    {
        Purchases::closeWithDifferences($purchase, request()->validate(['closing_notes' => 'required|string|max:255'], [], ['closing_notes' => 'motivo'])['closing_notes']);

        return back()->with('success', 'Compra cerrada con lo recibido.');
    }

    public function cancel(Purchase $purchase)
    {
        Purchases::cancel($purchase, request()->validate(['cancel_reason' => 'required|string|max:255'], [], ['cancel_reason' => 'motivo'])['cancel_reason']);

        return back()->with('success', "Compra {$purchase->code} anulada.");
    }

    /** Cuentas por pagar: a quién se le debe, cuánto y desde cuándo. */
    public function payables()
    {
        $rows = Purchase::payable()->with('supplier')->orderBy('due_date')->get()->groupBy('supplier_id')
            ->map(fn ($purchases) => [
                'supplier' => $purchases->first()->supplier,
                'purchases' => $purchases,
                'balance' => round((float) $purchases->sum('balance'), 2),
                'overdue' => $purchases->contains(fn (Purchase $p) => $p->isOverdue()),
                'next_due' => $purchases->whereNotNull('due_date')->min('due_date'),
            ])->sortByDesc('balance')->values();

        return view('admin.purchases.payables', [
            'rows' => $rows,
            'total' => round($rows->sum('balance'), 2),
            'overdue' => $rows->where('overdue', true)->count(),
        ]);
    }
}
