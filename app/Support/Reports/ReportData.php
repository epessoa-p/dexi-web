<?php

namespace App\Support\Reports;

use App\Models\Appointment;
use App\Models\Commission;
use App\Models\Consultation;
use App\Models\ConsultationDiagnosis;
use App\Models\ConsultationProcedure;
use App\Models\Expense;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\PatientFollowup;
use App\Models\PaymentMethod;
use App\Models\Personal;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Models\TreatmentPlan;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Las cifras de los reportes. Una sola definición para toda la aplicación:
 *
 * - Ingresos: dinero que entró por cobros (ventas no anuladas; anular devuelve todo).
 * - Vendido: el total de las ventas del período, cobrado o no.
 * - Costo: productos vendidos e insumos gastados en procedimientos, al último costo.
 * - Gastos: los registrados en Finanzas (incluye laboratorio y comisiones pagadas).
 * - Utilidad estimada: ingresos − costo − gastos.
 */
class ReportData
{
    /** Paleta validada para daltonismo, en orden fijo. */
    public const SLOTS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300'];

    public const COLOR_INCOME = '#2a78d6';

    public const COLOR_EXPENSE = '#eb6834';

    /** Línea de venta con el descuento general de la venta repartido. */
    private const NET_ITEM = '1.0 * sale_items.total * (case when sales.subtotal > 0 then 1.0 * sales.total / sales.subtotal else 1 end)';

    public function __construct(private readonly ReportPeriod $period) {}

    // ── Definiciones compartidas ───────────────────────────────────────────

    /** @return array<string, float> día => dinero que entró */
    public static function incomeByDay(CarbonInterface $from, CarbonInterface $to, ?int $branchId = null): array
    {
        return SalePayment::query()->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.status', 'completada')
            ->whereBetween('sale_payments.paid_at', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('sales.branch_id', $branchId))
            ->selectRaw('date(sale_payments.paid_at) as day, sum(sale_payments.amount) as amount')
            ->groupBy('day')->pluck('amount', 'day')->map(fn ($v) => round((float) $v, 2))->all();
    }

    public static function income(CarbonInterface $from, CarbonInterface $to, ?int $branchId = null): float
    {
        return round(array_sum(self::incomeByDay($from, $to, $branchId)), 2);
    }

    public static function productCost(CarbonInterface $from, CarbonInterface $to, ?int $branchId = null): float
    {
        return round((float) self::saleItems($from, $to, $branchId)->whereNotNull('sale_items.product_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->sum(\DB::raw('sale_items.quantity * products.purchase_price')), 2);
    }

    /** Insumos gastados en procedimientos (lo descontado menos lo devuelto), al último costo. */
    public static function suppliesCost(CarbonInterface $from, CarbonInterface $to, ?int $branchId = null): float
    {
        return round(-1 * (float) StockMovement::query()->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->where('stock_movements.source_type', (new ConsultationProcedure)->getMorphClass())
            ->whereBetween('stock_movements.created_at', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('stock_movements.branch_id', $branchId))
            ->sum(\DB::raw('stock_movements.quantity * products.purchase_price')), 2);
    }

    public static function expenses(CarbonInterface $from, CarbonInterface $to, ?int $branchId = null): float
    {
        return round((float) self::expenseQuery($from, $to, $branchId)->sum('expenses.amount'), 2);
    }

    /** @return array{income: float, cost: float, products: float, supplies: float, expenses: float, profit: float} */
    public static function totals(CarbonInterface $from, CarbonInterface $to, ?int $branchId = null): array
    {
        $income = self::income($from, $to, $branchId);
        $products = self::productCost($from, $to, $branchId);
        $supplies = max(self::suppliesCost($from, $to, $branchId), 0);
        $expenses = self::expenses($from, $to, $branchId);

        return ['income' => $income, 'products' => $products, 'supplies' => $supplies, 'cost' => round($products + $supplies, 2),
            'expenses' => $expenses, 'profit' => round($income - $products - $supplies - $expenses, 2)];
    }

    private static function saleItems(CarbonInterface $from, CarbonInterface $to, ?int $branchId): Builder
    {
        return SaleItem::query()->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.company_id', app(\App\Support\Tenancy::class)->id())
            ->where('sales.status', 'completada')
            ->whereBetween('sales.created_at', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('sales.branch_id', $branchId));
    }

    private static function expenseQuery(CarbonInterface $from, CarbonInterface $to, ?int $branchId): Builder
    {
        return Expense::query()->valid()
            ->whereDate('expenses.spent_at', '>=', $from->toDateString())->whereDate('expenses.spent_at', '<=', $to->toDateString())
            ->when($branchId, fn ($q) => $q->where('expenses.branch_id', $branchId));
    }

    // ── Finanzas ───────────────────────────────────────────────────────────

    public function finance(): array
    {
        $p = $this->period;
        $totals = self::totals($p->from, $p->to, $p->branchId);
        $previous = $p->previousFrom ? self::totals($p->previousFrom, $p->previousTo, $p->branchId) : null;

        $expensesByDay = self::expenseQuery($p->from, $p->to, $p->branchId)
            ->selectRaw('date(expenses.spent_at) as day, sum(expenses.amount) as amount')
            ->groupBy('day')->pluck('amount', 'day');

        $receivables = Sale::with('patient')->receivable()->when($p->branchId, fn ($q) => $q->where('branch_id', $p->branchId))->get();
        $payables = Purchase::with('supplier')->payable()->when($p->branchId, fn ($q) => $q->where('branch_id', $p->branchId))->get();
        $labUnpaid = LabOrder::with('lab')->unpaid()->get();

        $creditors = $payables->map(fn ($pu) => ['label' => $pu->supplier?->name ?? 'Sin proveedor', 'value' => (float) $pu->balance])
            ->concat($labUnpaid->map(fn ($o) => ['label' => $o->lab?->name ?? 'Laboratorio', 'value' => (float) $o->cost]))
            ->groupBy('label')->map(fn ($rows, $label) => ['label' => $label, 'value' => $rows->sum('value')])->sortByDesc('value')->values();

        return [
            'totals' => $totals,
            'previous' => $previous,
            'deltas' => [
                'income' => self::delta($totals['income'], $previous['income'] ?? null),
                'cost' => self::delta($totals['cost'], $previous['cost'] ?? null, upIsGood: false),
                'expenses' => self::delta($totals['expenses'], $previous['expenses'] ?? null, upIsGood: false),
                'profit' => self::delta($totals['profit'], $previous['profit'] ?? null),
            ],
            'chart' => [
                'labels' => array_values($p->buckets()),
                'format' => 'money',
                'kind' => 'line',
                'series' => [
                    ['name' => 'Ingresos', 'color' => self::COLOR_INCOME, 'values' => $p->series(self::incomeByDay($p->from, $p->to, $p->branchId))],
                    ['name' => 'Gastos', 'color' => self::COLOR_EXPENSE, 'values' => $p->series($expensesByDay)],
                ],
            ],
            'byMethod' => $this->incomeByMethod(),
            'expensesByCategory' => $this->rank(self::expenseQuery($p->from, $p->to, $p->branchId)
                ->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
                ->selectRaw("coalesce(expense_categories.name, 'Sin categoría') as label, sum(expenses.amount) as value")
                ->groupBy('expense_categories.name')->orderByDesc('value')->get()),
            'receivable' => round((float) $receivables->sum('balance'), 2),
            'receivableOverdue' => round((float) $receivables->filter(fn (Sale $s) => $s->isOverdue())->sum('balance'), 2),
            'debtors' => $this->rank($receivables->groupBy('patient_id')
                ->map(fn ($sales) => ['label' => $sales->first()->patient?->full_name ?? 'Sin paciente', 'value' => (float) $sales->sum('balance')])
                ->sortByDesc('value')->values()),
            'payable' => round((float) $creditors->sum('value'), 2),
            'creditors' => $this->rank($creditors),
        ];
    }

    private function incomeByMethod(): Collection
    {
        $p = $this->period;
        $paid = SalePayment::query()->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.status', 'completada')->whereBetween('sale_payments.paid_at', [$p->from, $p->to])
            ->when($p->branchId, fn ($q) => $q->where('sales.branch_id', $p->branchId))
            ->selectRaw('sale_payments.payment_method_id as method, sum(sale_payments.amount) as amount')
            ->groupBy('method')->pluck('amount', 'method');

        // El color sale del catálogo de métodos: cada uno conserva su color aunque cambie el período.
        return $this->parts(PaymentMethod::orderBy('sort')->orderBy('id')->get()
            ->map(fn (PaymentMethod $m) => ['label' => $m->name, 'value' => round((float) ($paid[$m->id] ?? 0), 2)]));
    }

    // ── Ventas ─────────────────────────────────────────────────────────────

    public function sales(): array
    {
        $p = $this->period;
        $sales = fn ($from, $to) => Sale::valid()->whereBetween('sales.created_at', [$from, $to])->when($p->branchId, fn ($q) => $q->where('sales.branch_id', $p->branchId));
        $total = round((float) $sales($p->from, $p->to)->sum('total'), 2);
        $count = $sales($p->from, $p->to)->count();
        $prevTotal = $p->previousFrom ? round((float) $sales($p->previousFrom, $p->previousTo)->sum('total'), 2) : null;
        $prevCount = $p->previousFrom ? $sales($p->previousFrom, $p->previousTo)->count() : null;
        $ticket = $count ? round($total / $count, 2) : 0.0;

        $items = fn () => self::saleItems($p->from, $p->to, $p->branchId);
        $byType = $items()->selectRaw("case
                when sale_items.product_id is not null then 'productos'
                when sale_items.treatment_plan_item_id is not null then 'planes'
                when sale_items.consultation_procedure_id is not null then 'consultas'
                else 'otros' end as kind, sum(" . self::NET_ITEM . ') as value')
            ->groupBy('kind')->pluck('value', 'kind');
        $labels = ['consultas' => 'Atendido en consulta', 'planes' => 'Planes de tratamiento', 'productos' => 'Productos', 'otros' => 'Otros cobros'];

        return [
            'total' => $total,
            'count' => $count,
            'ticket' => $ticket,
            'deltas' => [
                'total' => self::delta($total, $prevTotal),
                'count' => self::delta($count, $prevCount),
                'ticket' => $prevCount ? self::delta($ticket, $prevTotal / $prevCount) : ($prevCount === 0 ? self::delta($ticket, 0) : null),
            ],
            'chart' => [
                'labels' => array_values($p->buckets()),
                'format' => 'money',
                'kind' => 'column',
                'series' => [['name' => 'Vendido', 'color' => self::COLOR_INCOME, 'values' => $p->series(
                    $sales($p->from, $p->to)->selectRaw('date(sales.created_at) as day, sum(sales.total) as amount')->groupBy('day')->pluck('amount', 'day'))]],
            ],
            'byType' => $this->parts(collect($labels)->map(fn ($label, $key) => ['label' => $label, 'value' => round((float) ($byType[$key] ?? 0), 2)])->values()),
            'procedures' => $this->rank($items()->whereNotNull('sale_items.procedure_id')
                ->join('procedures', 'procedures.id', '=', 'sale_items.procedure_id')
                ->selectRaw('procedures.name as label, sum(' . self::NET_ITEM . ') as value, sum(sale_items.quantity) as quantity')
                ->groupBy('procedures.name')->orderByDesc('value')->get()),
            'products' => $this->rank($items()->whereNotNull('sale_items.product_id')
                ->join('products', 'products.id', '=', 'sale_items.product_id')
                ->selectRaw('products.name as label, sum(' . self::NET_ITEM . ') as value, sum(sale_items.quantity) as quantity')
                ->groupBy('products.name')->orderByDesc('value')->get()),
            'specialties' => $this->rank($items()->whereNotNull('sale_items.procedure_id')
                ->join('procedures', 'procedures.id', '=', 'sale_items.procedure_id')
                ->leftJoin('specialties', 'specialties.id', '=', 'procedures.specialty_id')
                ->selectRaw("coalesce(specialties.name, 'Sin especialidad') as label, sum(" . self::NET_ITEM . ') as value')
                ->groupBy('specialties.name')->orderByDesc('value')->get()),
            'users' => $this->rank($sales($p->from, $p->to)->leftJoin('users', 'users.id', '=', 'sales.user_id')
                ->selectRaw("coalesce(users.name, 'Sin usuario') as label, sum(sales.total) as value")
                ->groupBy('users.name')->orderByDesc('value')->get()),
        ];
    }

    // ── Pacientes y citas ──────────────────────────────────────────────────

    public function clinic(): array
    {
        $p = $this->period;
        $consultations = fn ($from, $to) => Consultation::whereBetween('started_at', [$from, $to])->when($p->branchId, fn ($q) => $q->where('branch_id', $p->branchId));
        $count = $consultations($p->from, $p->to)->count();
        $prevCount = $p->previousFrom ? $consultations($p->previousFrom, $p->previousTo)->count() : null;
        $newPatients = Patient::whereBetween('created_at', [$p->from, $p->to])->count();
        $prevNew = $p->previousFrom ? Patient::whereBetween('created_at', [$p->previousFrom, $p->previousTo])->count() : null;

        $appointments = Appointment::whereBetween('starts_at', [$p->from, $p->to])->when($p->branchId, fn ($q) => $q->where('branch_id', $p->branchId));
        $byStatus = (clone $appointments)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $attended = (int) ($byStatus['atendida'] ?? 0);
        $noShow = (int) ($byStatus['no_asistio'] ?? 0);
        $statusColors = ['atendida' => '#1baf7a', 'no_asistio' => '#eb6834', 'cancelada' => '#9aa5ab', 'confirmada' => '#2a78d6', 'agendada' => '#7fb2ea', 'en_espera' => '#eda100', 'en_atencion' => '#e87ba4'];

        $plans = TreatmentPlan::query();
        $presented = (clone $plans)->whereBetween('presented_at', [$p->from, $p->to])->count();
        $approved = (clone $plans)->whereBetween('approved_at', [$p->from, $p->to])->get();
        $approvedValue = round($approved->sum(fn (TreatmentPlan $plan) => $plan->totals()['total']), 2);

        return [
            'count' => $count,
            'patients' => $consultations($p->from, $p->to)->distinct()->count('patient_id'),
            'newPatients' => $newPatients,
            'deltas' => ['count' => self::delta($count, $prevCount), 'newPatients' => self::delta($newPatients, $prevNew)],
            'chart' => [
                'labels' => array_values($p->buckets()),
                'format' => 'count',
                'kind' => 'column',
                'series' => [['name' => 'Consultas', 'color' => self::COLOR_INCOME, 'values' => $p->series(
                    $consultations($p->from, $p->to)->selectRaw('date(started_at) as day, count(*) as n')->groupBy('day')->pluck('n', 'day'))]],
            ],
            'appointments' => [
                'total' => (int) $byStatus->sum(),
                'attended' => $attended,
                'noShow' => $noShow,
                'cancelled' => (int) ($byStatus['cancelada'] ?? 0),
                'attendance' => $attended + $noShow > 0 ? (int) round($attended / ($attended + $noShow) * 100) : null,
                'parts' => $this->countParts(collect(Appointment::STATUSES)
                    ->map(fn ($s, $key) => ['label' => $s[0], 'value' => (int) ($byStatus[$key] ?? 0), 'color' => $statusColors[$key] ?? '#c9ccd1'])
                    ->values()),
            ],
            'plans' => [
                'presented' => $presented,
                'approved' => $approved->count(),
                'acceptance' => $presented > 0 ? (int) round(min($approved->count() / $presented, 1) * 100) : null,
                'approvedValue' => $approvedValue,
            ],
            'diagnoses' => $this->rank(ConsultationDiagnosis::query()->join('consultations', 'consultations.id', '=', 'consultation_diagnoses.consultation_id')
                ->where('consultations.company_id', app(\App\Support\Tenancy::class)->id())
                ->whereBetween('consultations.started_at', [$p->from, $p->to])
                ->selectRaw('consultation_diagnoses.label as label, count(*) as value')
                ->groupBy('consultation_diagnoses.label')->orderByDesc('value')->get()),
            'procedures' => $this->rank(ConsultationProcedure::query()->join('consultations', 'consultations.id', '=', 'consultation_procedures.consultation_id')
                ->where('consultations.company_id', app(\App\Support\Tenancy::class)->id())
                ->whereBetween('consultations.started_at', [$p->from, $p->to])
                ->selectRaw('consultation_procedures.name as label, sum(consultation_procedures.quantity) as value')
                ->groupBy('consultation_procedures.name')->orderByDesc('value')->get()),
            'referrals' => $this->rank(Patient::whereBetween('created_at', [$p->from, $p->to])
                ->selectRaw("coalesce(referral_source, '') as source, count(*) as value")->groupBy('source')->get()
                ->map(fn ($r) => ['label' => Patient::REFERRAL_SOURCES[$r->source] ?? 'No se registró', 'value' => (int) $r->value])
                ->sortByDesc('value')->values()),
        ];
    }

    // ── Profesionales ──────────────────────────────────────────────────────

    public function professionals(): Collection
    {
        $p = $this->period;
        $production = \App\Support\Commissions::production($p->from, $p->to)->keyBy(fn ($r) => $r['professional']->id);
        $appointments = Appointment::whereBetween('starts_at', [$p->from, $p->to])->whereNotNull('personal_id')
            ->selectRaw('personal_id, status, count(*) as n')->groupBy('personal_id', 'status')->get()->groupBy('personal_id');
        $consultations = Consultation::whereBetween('started_at', [$p->from, $p->to])->whereNotNull('personal_id')
            ->selectRaw('personal_id, count(*) as n, count(distinct patient_id) as patients')->groupBy('personal_id')->get()->keyBy('personal_id');

        $ids = $production->keys()->merge($appointments->keys())->merge($consultations->keys())->unique();

        return Personal::withTrashed()->whereIn('id', $ids)->orderBy('full_name')->get()->map(function (Personal $pro) use ($production, $appointments, $consultations) {
            $prod = $production->get($pro->id);
            $appts = $appointments->get($pro->id, collect())->pluck('n', 'status');
            $attended = (int) ($appts['atendida'] ?? 0);
            $noShow = (int) ($appts['no_asistio'] ?? 0);

            return [
                'professional' => $pro,
                'consultations' => (int) ($consultations->get($pro->id)?->n ?? 0),
                'patients' => (int) ($consultations->get($pro->id)?->patients ?? 0),
                'procedures' => $prod['procedures'] ?? 0,
                'done' => $prod['done'] ?? 0.0,
                'sold' => $prod['sold'] ?? 0.0,
                'commissions' => $prod['earned'] ?? 0.0,
                'appointments' => (int) $appts->sum(),
                'attendance' => $attended + $noShow > 0 ? (int) round($attended / ($attended + $noShow) * 100) : null,
            ];
        })->sortByDesc('done')->values();
    }

    // ── Inventario ─────────────────────────────────────────────────────────

    public function inventory(): array
    {
        $p = $this->period;
        $products = Product::with(['stocks', 'category'])->where('active', true)->get();
        $valued = $products->map(fn (Product $pr) => ['label' => $pr->name, 'quantity' => max($pr->stockIn($p->branchId), 0),
            'value' => round(max($pr->stockIn($p->branchId), 0) * (float) $pr->purchase_price, 2), 'category' => $pr->category?->name ?? 'Sin categoría']);

        $movements = StockMovement::whereBetween('created_at', [$p->from, $p->to])->when($p->branchId, fn ($q) => $q->where('branch_id', $p->branchId))
            ->selectRaw('reason, sum(case when quantity > 0 then quantity else 0 end) as qty_in, sum(case when quantity < 0 then -quantity else 0 end) as qty_out')
            ->groupBy('reason')->get()
            ->map(fn ($r) => ['label' => StockMovement::REASONS[$r->reason] ?? $r->reason, 'in' => (float) $r->qty_in, 'out' => (float) $r->qty_out]);

        return [
            'value' => round($valued->sum('value'), 2),
            'count' => $products->count(),
            'byProduct' => $this->rank($valued->sortByDesc('value')->values()),
            'byCategory' => $this->rank($valued->groupBy('category')->map(fn ($rows, $label) => ['label' => $label, 'value' => $rows->sum('value')])->sortByDesc('value')->values()),
            'low' => $products->filter(fn (Product $pr) => $pr->isLowStock($p->branchId))
                ->map(fn ($pr) => ['label' => $pr->name, 'stock' => $pr->stockIn($p->branchId), 'min' => (float) $pr->min_stock, 'unit' => mb_strtolower($pr->unit_label)])->values(),
            'expiring' => ProductBatch::with(['product', 'branch'])->expiring()->when($p->branchId, fn ($q) => $q->where('branch_id', $p->branchId))->orderBy('expires_at')->get(),
            'movements' => $movements,
            'consumed' => $this->rank(StockMovement::query()->join('products', 'products.id', '=', 'stock_movements.product_id')
                ->where('stock_movements.source_type', (new ConsultationProcedure)->getMorphClass())
                ->whereBetween('stock_movements.created_at', [$p->from, $p->to])
                ->selectRaw('products.name as label, -sum(stock_movements.quantity) as quantity, -sum(stock_movements.quantity * products.purchase_price) as value')
                ->groupBy('products.name')->orderByDesc('value')->get()),
        ];
    }

    // ── Para revisar hoy ───────────────────────────────────────────────────

    public function pending(): array
    {
        $company = app(\App\Support\Tenancy::class)->company();

        return [
            'overdueSales' => Sale::receivable()->whereNotNull('due_date')->whereDate('due_date', '<', today())->count(),
            'overduePurchases' => $company?->planAllows('compras') ? Purchase::payable()->whereNotNull('due_date')->whereDate('due_date', '<', today())->count() : 0,
            'lowStock' => $company?->planAllows('inventario') ? Product::where('active', true)->lowStock()->count() : 0,
            'expiring' => $company?->planAllows('inventario') ? ProductBatch::expiring()->count() : 0,
            'labLate' => $company?->planAllows('laboratorio') ? LabOrder::whereIn('status', ['enviado', 'en_proceso'])->whereNotNull('due_at')->whereDate('due_at', '<', today())->count() : 0,
            'followups' => $company?->planAllows('recordatorios') ? PatientFollowup::pending()->whereDate('due_date', '<=', today())->count() : 0,
            'pendingCommissions' => $company?->planAllows('comisiones') ? round((float) Commission::pending()->sum('amount'), 2) : 0,
        ];
    }

    // ── Ayudas ─────────────────────────────────────────────────────────────

    private function parts(Collection $rows): Collection
    {
        if ($rows->count() > count(self::SLOTS)) {
            $keep = count(self::SLOTS) - 1;
            $rows = $rows->take($keep)->push(['label' => 'Otros', 'value' => round($rows->slice($keep)->sum('value'), 2)]);
        }
        $total = $rows->sum(fn ($row) => max($row['value'], 0));

        return $rows->values()
            ->map(fn ($row, $index) => $row + ['color' => self::SLOTS[$index], 'share' => $total > 0 ? max($row['value'], 0) / $total * 100 : 0])
            ->filter(fn ($row) => $row['value'] > 0)->values();
    }

    /** Partes con color propio (estados de cita): el color sigue al concepto. */
    private function countParts(Collection $rows): Collection
    {
        $total = $rows->sum('value');

        return $rows->filter(fn ($r) => $r['value'] > 0)
            ->map(fn ($r) => $r + ['share' => $total > 0 ? $r['value'] / $total * 100 : 0])->values();
    }

    private function rank(iterable $rows): Collection
    {
        $rows = collect($rows)->map(fn ($row) => is_array($row) ? $row : $row->only(['label', 'value', 'quantity']))
            ->map(fn ($row) => ['label' => (string) $row['label'], 'value' => round((float) $row['value'], 2), 'quantity' => isset($row['quantity']) ? (float) $row['quantity'] : null])
            ->filter(fn ($row) => $row['value'] > 0)->values();
        $max = (float) $rows->max('value');
        $total = (float) $rows->sum('value');

        return $rows->map(fn ($row) => $row + [
            'width' => $max > 0 ? $row['value'] / $max * 100 : 0,
            'share' => $total > 0 ? $row['value'] / $total * 100 : 0,
        ]);
    }

    public static function delta(float|int $current, float|int|null $previous, bool $upIsGood = true): ?array
    {
        if ($previous === null) {
            return null;
        }
        if ((float) $previous === 0.0) {
            return ['pct' => null, 'direction' => (float) $current === 0.0 ? 'flat' : 'up', 'tone' => 'neutral'];
        }
        $pct = round(($current - $previous) / abs($previous) * 100) + 0.0;
        $direction = $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat');
        $good = $upIsGood ? $direction === 'up' : $direction === 'down';

        return ['pct' => (float) $pct, 'direction' => $direction, 'tone' => $direction === 'flat' ? 'neutral' : ($good ? 'good' : 'bad')];
    }
}
