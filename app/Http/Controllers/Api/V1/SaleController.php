<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Admin\SaleController as WebSaleController;
use App\Http\Controllers\Controller;
use App\Models\ConsultationProcedure;
use App\Models\Patient;
use App\Models\PaymentMethod;
use App\Models\Personal;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\TreatmentPlanItem;
use App\Support\Cash;
use App\Support\Communications;
use App\Support\Money;
use App\Support\Sales;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cobros en la app: lo pendiente del paciente, cobrar, cuentas por cobrar,
 * abonos con recibo por WhatsApp, ventas y anulación. Todo pasa por
 * App\Support\Sales (las mismas reglas que la web: caja abierta, vuelto solo
 * del efectivo, referencia obligatoria, cobrar una sola vez).
 */
class SaleController extends Controller
{
    /** Métodos de pago, cajas abiertas y profesionales para lo suelto. */
    public function options(): JsonResponse
    {
        PaymentMethod::ensureDefaults();

        return response()->json([
            'currency' => Money::symbol(),
            'payment_methods' => PaymentMethod::where('active', true)->orderBy('sort')->get()->map(fn (PaymentMethod $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'is_cash' => $m->isCash(),
                'requires_reference' => (bool) $m->requires_reference,
            ])->values(),
            'cash' => [
                'enabled' => Cash::enabled(),
                'sessions' => Cash::enabled() ? Cash::openSessions()->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->caja?->name,
                    'opened_at' => $s->opened_at?->format('Y-m-d\TH:i:s'),
                ])->values() : [],
            ],
            'professionals' => Personal::professionals()->get(['id', 'full_name'])->map(fn ($p) => ['id' => $p->id, 'name' => $p->full_name])->values(),
            'default_professional' => Personal::where('user_id', auth()->id())->where('attends_patients', true)->value('id'),
        ]);
    }

    /** Lo que el paciente tiene por cobrar: lo realizado en consultas y los ítems de sus planes aprobados. */
    public function pending(Patient $patient): JsonResponse
    {
        $pending = Sales::pendingFor($patient);

        return response()->json([
            'patient' => PatientController::summary($patient),
            'debt' => round((float) Sale::receivable()->where('patient_id', $patient->id)->sum('balance'), 2),
            'pending_total' => Sales::pendingTotal($patient),
            'data' => $pending['consultation']->map(fn (ConsultationProcedure $cp) => [
                'source' => 'consultation',
                'id' => $cp->id,
                'name' => $cp->name,
                'tooth' => $cp->tooth,
                'surfaces' => $cp->surfaces,
                'quantity' => $cp->quantity,
                'subtotal' => $cp->subtotal,
                'origin' => $cp->consultation?->code,
                'date' => $cp->consultation?->started_at?->toDateString(),
                'done' => true,
            ])->concat($pending['plan']->map(fn (TreatmentPlanItem $i) => [
                'source' => 'plan',
                'id' => $i->id,
                'name' => $i->name,
                'tooth' => $i->tooth,
                'surfaces' => $i->surfaces,
                'quantity' => $i->quantity,
                'subtotal' => $i->subtotal,
                'origin' => $i->plan?->code,
                'date' => $i->done_at?->toDateString(),
                // Lo del plan aún no hecho se puede cobrar por adelantado, pero no viene marcado.
                'done' => $i->status === 'realizado',
            ]))->values(),
        ]);
    }

    /** Cuentas por cobrar: quién debe, cuánto, desde cuándo y el recordatorio listo para WhatsApp. */
    public function receivables(Request $request): JsonResponse
    {
        $rows = Sale::receivable()->with('patient')
            ->when($request->query('q'), fn ($q, $term) => $q->whereHas('patient', fn ($p) => $p->search($term)))
            ->get()
            ->groupBy('patient_id')
            ->map(function ($sales) {
                $patient = $sales->first()->patient;
                $balance = round((float) $sales->sum('balance'), 2);
                $message = $patient ? Communications::render('saldo', $patient, ['{saldo}' => Money::format($balance)]) : null;

                return [
                    'patient' => $patient ? PatientController::summary($patient) : null,
                    'balance' => $balance,
                    'count' => $sales->count(),
                    'oldest' => $sales->min('created_at')?->toDateString(),
                    'overdue' => $sales->contains(fn (Sale $s) => $s->isOverdue()),
                    'reminder' => $message ? ['message' => $message, 'whatsapp_url' => Communications::link($patient, $message)] : null,
                ];
            })
            // Lo vencido primero; después, quien más debe.
            ->sortBy([['overdue', 'desc'], ['balance', 'desc']])
            ->values();

        return response()->json([
            'total' => round($rows->sum('balance'), 2),
            'overdue' => $rows->where('overdue', true)->count(),
            'data' => $rows,
        ]);
    }

    /** Abono a la cuenta del paciente: se aplica a sus ventas con saldo, de la más antigua a la más nueva. */
    public function payPatient(Request $request, Patient $patient): JsonResponse
    {
        $applied = Sales::payPatient($patient, $request->all());
        $amount = round(array_sum($applied), 2);
        $debt = round((float) Sale::receivable()->where('patient_id', $patient->id)->sum('balance'), 2);
        $method = PaymentMethod::find($request->input('payment_method_id'));

        $lines = ["Recibo de {$this->clinic()}", "Paciente: {$patient->full_name}", 'Abono: ' . Money::format($amount) . ($method ? " ({$method->name})" : '')];
        foreach ($applied as $code => $part) {
            $lines[] = "· {$code}: " . Money::format($part);
        }
        $lines[] = $debt > 0 ? 'Saldo pendiente: ' . Money::format($debt) : 'Tu cuenta quedó al día. ¡Gracias!';
        $text = implode("\n", $lines);

        return response()->json([
            'amount' => $amount,
            'applied' => collect($applied)->map(fn ($part, $code) => ['sale' => $code, 'amount' => $part])->values(),
            'debt' => $debt,
            'receipt' => ['message' => $text, 'whatsapp_url' => Communications::link($patient, $text)],
        ], 201);
    }

    /** Ventas: hoy, el mes, con saldo, anuladas o todas; de un paciente con ?patient_id=. */
    public function index(Request $request): JsonResponse
    {
        $filter = array_key_exists($request->query('filter'), WebSaleController::FILTERS) ? $request->query('filter') : 'hoy';
        $patientId = $request->integer('patient_id') ?: null;
        if ($patientId) {
            $filter = array_key_exists($request->query('filter'), WebSaleController::FILTERS) ? $filter : 'todas';
        }
        [$from, $to] = match ($filter) {
            'hoy' => [now()->startOfDay(), now()->endOfDay()],
            'mes' => [now()->startOfMonth(), now()->endOfMonth()],
            default => [null, null],
        };

        $sales = Sale::with('patient')
            ->when($patientId, fn ($q) => $q->where('patient_id', $patientId))
            ->when($from, fn ($q) => $q->whereBetween('created_at', [$from, $to]))
            ->when($filter === 'saldo', fn ($q) => $q->receivable())
            ->when($filter === 'anuladas', fn ($q) => $q->where('status', 'anulada'))
            ->when($request->query('q'), fn ($q, $term) => $q->whereHas('patient', fn ($p) => $p->search($term)))
            ->latest('id')
            ->limit(100)
            ->get();

        // Lo que entró hoy, por método (abonos incluidos).
        $today = SalePayment::with('method')->whereBetween('paid_at', [now()->startOfDay(), now()->endOfDay()])
            ->whereHas('sale', fn ($q) => $q->valid()->when($patientId, fn ($s) => $s->where('patient_id', $patientId)))
            ->get();

        return response()->json([
            'filter' => $filter,
            'filters' => WebSaleController::FILTERS,
            'collected_today' => round((float) $today->sum('amount'), 2),
            'by_method' => $today->groupBy(fn ($p) => $p->method?->name ?? 'Otro')->map(fn ($g, $name) => ['name' => $name, 'amount' => round((float) $g->sum('amount'), 2)])->values(),
            'debt' => $patientId ? round((float) Sale::receivable()->where('patient_id', $patientId)->sum('balance'), 2) : null,
            'data' => $sales->map(fn (Sale $s) => self::summary($s))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $sale = Sales::create($request->all());

        return response()->json($this->detail($sale), 201);
    }

    public function show(Sale $sale): JsonResponse
    {
        return response()->json($this->detail($sale));
    }

    public function payment(Request $request, Sale $sale): JsonResponse
    {
        $sale = Sales::addPayment($sale, $request->all());

        return response()->json($this->detail($sale), 201);
    }

    public function cancel(Request $request, Sale $sale): JsonResponse
    {
        $sale = Sales::cancel($sale, $request->all());

        return response()->json($this->detail($sale));
    }

    /** @return array<string, mixed> */
    public static function summary(Sale $s): array
    {
        return [
            'id' => $s->id,
            'code' => $s->code,
            'created_at' => $s->created_at?->format('Y-m-d\TH:i:s'),
            'status' => $s->status,
            'cancelled' => $s->isCancelled(),
            'patient' => $s->patient ? ['id' => $s->patient->id, 'full_name' => $s->patient->full_name] : null,
            'total' => (float) $s->total,
            'paid' => (float) $s->paid,
            'balance' => (float) $s->balance,
            'due_date' => $s->due_date?->toDateString(),
            'overdue' => $s->isOverdue(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Sale $sale): array
    {
        $sale->load(['patient', 'items', 'payments.method', 'payments.user', 'user', 'canceller']);

        return self::summary($sale) + [
            'subtotal' => (float) $sale->subtotal,
            'discount' => (float) $sale->discount,
            'notes' => $sale->notes,
            'user' => $sale->user?->name,
            'cancel_reason' => $sale->cancel_reason,
            'cancelled_at' => $sale->cancelled_at?->format('Y-m-d\TH:i:s'),
            'cancelled_by' => $sale->canceller?->name,
            'items' => $sale->items->map(fn (SaleItem $i) => [
                'id' => $i->id,
                'description' => $i->description,
                'origin' => $i->origin,
                'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'discount' => (float) $i->discount,
                'total' => (float) $i->total,
            ])->values(),
            'payments' => $sale->payments->map(fn (SalePayment $p) => [
                'id' => $p->id,
                'kind' => $p->kind,
                'method' => $p->method?->name,
                'amount' => (float) $p->amount,
                'reference' => $p->reference,
                'paid_at' => $p->paid_at ? \Carbon\Carbon::parse($p->paid_at)->format('Y-m-d\TH:i:s') : null,
                'user' => $p->user?->name,
            ])->values(),
            'receipt' => $this->receipt($sale),
        ];
    }

    /** Recibo en texto para mandar por WhatsApp. @return array{message: string, whatsapp_url: ?string}|null */
    private function receipt(Sale $sale): ?array
    {
        if (! $sale->patient || $sale->isCancelled()) {
            return null;
        }
        $lines = ["Recibo {$sale->code} · {$this->clinic()}", 'Fecha: ' . $sale->created_at->format('d/m/Y H:i'), "Paciente: {$sale->patient->full_name}", ''];
        foreach ($sale->items as $item) {
            $lines[] = '· ' . $item->description . ((float) $item->quantity != 1 ? ' × ' . rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.') : '') . ': ' . Money::format($item->total);
        }
        $lines[] = '';
        if ((float) $sale->discount > 0) {
            $lines[] = 'Descuento: −' . Money::format($sale->discount);
        }
        $lines[] = 'Total: ' . Money::format($sale->total);
        $lines[] = 'Pagado: ' . Money::format($sale->paid);
        if ((float) $sale->balance > 0) {
            $lines[] = 'Saldo: ' . Money::format($sale->balance) . ($sale->due_date ? ' (hasta el ' . $sale->due_date->format('d/m/Y') . ')' : '');
        }
        $lines[] = '¡Gracias por tu visita!';
        $text = implode("\n", $lines);

        return ['message' => $text, 'whatsapp_url' => Communications::link($sale->patient, $text)];
    }

    private function clinic(): string
    {
        return app(Tenancy::class)->company()?->displayName() ?? '';
    }
}
