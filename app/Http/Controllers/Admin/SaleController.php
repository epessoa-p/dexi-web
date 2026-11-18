<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PaymentMethod;
use App\Models\Procedure;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Support\Cash;
use App\Support\Sales;
use Carbon\Carbon;

/** Ventas y cobros: cobrar (POS), lista, detalle con abonos, recibo y anulación. */
class SaleController extends Controller
{
    public const FILTERS = ['hoy' => 'Hoy', 'mes' => 'Este mes', 'saldo' => 'Con saldo', 'anuladas' => 'Anuladas', 'todas' => 'Todas'];

    public function index()
    {
        $filter = array_key_exists(request('filter'), self::FILTERS) ? request('filter') : 'hoy';
        [$from, $to] = match ($filter) {
            'hoy' => [now()->startOfDay(), now()->endOfDay()],
            'mes' => [now()->startOfMonth(), now()->endOfMonth()],
            default => [null, null],
        };

        $sales = Sale::with(['patient', 'payments.method'])
            ->when($from, fn ($q) => $q->whereBetween('created_at', [$from, $to]))
            ->when($filter === 'saldo', fn ($q) => $q->receivable())
            ->when($filter === 'anuladas', fn ($q) => $q->where('status', 'anulada'))
            ->when(request('q'), fn ($q, $term) => $q->whereHas('patient', fn ($p) => $p->search($term)))
            ->latest('id')
            ->paginate(25)->withQueryString();

        // Lo cobrado (pagos que entraron) hoy y en el mes, por método.
        $collected = fn ($start, $end) => SalePayment::with('method')->whereBetween('paid_at', [$start, $end])
            ->whereHas('sale', fn ($q) => $q->valid())->get();

        return view('admin.sales.index', [
            'sales' => $sales,
            'filter' => $filter,
            'today' => $collected(now()->startOfDay(), now()->endOfDay()),
            'month' => $collected(now()->startOfMonth(), now()->endOfMonth()),
            'receivable' => round((float) Sale::receivable()->sum('balance'), 2),
        ]);
    }

    /** Cobrar: el paciente, lo pendiente (consultas y planes) y lo suelto del tarifario. */
    public function create()
    {
        PaymentMethod::ensureDefaults();
        $patient = request('patient_id') ? Patient::find(request('patient_id')) : null;
        $pending = $patient ? Sales::pendingFor($patient) : ['consultation' => collect(), 'plan' => collect()];

        // Qué viene marcado: lo de la consulta pedida (o todo lo realizado); los ítems del plan pedido.
        $consultationId = (int) request('consultation_id');
        $planId = (int) request('plan_id');
        $preselected = $pending['consultation']->filter(fn ($cp) => ! $consultationId || $cp->consultation_id === $consultationId)->map(fn ($cp) => "consultation-{$cp->id}")
            ->concat($pending['plan']->filter(fn ($i) => $planId ? $i->treatment_plan_id === $planId : $i->status === 'realizado')->map(fn ($i) => "plan-{$i->id}"))
            ->values()->all();

        // El descuento del plan se propone la primera vez que se cobra ese plan.
        $plan = $planId ? \App\Models\TreatmentPlan::where('patient_id', $patient?->id)->find($planId) : null;
        $suggestedDiscount = $plan && Sales::billedForPlan($plan->id) == 0 ? (float) $plan->discount : 0;

        return view('admin.sales.create', [
            'suggestedDiscount' => $suggestedDiscount,
            'patient' => $patient,
            'pending' => $pending,
            'preselected' => $preselected,
            'debt' => $patient ? round((float) Sale::receivable()->where('patient_id', $patient->id)->sum('balance'), 2) : 0,
            'procedures' => Procedure::with('specialty')->where('active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'methods' => PaymentMethod::where('active', true)->orderBy('sort')->get(),
            'sessions' => Cash::enabled() ? Cash::openSessions() : collect(),
            'cashEnabled' => Cash::enabled(),
            // Quién atendió lo suelto: por defecto, el odontólogo del usuario que cobra (si es uno).
            'professionals' => \App\Models\Personal::professionals()->get(['id', 'full_name']),
            // Productos a la venta (con el módulo de inventario).
            'products' => \App\Support\Stock::enabled()
                ? \App\Models\Product::with('stocks')->where('active', true)->where('for_sale', true)->orderBy('name')->get()
                : collect(),
            'defaultProfessional' => \App\Models\Personal::where('user_id', auth()->id())->where('attends_patients', true)->value('id'),
        ]);
    }

    public function store()
    {
        $sale = Sales::create(request()->all());
        $message = "Venta {$sale->code} registrada" . ((float) $sale->balance > 0 ? ': queda un saldo de ' . \App\Support\Money::format($sale->balance) . '.' : '.');

        return redirect()->route('sales.show', $sale)->with('success', $message);
    }

    public function show(Sale $sale)
    {
        PaymentMethod::ensureDefaults();
        $sale->load(['patient', 'items.consultationProcedure.consultation', 'items.planItem.plan', 'payments.method', 'payments.user', 'user', 'canceller']);

        return view('admin.sales.show', [
            'sale' => $sale,
            'methods' => PaymentMethod::where('active', true)->orderBy('sort')->get(),
            'sessions' => Cash::enabled() ? Cash::openSessions() : collect(),
            'cashEnabled' => Cash::enabled(),
        ]);
    }

    public function payment(Sale $sale)
    {
        Sales::addPayment($sale, request()->all());

        return back()->with('success', 'Abono registrado' . ((float) $sale->fresh()->balance > 0 ? '.' : ': la venta quedó pagada.'));
    }

    public function cancel(Sale $sale)
    {
        Sales::cancel($sale, request()->all());

        return back()->with('success', "Venta {$sale->code} anulada: lo cobrado se devolvió y lo atendido vuelve a estar pendiente de cobro.");
    }

    /** Recibo para imprimir. */
    public function receipt(Sale $sale)
    {
        return view('admin.sales.receipt', ['sale' => $sale->load(['patient', 'items', 'payments.method', 'user'])]);
    }
}
