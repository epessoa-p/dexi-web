<?php

namespace App\Support;

use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\SaleItem;
use App\Models\Supplier;
use App\Models\TreatmentPlanItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Laboratorio dental: órdenes de trabajo, su recorrido y el pago al
 * laboratorio (un gasto de proveedor que puede cubrir varias órdenes).
 * Su costo se descuenta de la base de la comisión del odontólogo.
 */
class LabOrders
{
    /** Categoría de gasto con la que se pagan los trabajos. */
    public const EXPENSE_CATEGORY = 'LABORATORIO DENTAL';

    public static function validate(array $input, ?LabOrder $order = null): array
    {
        $companyId = app(Tenancy::class)->id();
        $data = validator($input, [
            'patient_id' => [$order ? 'nullable' : 'required', Rule::exists('patients', 'id')->where('company_id', $companyId)],
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'personal_id' => ['nullable', Rule::exists('personal', 'id')->where('company_id', $companyId)],
            'treatment_plan_item_id' => ['nullable', 'integer'],
            'consultation_id' => ['nullable', 'integer'],
            'work_type' => ['required', Rule::in(array_keys(LabOrder::WORK_TYPES))],
            'teeth' => ['nullable', 'string', 'max:120'],
            'material' => ['nullable', 'string', 'max:40'],
            'shade' => ['nullable', 'string', 'max:20'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'sent_at' => ['required', 'date', 'before_or_equal:today'],
            'due_at' => ['nullable', 'date', 'after_or_equal:sent_at'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'due_at.after_or_equal' => 'La entrega no puede ser antes del envío.',
            'sent_at.before_or_equal' => 'La fecha de envío no puede ser futura.',
        ], ['patient_id' => 'paciente', 'supplier_id' => 'laboratorio', 'work_type' => 'trabajo', 'sent_at' => 'enviado el', 'due_at' => 'entrega', 'cost' => 'costo'])->validate();

        $patientId = $order?->patient_id ?? (int) $data['patient_id'];
        // El ítem del plan tiene que ser de este paciente (y de esta clínica).
        if (! empty($data['treatment_plan_item_id'])) {
            $item = TreatmentPlanItem::with('plan')->find($data['treatment_plan_item_id']);
            if (! $item || $item->plan?->company_id !== $companyId || $item->plan->patient_id !== $patientId) {
                throw ValidationException::withMessages(['treatment_plan_item_id' => 'Ese trabajo no es del plan de este paciente.']);
            }
        }
        if (! empty($data['consultation_id'])) {
            $data['consultation_id'] = \App\Models\Consultation::where('patient_id', $patientId)->whereKey($data['consultation_id'])->value('id');
        }

        return $data;
    }

    public static function create(array $input): LabOrder
    {
        $data = self::validate($input);

        return DB::transaction(function () use ($data) {
            Company::whereKey(app(Tenancy::class)->id())->lockForUpdate()->first();
            $order = LabOrder::create([
                'number' => (int) LabOrder::max('number') + 1,
                'patient_id' => $data['patient_id'],
                'supplier_id' => $data['supplier_id'],
                'personal_id' => $data['personal_id'] ?? null,
                'treatment_plan_item_id' => $data['treatment_plan_item_id'] ?? null,
                'consultation_id' => $data['consultation_id'] ?? null,
                'work_type' => $data['work_type'],
                'teeth' => $data['teeth'] ?? null,
                'material' => $data['material'] ?? null,
                'shade' => $data['shade'] ?? null,
                'instructions' => $data['instructions'] ?? null,
                'status' => 'enviado',
                'sent_at' => $data['sent_at'],
                'due_at' => $data['due_at'] ?? null,
                'cost' => round((float) ($data['cost'] ?? 0), 2),
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);
            $order->logs()->create(['from_status' => null, 'to_status' => 'enviado', 'user_id' => auth()->id()]);

            return $order;
        });
    }

    public static function update(LabOrder $order, array $input): LabOrder
    {
        if (in_array($order->status, ['instalado', 'cancelado'], true)) {
            throw ValidationException::withMessages(['work_type' => 'Una orden instalada o cancelada ya no se edita.']);
        }
        $data = self::validate($input, $order);
        if ($order->isPaid() && round((float) ($data['cost'] ?? 0), 2) !== round((float) $order->cost, 2)) {
            throw ValidationException::withMessages(['cost' => 'Ya se pagó al laboratorio: para cambiar el costo, anula primero el gasto.']);
        }
        if ($order->isPaid() && (int) $data['supplier_id'] !== $order->supplier_id) {
            throw ValidationException::withMessages(['supplier_id' => 'Ya se pagó a ese laboratorio: no se puede cambiar.']);
        }
        $order->update(collect($data)->except('patient_id')->all() + ['cost' => round((float) ($data['cost'] ?? 0), 2)]);

        return $order;
    }

    /** Avanza la orden (o la devuelve para ajuste: recibido → en proceso). */
    public static function setStatus(LabOrder $order, string $status, ?string $note = null): LabOrder
    {
        if (! in_array($status, LabOrder::TRANSITIONS[$order->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'No se puede pasar de «' . $order->status_label . '» a «' . (LabOrder::STATUSES[$status][0] ?? $status) . '».']);
        }
        if ($status === 'cancelado' && blank($note)) {
            throw ValidationException::withMessages(['note' => 'Indica por qué se cancela.']);
        }
        if ($status === 'cancelado' && $order->isPaid()) {
            throw ValidationException::withMessages(['note' => 'Ya se pagó al laboratorio: anula primero el gasto.']);
        }

        return DB::transaction(function () use ($order, $status, $note) {
            $from = $order->status;
            $returned = $from === 'recibido' && $status === 'en_proceso';
            $order->update(array_filter([
                'status' => $status,
                'received_at' => $status === 'recibido' ? now() : null,
                'installed_at' => $status === 'instalado' ? now() : null,
                'cancelled_at' => $status === 'cancelado' ? now() : null,
                'cancel_reason' => $status === 'cancelado' ? $note : null,
                'returns_count' => $returned ? $order->returns_count + 1 : null,
            ], fn ($v) => $v !== null));
            if ($returned) {
                $order->update(['received_at' => null]);
            }
            $order->logs()->create(['from_status' => $from, 'to_status' => $status, 'note' => $note, 'user_id' => auth()->id()]);

            return $order;
        });
    }

    /**
     * Paga al laboratorio una o varias órdenes con un solo gasto de proveedor
     * (categoría LABORATORIO DENTAL), desde una cuenta o la caja.
     *
     * @param  array<int>  $orderIds
     */
    public static function pay(Supplier $lab, array $orderIds, array $input): \App\Models\Expense
    {
        return DB::transaction(function () use ($lab, $orderIds, $input) {
            $orders = LabOrder::unpaid()->where('supplier_id', $lab->id)->whereIn('id', $orderIds)->lockForUpdate()->orderBy('number')->get();
            if ($orders->isEmpty()) {
                throw ValidationException::withMessages(['orders' => 'Elige al menos un trabajo con costo y sin pagar.']);
            }
            $category = ExpenseCategory::firstOrNew(['name' => self::EXPENSE_CATEGORY]);
            $category->fill(ExpenseCategory::attributesFor('proveedor') + ['active' => true])->save();

            $expense = Expenses::register([
                'group' => 'proveedor',
                'expense_category_id' => $category->id,
                'supplier_id' => $lab->id,
                'concept' => 'Laboratorio ' . $lab->name . ': ' . $orders->map(fn (LabOrder $o) => $o->code)->implode(', '),
                'amount' => round((float) $orders->sum('cost'), 2),
                'spent_at' => $input['spent_at'] ?? today()->toDateString(),
                'reference' => $input['reference'] ?? null,
            ] + collect($input)->only(['source', 'finance_account_id', 'cash_session_id'])->all());

            LabOrder::whereIn('id', $orders->pluck('id'))->update(['expense_id' => $expense->id]);

            return $expense;
        });
    }

    /** Al anular el gasto, sus órdenes vuelven a quedar por pagar. */
    public static function onExpenseCancelled(\App\Models\Expense $expense): void
    {
        LabOrder::where('expense_id', $expense->id)->update(['expense_id' => null]);
    }

    /**
     * Costo de laboratorio de una línea cobrada (para la comisión): el de los
     * trabajos no cancelados del ítem del plan que la línea cobra.
     */
    public static function costForSaleItem(SaleItem $item): float
    {
        $planItemId = $item->treatment_plan_item_id
            ?? ($item->consultation_procedure_id ? TreatmentPlanItem::where('consultation_procedure_id', $item->consultation_procedure_id)->value('id') : null);

        return $planItemId
            ? round((float) LabOrder::where('treatment_plan_item_id', $planItemId)->where('status', '!=', 'cancelado')->sum('cost'), 2)
            : 0.0;
    }

    /** Los trabajos del plan del paciente que se pueden enlazar (planes aprobados). */
    public static function planItemsFor(Patient $patient)
    {
        return TreatmentPlanItem::with('plan')
            ->whereHas('plan', fn ($q) => $q->where('patient_id', $patient->id)->whereIn('status', ['presentado', 'aprobado']))
            ->where('status', '!=', 'cancelado')->get();
    }
}
