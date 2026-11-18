<?php

namespace App\Support;

use App\Models\CashSession;
use App\Models\Company;
use App\Models\ConsultationProcedure;
use App\Models\Patient;
use App\Models\PaymentMethod;
use App\Models\Procedure;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\TreatmentPlanItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ventas y cobros, en un solo lugar (web y API). Cada operación deja
 * coherentes, en una transacción, la venta, lo cobrado (consultas y planes)
 * y la caja.
 *
 * - Lo realizado en consultas y los ítems de planes aprobados se cobran UNA vez.
 * - Un ítem de plan cobrado por adelantado deja «ya cobrado» al procedimiento
 *   de la consulta en que se haga (y al revés).
 * - Lo no pagado queda como saldo (cuenta por cobrar) y exige paciente.
 * - El vuelto solo sale del efectivo. Con el módulo de caja, todo cobro entra
 *   a un turno abierto (solo el efectivo cuenta para el arqueo).
 */
final class Sales
{
    /**
     * Lo pendiente de cobro de un paciente.
     *
     * @return array{consultation: Collection<int, ConsultationProcedure>, plan: Collection<int, TreatmentPlanItem>}
     */
    public static function pendingFor(Patient $patient): array
    {
        $consultation = ConsultationProcedure::with(['consultation', 'procedure'])
            ->whereHas('consultation', fn ($q) => $q->where('patient_id', $patient->id))
            ->whereNull('sale_item_id')
            ->orderBy('id')
            ->get()
            // Si el ítem del plan ya se cobró por adelantado, esto no se vuelve a cobrar.
            ->reject(fn (ConsultationProcedure $cp) => TreatmentPlanItem::where('consultation_procedure_id', $cp->id)->whereNotNull('sale_item_id')->exists());

        $plan = TreatmentPlanItem::with(['plan', 'procedure'])
            ->whereHas('plan', fn ($q) => $q->where('patient_id', $patient->id)->whereIn('status', ['aprobado', 'terminado']))
            ->where('status', '!=', 'cancelado')
            ->whereNull('sale_item_id')
            // Lo ya hecho en una consulta aparece como «de la consulta» (una sola vez).
            ->where(fn ($q) => $q->whereNull('consultation_procedure_id')->orWhereNotIn('consultation_procedure_id', $consultation->pluck('id')))
            ->orderBy('treatment_plan_id')->orderBy('phase')->orderBy('id')
            ->get();

        return ['consultation' => $consultation->values(), 'plan' => $plan];
    }

    /** Total de lo pendiente de cobro (realizado; lo del plan aún no hecho no cuenta). */
    public static function pendingTotal(Patient $patient): float
    {
        $pending = self::pendingFor($patient);

        return round((float) $pending['consultation']->sum(fn ($cp) => $cp->subtotal)
            + (float) $pending['plan']->where('status', 'realizado')->sum(fn ($i) => $i->subtotal), 2);
    }

    /**
     * Registra un cobro.
     *
     * $input: patient_id?, cash_session_id?, discount?, due_date?, notes?,
     *         items[]: {source: consultation|plan|free, id?, procedure_id?, description?, quantity?, unit_price?, discount?},
     *         payments[]: {payment_method_id, amount, reference?}
     */
    public static function create(array $input): Sale
    {
        $companyId = app(Tenancy::class)->id();
        $data = validator($input, [
            'patient_id' => ['nullable', Rule::exists('patients', 'id')->where('company_id', $companyId)],
            'cash_session_id' => ['nullable', 'integer'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.source' => ['required', Rule::in(['consultation', 'plan', 'free', 'product'])],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.procedure_id' => ['nullable', 'integer'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0.01', 'max:999'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'items.*.personal_id' => ['nullable', 'integer', Rule::exists('personal', 'id')->where('company_id', $companyId)],
            // Quién atendió lo suelto (para las comisiones).
            'personal_id' => ['nullable', 'integer', Rule::exists('personal', 'id')->where('company_id', $companyId)],
            'payments' => ['nullable', 'array'],
            'payments.*.payment_method_id' => ['required', Rule::exists('payment_methods', 'id')->where('company_id', $companyId)->where('active', true)],
            'payments.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'payments.*.reference' => ['nullable', 'string', 'max:80'],
        ], [
            'items.required' => 'Agrega al menos un ítem para cobrar.',
            'due_date.after_or_equal' => 'La fecha de pago del saldo no puede ser pasada.',
        ], ['patient_id' => 'paciente', 'discount' => 'descuento', 'due_date' => 'fecha de pago'])->validate();

        $patient = ! empty($data['patient_id']) ? Patient::find($data['patient_id']) : null;
        $lines = collect($data['items'])->map(fn (array $item) => self::resolveLine($item, $patient));

        $subtotal = round($lines->sum('total'), 2);
        $discount = min(round((float) ($data['discount'] ?? 0), 2), $subtotal);
        $total = round($subtotal - $discount, 2);

        $payments = self::payments($data['payments'] ?? []);
        [$payments, $paid] = self::settle($payments, $total);
        $balance = round($total - $paid, 2);

        if ($balance > 0 && ! $patient) {
            throw ValidationException::withMessages(['payments' => 'Para dejar saldo pendiente elige al paciente.']);
        }
        $session = self::sessionFor($data['cash_session_id'] ?? null, $paid > 0);

        return DB::transaction(function () use ($data, $patient, $lines, $subtotal, $discount, $total, $paid, $balance, $payments, $session) {
            $companyId = app(Tenancy::class)->id();
            Company::whereKey($companyId)->lockForUpdate()->first();

            $sale = Sale::create([
                'branch_id' => $session?->caja?->branch_id ?? $patient?->branch_id,
                'patient_id' => $patient?->id,
                'cash_session_id' => $session?->id,
                'number' => (int) Sale::max('number') + 1,
                'status' => 'completada',
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => $total,
                'paid' => $paid,
                'balance' => $balance,
                'due_date' => $balance > 0 ? ($data['due_date'] ?? null) : null,
                'notes' => $data['notes'] ?? null,
                'user_id' => auth()->id(),
                'personal_id' => $data['personal_id'] ?? null,
            ]);

            foreach ($lines as $line) {
                $line['personal_id'] = Commissions::professionalForLine($line, $sale->personal_id);
                $item = $sale->items()->create(collect($line)->only([
                    'procedure_id', 'product_id', 'consultation_procedure_id', 'treatment_plan_item_id', 'personal_id', 'description', 'tooth', 'quantity', 'unit_price', 'discount', 'total',
                ])->all());
                self::markBilled($item);
                // Un producto vendido sale del stock del consultorio (si no alcanza, no se vende).
                if ($item->product_id) {
                    Stock::consume($line['product'], Stock::defaultBranch($sale->branch_id), (float) $item->quantity, 'sale',
                        ['source' => $sale, 'notes' => "Venta {$sale->code}" . ($patient ? " · {$patient->full_name}" : '')]);
                }
            }
            Commissions::onSale($sale);

            foreach ($payments as $payment) {
                self::registerPayment($sale, $payment['method'], $payment['amount'], $payment['reference'] ?? null, $session, 'venta');
            }

            return $sale;
        });
    }

    /** Abono a una venta con saldo. */
    public static function addPayment(Sale $sale, array $input): Sale
    {
        $data = self::validatePayment($input);
        $amount = round((float) $data['amount'], 2);

        return DB::transaction(function () use ($sale, $data, $amount) {
            $sale = Sale::lockForUpdate()->findOrFail($sale->id);
            if ($sale->isCancelled()) {
                throw ValidationException::withMessages(['amount' => 'La venta está anulada.']);
            }
            if ($amount <= 0 || $amount > (float) $sale->balance) {
                throw ValidationException::withMessages(['amount' => 'El abono debe ser mayor a cero y no superar el saldo de ' . Money::format($sale->balance) . '.']);
            }
            $method = PaymentMethod::findOrFail($data['payment_method_id']);
            self::checkReference($method, $data['reference'] ?? null);
            $session = self::sessionFor($data['cash_session_id'] ?? null, true);

            self::registerPayment($sale, $method, $amount, $data['reference'] ?? null, $session, 'abono');
            $sale->update(['paid' => round((float) $sale->paid + $amount, 2), 'balance' => round((float) $sale->balance - $amount, 2)]);

            return $sale;
        });
    }

    /**
     * Abono del paciente a su cuenta: se aplica a las ventas con saldo, de la
     * más antigua a la más nueva. Devuelve lo aplicado por venta.
     *
     * @return array<string, float> código de venta => monto
     */
    public static function payPatient(Patient $patient, array $input): array
    {
        $data = self::validatePayment($input);
        $amount = round((float) $data['amount'], 2);
        $debt = round((float) Sale::receivable()->where('patient_id', $patient->id)->sum('balance'), 2);
        if ($amount <= 0 || $amount > $debt) {
            throw ValidationException::withMessages(['amount' => 'El abono debe ser mayor a cero y no superar la deuda de ' . Money::format($debt) . '.']);
        }

        return DB::transaction(function () use ($patient, $data, $amount) {
            $left = $amount;
            $applied = [];
            foreach (Sale::receivable()->where('patient_id', $patient->id)->orderBy('created_at')->orderBy('id')->lockForUpdate()->get() as $sale) {
                if ($left <= 0) {
                    break;
                }
                $part = min($left, (float) $sale->balance);
                self::addPayment($sale, ['amount' => $part] + $data);
                $applied[$sale->code] = $part;
                $left = round($left - $part, 2);
            }

            return $applied;
        });
    }

    /**
     * Anula la venta: devuelve lo cobrado (sale de la caja) y lo cobrado
     * (consultas, planes) queda otra vez pendiente de cobro.
     */
    public static function cancel(Sale $sale, array $input): Sale
    {
        $data = validator($input, [
            'cancel_reason' => ['required', 'string', 'max:255'],
            'payment_method_id' => ['nullable', Rule::exists('payment_methods', 'id')->where('company_id', app(Tenancy::class)->id())],
            'cash_session_id' => ['nullable', 'integer'],
        ], [], ['cancel_reason' => 'motivo', 'payment_method_id' => 'método de devolución'])->validate();

        return DB::transaction(function () use ($sale, $data) {
            $sale = Sale::lockForUpdate()->with('items')->findOrFail($sale->id);
            if ($sale->isCancelled()) {
                throw ValidationException::withMessages(['cancel_reason' => 'La venta ya está anulada.']);
            }

            $refund = round((float) $sale->paid, 2);
            if ($refund > 0) {
                if (empty($data['payment_method_id'])) {
                    throw ValidationException::withMessages(['payment_method_id' => 'Elige cómo se devuelve el dinero (' . Money::format($refund) . ').']);
                }
                $session = self::sessionFor($data['cash_session_id'] ?? null, true);
                if ($session) {
                    $method = PaymentMethod::findOrFail($data['payment_method_id']);
                    if ($method->isCash() && $refund > $session->expectedCash()) {
                        throw ValidationException::withMessages(['payment_method_id' => 'En la caja no hay efectivo suficiente para devolver ' . Money::format($refund) . '.']);
                    }
                    Cash::record($session, 'refund', $method, -$refund, "Anulación venta {$sale->code}", $sale);
                }
            }

            foreach ($sale->items as $item) {
                ConsultationProcedure::where('sale_item_id', $item->id)->update(['sale_item_id' => null]);
                TreatmentPlanItem::where('sale_item_id', $item->id)->update(['sale_item_id' => null]);
            }

            Commissions::onCancel($sale);
            // Los productos vendidos vuelven al stock.
            Stock::restore($sale, 'sale_return', "Anulación {$sale->code}");

            $sale->update([
                'status' => 'anulada', 'balance' => 0,
                'cancelled_at' => now(), 'cancelled_by' => auth()->id(), 'cancel_reason' => trim($data['cancel_reason']),
            ]);

            return $sale;
        });
    }

    /** Cuánto se cobró de un plan de tratamiento (por sus ítems), sin anuladas. */
    public static function billedForPlan(int $planId): float
    {
        // El descuento global de cada venta se reparte entre sus líneas (proporcional).
        return round((float) SaleItem::with('sale')->whereHas('sale', fn ($q) => $q->valid())
            ->whereHas('planItem', fn ($q) => $q->where('treatment_plan_id', $planId))
            ->get()
            ->sum(fn (SaleItem $item) => (float) $item->sale->subtotal > 0
                ? (float) $item->total * (float) $item->sale->total / (float) $item->sale->subtotal
                : 0), 2);
    }

    // ── Internos ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> línea normalizada con su origen verificado */
    private static function resolveLine(array $item, ?Patient $patient): array
    {
        $source = $item['source'];
        $line = ['procedure_id' => null, 'consultation_procedure_id' => null, 'treatment_plan_item_id' => null, 'tooth' => null];

        if ($source === 'consultation') {
            $cp = ConsultationProcedure::with('consultation')->find($item['id'] ?? 0);
            if (! $cp || $cp->consultation?->company_id !== app(Tenancy::class)->id()) {
                throw ValidationException::withMessages(['items' => 'Uno de los procedimientos no existe.']);
            }
            if ($cp->sale_item_id) {
                throw ValidationException::withMessages(['items' => "«{$cp->name}» ya fue cobrado."]);
            }
            if (! $patient || $cp->consultation->patient_id !== $patient->id) {
                throw ValidationException::withMessages(['items' => "«{$cp->name}» es de otro paciente."]);
            }
            $line = ['procedure_id' => $cp->procedure_id, 'consultation_procedure_id' => $cp->id, 'tooth' => $cp->tooth,
                'description' => $cp->name . ($cp->tooth ? " · pieza {$cp->tooth}" . ($cp->surfaces ? " {$cp->surfaces}" : '') : ''),
                'quantity' => $cp->quantity, 'unit_price' => (float) $cp->price] + $line;
        } elseif ($source === 'plan') {
            $planItem = TreatmentPlanItem::with('plan')->find($item['id'] ?? 0);
            if (! $planItem || $planItem->plan?->company_id !== app(Tenancy::class)->id()) {
                throw ValidationException::withMessages(['items' => 'Uno de los ítems del plan no existe.']);
            }
            if ($planItem->sale_item_id) {
                throw ValidationException::withMessages(['items' => "«{$planItem->name}» del plan ya fue cobrado."]);
            }
            if (! $patient || $planItem->plan->patient_id !== $patient->id) {
                throw ValidationException::withMessages(['items' => "«{$planItem->name}» es del plan de otro paciente."]);
            }
            if (! in_array($planItem->plan->status, ['aprobado', 'terminado'], true) || $planItem->status === 'cancelado') {
                throw ValidationException::withMessages(['items' => 'Solo se cobran ítems de planes aprobados.']);
            }
            $line = ['procedure_id' => $planItem->procedure_id, 'treatment_plan_item_id' => $planItem->id, 'tooth' => $planItem->tooth,
                'description' => $planItem->name . ($planItem->tooth ? " · pieza {$planItem->tooth}" . ($planItem->surfaces ? " {$planItem->surfaces}" : '') : '') . " ({$planItem->plan->code})",
                'quantity' => $planItem->quantity, 'unit_price' => (float) $planItem->unit_price] + $line;
        } elseif ($source === 'product') {
            $product = \App\Models\Product::where('active', true)->find($item['product_id'] ?? 0);
            if (! $product) {
                throw ValidationException::withMessages(['items' => 'Uno de los productos no existe o está inactivo.']);
            }
            $line = ['product_id' => $product->id, 'product' => $product, 'description' => $product->label, 'personal_id' => $item['personal_id'] ?? null,
                'quantity' => (float) ($item['quantity'] ?? 1), 'unit_price' => (float) ($item['unit_price'] ?? $product->sale_price)] + $line;
        } else {
            $procedure = ! empty($item['procedure_id']) ? Procedure::find($item['procedure_id']) : null;
            $description = trim((string) ($item['description'] ?? '')) ?: $procedure?->name;
            if (! $description) {
                throw ValidationException::withMessages(['items' => 'Cada ítem suelto necesita un procedimiento o una descripción.']);
            }
            $line = ['procedure_id' => $procedure?->id, 'description' => $description, 'personal_id' => $item['personal_id'] ?? null,
                'quantity' => (float) ($item['quantity'] ?? 1), 'unit_price' => (float) ($item['unit_price'] ?? $procedure?->price ?? 0)] + $line;
        }

        // En lo atendido se respeta lo registrado; en lo suelto, lo que se escribió. El descuento por línea siempre se acepta.
        if ($source === 'free' || isset($item['unit_price'])) {
            $line['unit_price'] = (float) ($item['unit_price'] ?? $line['unit_price']);
        }
        $gross = round((float) $line['quantity'] * (float) $line['unit_price'], 2);
        $line['discount'] = min(round((float) ($item['discount'] ?? 0), 2), $gross);
        $line['total'] = round($gross - $line['discount'], 2);

        return $line;
    }

    /** Marca como cobrado lo que la línea trae (y su pareja: consulta ↔ plan). */
    private static function markBilled(SaleItem $item): void
    {
        if ($item->consultation_procedure_id) {
            ConsultationProcedure::whereKey($item->consultation_procedure_id)->update(['sale_item_id' => $item->id]);
            TreatmentPlanItem::where('consultation_procedure_id', $item->consultation_procedure_id)->whereNull('sale_item_id')->update(['sale_item_id' => $item->id]);
        }
        if ($item->treatment_plan_item_id) {
            $planItem = TreatmentPlanItem::find($item->treatment_plan_item_id);
            $planItem?->update(['sale_item_id' => $item->id]);
            if ($planItem?->consultation_procedure_id) {
                ConsultationProcedure::whereKey($planItem->consultation_procedure_id)->whereNull('sale_item_id')->update(['sale_item_id' => $item->id]);
            }
        }
    }

    /** @return Collection<int, array{method: PaymentMethod, amount: float, reference: ?string}> */
    private static function payments(array $raw): Collection
    {
        $methods = PaymentMethod::whereIn('id', collect($raw)->pluck('payment_method_id'))->get()->keyBy('id');

        return collect($raw)
            ->map(fn ($p) => ['method' => $methods->get($p['payment_method_id']), 'amount' => round((float) $p['amount'], 2), 'reference' => $p['reference'] ?? null])
            ->filter(fn ($p) => $p['method'] && $p['amount'] > 0)
            ->values();
    }

    /**
     * El vuelto solo sale del efectivo (se descuenta del último pago en efectivo).
     *
     * @return array{0: array<int, array>, 1: float}
     */
    private static function settle(Collection $payments, float $total): array
    {
        $sum = round($payments->sum('amount'), 2);
        $change = round(max($sum - $total, 0), 2);

        if ($change > 0) {
            $cash = round($payments->filter(fn ($p) => $p['method']->isCash())->sum('amount'), 2);
            if ($change > $cash) {
                throw ValidationException::withMessages(['payments' => 'Los pagos superan el total y solo el efectivo puede dar vuelto.']);
            }
            $payments = $payments->reverse()->map(function ($p) use (&$change) {
                if ($change > 0 && $p['method']->isCash()) {
                    $take = min($change, $p['amount']);
                    $p['amount'] = round($p['amount'] - $take, 2);
                    $change = round($change - $take, 2);
                }

                return $p;
            })->reverse()->filter(fn ($p) => $p['amount'] > 0)->values();
        }

        foreach ($payments as $payment) {
            self::checkReference($payment['method'], $payment['reference']);
        }

        return [$payments->all(), round(min($sum, $total), 2)];
    }

    private static function checkReference(PaymentMethod $method, ?string $reference): void
    {
        if ($method->requires_reference && blank($reference)) {
            throw ValidationException::withMessages(['payments' => "El pago con {$method->name} necesita el número de referencia (comprobante)."]);
        }
    }

    /** @return array<string, mixed> */
    private static function validatePayment(array $input): array
    {
        return validator($input, [
            'payment_method_id' => ['required', Rule::exists('payment_methods', 'id')->where('company_id', app(Tenancy::class)->id())->where('active', true)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'reference' => ['nullable', 'string', 'max:80'],
            'cash_session_id' => ['nullable', 'integer'],
        ], [], ['payment_method_id' => 'método de pago', 'amount' => 'monto'])->validate();
    }

    /** Con el módulo de caja, cobrar (o devolver) exige un turno abierto. */
    private static function sessionFor(?int $sessionId, bool $moneyMoves): ?CashSession
    {
        if (! $moneyMoves || ! Cash::enabled()) {
            return null;
        }
        if (! $sessionId) {
            $open = Cash::openSessions();
            if ($open->count() === 1) {
                return $open->first();
            }
            throw ValidationException::withMessages(['cash_session_id' => $open->isEmpty() ? 'Abre una caja para cobrar.' : 'Elige en qué caja entra el cobro.']);
        }

        return Cash::sessionFor($sessionId);
    }

    private static function registerPayment(Sale $sale, PaymentMethod $method, float $amount, ?string $reference, ?CashSession $session, string $kind): void
    {
        $payment = $sale->payments()->create([
            'company_id' => $sale->company_id,
            'payment_method_id' => $method->id,
            'cash_session_id' => $session?->id,
            'kind' => $kind,
            'amount' => $amount,
            'reference' => $reference,
            'user_id' => auth()->id(),
            'paid_at' => now(),
        ]);
        Commissions::onPayment($sale, $payment);

        if ($session) {
            Cash::record($session, $kind === 'venta' ? 'sale' : 'receivable', $method, $amount,
                ($kind === 'venta' ? 'Venta ' : 'Abono ') . $sale->code . ($sale->patient ? ' · ' . $sale->patient->full_name : ''), $payment);
        }
    }
}
