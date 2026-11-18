<?php

namespace App\Support;

use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\CommissionSettlement;
use App\Models\Company;
use App\Models\ConsultationProcedure;
use App\Models\ExpenseCategory;
use App\Models\Personal;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Comisiones de los odontólogos (ESTRUCTURA_CUENTAS_Y_GASTOS.md §6):
 *
 * - Cada línea cobrada lleva quién la atendió: el odontólogo de la consulta,
 *   el del plan (o el que lo hizo) o el elegido al cobrar lo suelto.
 * - Se generan con la regla vigente y se guardan con su base, tipo y valor:
 *   nada se recalcula después. Sin regla no hay comisión. Gana la más
 *   específica (profesional + procedimiento › … › equipo + todo).
 * - Base = total de la línea con el descuento general de la venta prorrateado.
 * - Momento (configurable): «sobre lo cobrado» (cada pago genera su parte; lo
 *   que queda a crédito no comisiona hasta que se paga) o «al vender» (todo de una).
 * - Anular: lo pendiente se borra; lo ya pagado genera un ajuste NEGATIVO que
 *   se descuenta solo en la próxima liquidación.
 * - Liquidar = pagar como un gasto de Finanzas (categoría COMISIONES) desde
 *   una cuenta o la caja.
 */
final class Commissions
{
    public const BASIS = [
        'cobrado' => 'Sobre lo cobrado: cada pago del paciente genera su parte',
        'vendido' => 'Al vender: todo de una vez, aunque quede saldo',
    ];

    public static function enabled(?Company $company = null): bool
    {
        return (bool) ($company ?? app(Tenancy::class)->company())?->planAllows('comisiones');
    }

    public static function basis(): string
    {
        $basis = settings()->get('clinic.commission_basis', 'cobrado', app(Tenancy::class)->id());

        return array_key_exists($basis, self::BASIS) ? $basis : 'cobrado';
    }

    /** ¿Se descuenta el costo de laboratorio de la base? (por defecto sí, con el módulo de laboratorio) */
    public static function minusLab(): bool
    {
        return (bool) app(Tenancy::class)->company()?->planAllows('laboratorio')
            && (bool) settings()->get('clinic.commission_minus_lab', true, app(Tenancy::class)->id());
    }

    // ── Generar ────────────────────────────────────────────────────────────

    /** Al crear la venta (modo «al vender»). Dentro de su transacción. */
    public static function onSale(Sale $sale): void
    {
        if (self::basis() === 'vendido') {
            self::accrue($sale, 1.0, null);
        }
    }

    /** Al registrar un pago (modo «sobre lo cobrado»): la parte proporcional. */
    public static function onPayment(Sale $sale, SalePayment $payment): void
    {
        if (self::basis() === 'cobrado' && (float) $sale->total > 0) {
            self::accrue($sale, min(1.0, (float) $payment->amount / (float) $sale->total), $payment);
        }
    }

    private static function accrue(Sale $sale, float $ratio, ?SalePayment $payment): void
    {
        if (! self::enabled() || $ratio <= 0) {
            return;
        }
        $rules = CommissionRule::with('procedure')->where('active', true)->get();
        if ($rules->isEmpty()) {
            return;
        }
        // El descuento general de la venta se reparte entre sus líneas.
        $factor = (float) $sale->subtotal > 0 ? (float) $sale->total / (float) $sale->subtotal : 0;

        foreach ($sale->items()->with('procedure')->whereNotNull('personal_id')->get() as $item) {
            $rule = self::ruleFor($item, $rules);
            if (! $rule) {
                continue;
            }
            $base = round((float) $item->total * $factor * $ratio, 2);
            // El costo del laboratorio (corona, prótesis…) se descuenta antes de calcular el porcentaje.
            $lab = self::minusLab() ? round(LabOrders::costForSaleItem($item) * $ratio, 2) : 0.0;
            $base = max(round($base - $lab, 2), 0);
            $amount = $rule->type === 'porcentaje'
                ? round($base * (float) $rule->value / 100, 2)
                : round((float) $rule->value * (float) $item->quantity * $ratio, 2);
            if ($amount <= 0) {
                continue;
            }

            Commission::create([
                'company_id' => $sale->company_id,
                'sale_id' => $sale->id,
                'sale_item_id' => $item->id,
                'sale_payment_id' => $payment?->id,
                'personal_id' => $item->personal_id,
                'commission_rule_id' => $rule->id,
                'kind' => 'devengada',
                'description' => $item->description . ($ratio < 1 ? ' (' . round($ratio * 100) . ' % cobrado)' : ''),
                'base' => $base,
                'type' => $rule->type,
                'value' => $rule->value,
                'amount' => $amount,
                'earned_at' => $payment?->paid_at ?? $sale->created_at ?? now(),
            ]);
        }
    }

    /** La regla ganadora para una línea, o null si ninguna encaja. */
    public static function ruleFor(SaleItem $item, Collection $rules): ?CommissionRule
    {
        return $rules->filter(fn (CommissionRule $rule) => self::matches($rule, $item))
            ->sortBy([fn ($a, $b) => $a->priority <=> $b->priority, fn ($a, $b) => $b->id <=> $a->id])
            ->first();
    }

    public static function matches(CommissionRule $rule, SaleItem $item): bool
    {
        if ($rule->personal_id && $rule->personal_id !== $item->personal_id) {
            return false;
        }
        // Las reglas son por procedimientos: la venta de productos (cepillos, enjuagues…) no comisiona.
        if ($item->product_id) {
            return false;
        }

        return match ($rule->applies_to) {
            'procedimiento' => $item->procedure_id && $item->procedure_id === $rule->procedure_id,
            'especialidad' => $item->procedure && $item->procedure->specialty_id && $item->procedure->specialty_id === $rule->specialty_id,
            default => true,
        };
    }

    /**
     * Quién atendió una línea: el de la consulta; en un plan, quien lo hizo
     * (si ya se hizo) o quien lo propuso; en lo suelto, el elegido al cobrar.
     */
    public static function professionalForLine(array $line, ?int $fallback): ?int
    {
        if (! empty($line['consultation_procedure_id'])) {
            $id = ConsultationProcedure::with('consultation')->find($line['consultation_procedure_id'])?->consultation?->personal_id;
        } elseif (! empty($line['treatment_plan_item_id'])) {
            $planItem = \App\Models\TreatmentPlanItem::with(['plan', 'consultationProcedure.consultation'])->find($line['treatment_plan_item_id']);
            $id = $planItem?->consultationProcedure?->consultation?->personal_id ?? $planItem?->plan?->personal_id;
        } else {
            $id = $line['personal_id'] ?? null;
        }

        return $id ? (int) $id : $fallback;
    }

    /** Al anular una venta: borra lo pendiente y ajusta en negativo lo ya pagado. */
    public static function onCancel(Sale $sale): int
    {
        Commission::where('sale_id', $sale->id)->pending()->delete();

        $adjusted = 0;
        foreach (Commission::where('sale_id', $sale->id)->where('kind', 'devengada')->get() as $commission) {
            $net = round((float) $commission->amount + (float) Commission::where('adjusts_commission_id', $commission->id)->sum('amount'), 2);
            if ($net > 0) {
                Commission::create([
                    'company_id' => $commission->company_id,
                    'sale_id' => $commission->sale_id,
                    'sale_item_id' => $commission->sale_item_id,
                    'personal_id' => $commission->personal_id,
                    'commission_rule_id' => $commission->commission_rule_id,
                    'kind' => 'ajuste',
                    'adjusts_commission_id' => $commission->id,
                    'description' => "Anulación {$sale->code}: {$commission->description}",
                    'base' => $commission->base,
                    'type' => $commission->type,
                    'value' => $commission->value,
                    'amount' => -$net,
                    'earned_at' => now(),
                ]);
                $adjusted++;
            }
        }

        return $adjusted;
    }

    // ── Consultas ──────────────────────────────────────────────────────────

    /**
     * Lo que se debe, de CUALQUIER fecha (no depende del filtro del período).
     *
     * @return Collection<int, array{professional: Personal, count: int, total: float, since: ?string}>
     */
    public static function pending(): Collection
    {
        return Commission::with('professional')->pending()->get()
            ->groupBy('personal_id')
            ->map(fn (Collection $rows) => [
                'professional' => $rows->first()->professional,
                'count' => $rows->where('kind', 'devengada')->count(),
                'total' => round((float) $rows->sum('amount'), 2),
                'since' => $rows->min('earned_at'),
            ])
            ->filter(fn ($row) => $row['total'] != 0.0)
            ->sortByDesc('total')->values();
    }

    /**
     * Producción por profesional en el período: lo atendido (valor de lo hecho
     * en consultas), lo vendido (sus líneas cobradas), lo comisionado y lo pendiente.
     *
     * @return Collection<int, array{professional: Personal, done: float, procedures: int, sold: float, earned: float, settled: float, pending: float}>
     */
    public static function production(\Carbon\CarbonInterface $from, \Carbon\CarbonInterface $to): Collection
    {
        $range = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];

        $done = ConsultationProcedure::query()
            ->join('consultations', 'consultations.id', '=', 'consultation_procedures.consultation_id')
            ->where('consultations.company_id', app(Tenancy::class)->id())
            ->whereBetween('consultations.started_at', $range)
            ->whereNotNull('consultations.personal_id')
            ->groupBy('consultations.personal_id')
            ->selectRaw('consultations.personal_id as pid, count(*) as n, sum(consultation_procedures.price * consultation_procedures.quantity) as amount')
            ->get()->keyBy('pid');

        $sold = SaleItem::with('sale')->whereNotNull('personal_id')
            ->whereHas('sale', fn ($q) => $q->valid()->whereBetween('created_at', $range))
            ->get()->groupBy('personal_id')
            ->map(fn ($items) => round($items->sum(fn (SaleItem $i) => (float) $i->sale->subtotal > 0
                ? (float) $i->total * (float) $i->sale->total / (float) $i->sale->subtotal : 0), 2));

        $commissions = Commission::whereBetween('earned_at', $range)->get()->groupBy('personal_id');

        $ids = collect($done->keys())->merge($sold->keys())->merge($commissions->keys())->unique()->filter();

        return Personal::withTrashed()->whereIn('id', $ids)->orderBy('full_name')->get()
            ->map(function (Personal $p) use ($done, $sold, $commissions) {
                $rows = $commissions->get($p->id, collect());

                return [
                    'professional' => $p,
                    'procedures' => (int) ($done->get($p->id)?->n ?? 0),
                    'done' => round((float) ($done->get($p->id)?->amount ?? 0), 2),
                    'sold' => (float) $sold->get($p->id, 0),
                    'earned' => round((float) $rows->sum('amount'), 2),
                    'settled' => round((float) $rows->whereNotNull('commission_settlement_id')->sum('amount'), 2),
                    'pending' => round((float) $rows->whereNull('commission_settlement_id')->sum('amount'), 2),
                ];
            })
            ->sortByDesc('done')->values();
    }

    // ── Liquidar ───────────────────────────────────────────────────────────

    /**
     * Paga las comisiones pendientes de un profesional en el período.
     *
     * @param  array{source: string, account: ?\App\Models\FinanceAccount, session: ?\App\Models\CashSession}  $from
     */
    public static function settle(Personal $professional, string $start, string $end, ?string $notes, array $from): CommissionSettlement
    {
        $start = Carbon::parse($start)->startOfDay();
        $end = Carbon::parse($end)->endOfDay();
        if ($start->gt($end)) {
            throw ValidationException::withMessages(['period_start' => 'El inicio del período no puede ser posterior al fin.']);
        }

        return DB::transaction(function () use ($professional, $start, $end, $notes, $from) {
            $commissions = Commission::where('personal_id', $professional->id)->pending()
                ->whereBetween('earned_at', [$start, $end])->lockForUpdate()->get();
            $total = round((float) $commissions->sum('amount'), 2);

            if ($commissions->isEmpty()) {
                throw ValidationException::withMessages(['period_start' => 'No hay nada pendiente de pagar en ese período.']);
            }
            if ($total <= 0) {
                throw ValidationException::withMessages(['period_start' => 'En ese período los ajustes superan a lo ganado ('
                    . Money::format($total) . '): se descontará en la próxima liquidación.']);
            }

            $companyId = app(Tenancy::class)->id();
            $settlement = CommissionSettlement::create([
                'personal_id' => $professional->id,
                'number' => (int) CommissionSettlement::forCompany($companyId)->lockForUpdate()->max('number') + 1,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'commissions_count' => $commissions->where('kind', 'devengada')->count(),
                'total' => $total,
                'status' => 'pagada',
                'paid_at' => now(),
                'paid_by' => auth()->id(),
                'notes' => $notes,
            ]);

            $expense = Expenses::record([
                'expense_category_id' => self::expenseCategory()->id,
                'personal_id' => $professional->id,
                'commission_settlement_id' => $settlement->id,
                'concept' => "Liquidación {$settlement->code} · {$professional->full_name}",
                'amount' => $total,
                'spent_at' => now()->toDateString(),
                'branch_id' => $from['session']?->caja?->branch_id ?? $from['account']?->branch_id,
                'notes' => $notes,
            ], $from);

            $settlement->update(['expense_id' => $expense->id]);
            Commission::whereIn('id', $commissions->pluck('id'))->update(['commission_settlement_id' => $settlement->id]);

            return $settlement;
        });
    }

    /** Anula una liquidación: el dinero vuelve (se anula su gasto) y las comisiones quedan pendientes otra vez. */
    public static function cancelSettlement(CommissionSettlement $settlement, string $reason): void
    {
        DB::transaction(function () use ($settlement, $reason) {
            $settlement = CommissionSettlement::whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if ($settlement->isCancelled()) {
                throw ValidationException::withMessages(['cancel_reason' => 'Esa liquidación ya está anulada.']);
            }
            if ($settlement->expense && ! $settlement->expense->isCancelled()) {
                Expenses::cancel($settlement->expense, "Anulación de la liquidación {$settlement->code}: {$reason}", fromSettlement: true);
            }
            Commission::where('commission_settlement_id', $settlement->id)->update(['commission_settlement_id' => null]);
            $settlement->update(['status' => 'anulada', 'cancelled_at' => now(), 'cancelled_by' => auth()->id(), 'cancel_reason' => $reason]);
        });
    }

    /** La categoría de sistema COMISIONES (pago a personal); se crea o reactiva al liquidar. */
    public static function expenseCategory(): ExpenseCategory
    {
        $category = ExpenseCategory::firstOrNew(['name' => ExpenseCategory::COMMISSIONS]);
        $category->fill(ExpenseCategory::attributesFor('personal') + ['active' => true])->save();

        return $category;
    }
}
