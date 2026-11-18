<?php

namespace App\Support;

use App\Models\Consultation;
use App\Models\ConsultationProcedure;
use App\Models\OdontogramEntry;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Planes de tratamiento / presupuestos, en un solo lugar (web y API):
 *
 * - Se arman a mano o desde los hallazgos del odontograma (sugerencia).
 * - borrador → presentado → aprobado → terminado; rechazado o cancelado en cualquier momento antes de terminar.
 * - Lo que se hace en una consulta marca solo el ítem pendiente del plan aprobado
 *   (mismo procedimiento y misma pieza). Con todo realizado, el plan se termina solo.
 */
final class TreatmentPlans
{
    /** Ítem que debe marcarse primero (lo usa «Hecho hoy» desde la consulta). */
    private static ?int $preferredItemId = null;

    /** Hallazgo del odontograma → procedimiento sugerido (código del tarifario sugerido). */
    public static function suggestedCode(string $condition, int $tooth, int $surfaces): ?string
    {
        $n = $tooth % 10;
        $temporal = intdiv($tooth, 10) >= 5;

        return match ($condition) {
            'caries' => $surfaces > 1 ? 'OPE-02' : 'OPE-01',
            'fractura', 'restauracion_defectuosa' => 'OPE-02',
            'endodoncia_indicada' => $temporal ? 'END-05' : ($n <= 3 ? 'END-01' : ($n <= 5 ? 'END-02' : 'END-03')),
            'extraccion_indicada' => $temporal ? 'PED-02' : ($n === 8 ? 'CIR-03' : 'CIR-01'),
            'resto_radicular' => 'CIR-02',
            'movilidad' => 'PER-01',
            default => null,
        };
    }

    /**
     * Sugerencias a partir de los hallazgos abiertos del odontograma: una por
     * pieza y condición (una caries en O y M es una sola resina «MO»).
     *
     * @return Collection<int, array{key: string, tooth: int, surfaces: ?string, condition: string, finding: string, procedure: ?Procedure, phase: int}>
     */
    public static function suggestions(Patient $patient): Collection
    {
        $procedures = Procedure::where('active', true)->whereNotNull('code')->get()->keyBy('code');

        return OdontogramEntry::where('patient_id', $patient->id)->whereNull('resolved_at')->get()
            ->filter(fn (OdontogramEntry $e) => $e->type === 'hallazgo')
            ->groupBy(fn (OdontogramEntry $e) => $e->tooth . '-' . $e->condition)
            ->map(function (Collection $group) use ($procedures) {
                $first = $group->first();
                $surfaces = Consultations::surfaces($group->pluck('surface')->filter()->all());
                $code = self::suggestedCode($first->condition, $first->tooth, strlen((string) $surfaces));

                return [
                    'key' => "{$first->tooth}-{$first->condition}",
                    'tooth' => $first->tooth,
                    'surfaces' => $surfaces,
                    'condition' => $first->condition,
                    'finding' => $first->label,
                    'procedure' => $code ? $procedures->get($code) : null,
                    // Urgente primero: dolor/infección (endodoncia, extracción) en fase 1; lo restaurador en la 2.
                    'phase' => in_array($first->condition, ['endodoncia_indicada', 'extraccion_indicada', 'resto_radicular'], true) ? 1 : 2,
                ];
            })
            ->sortBy(fn ($s) => [$s['phase'], $s['tooth']])
            ->values();
    }

    public static function create(Patient $patient, array $input): TreatmentPlan
    {
        $companyId = app(Tenancy::class)->id();
        $data = validator($input, [
            'title' => ['nullable', 'string', 'max:150'],
            'personal_id' => ['nullable', Rule::exists('personal', 'id')->where('company_id', $companyId)],
            'consultation_id' => ['nullable', Rule::exists('consultations', 'id')->where('company_id', $companyId)->where('patient_id', $patient->id)],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'suggestions' => ['nullable', 'array'],
            'suggestions.*' => ['string'],
        ])->validate();

        return DB::transaction(function () use ($patient, $data) {
            $plan = TreatmentPlan::create([
                'patient_id' => $patient->id,
                'personal_id' => $data['personal_id'] ?? Consultations::myProfessionalId(),
                'consultation_id' => $data['consultation_id'] ?? null,
                'number' => (int) TreatmentPlan::lockForUpdate()->max('number') + 1,
                'title' => trim((string) ($data['title'] ?? '')) ?: 'Plan de tratamiento',
                'status' => 'borrador',
                'valid_until' => $data['valid_until'] ?? now()->addDays(30)->toDateString(),
                'created_by' => auth()->id(),
            ]);

            // Ítems a partir de las sugerencias elegidas del odontograma.
            $chosen = collect($data['suggestions'] ?? []);
            if ($chosen->isNotEmpty()) {
                self::suggestions($patient)->filter(fn ($s) => $chosen->contains($s['key']) && $s['procedure'])
                    ->each(fn ($s, $i) => $plan->items()->create([
                        'procedure_id' => $s['procedure']->id,
                        'name' => $s['procedure']->name,
                        'tooth' => $s['tooth'],
                        'surfaces' => $s['surfaces'],
                        'phase' => $s['phase'],
                        'unit_price' => $s['procedure']->price,
                        'notes' => $s['finding'],
                        'sort' => $i,
                    ]));
            }

            return $plan;
        });
    }

    public static function addItem(TreatmentPlan $plan, array $input): TreatmentPlanItem
    {
        if ($plan->isClosed()) {
            throw ValidationException::withMessages(['procedure_id' => 'El plan está cerrado.']);
        }
        $companyId = app(Tenancy::class)->id();
        $data = validator($input, [
            'procedure_id' => ['required', Rule::exists('procedures', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'tooth' => ['nullable', 'integer', Rule::in(Odontogram::teeth())],
            'surfaces' => ['nullable', 'array'],
            'surfaces.*' => [Rule::in(array_keys(Odontogram::SURFACES))],
            'phase' => ['nullable', 'integer', 'min:1', 'max:9'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['procedure_id' => 'procedimiento', 'tooth' => 'pieza', 'phase' => 'fase', 'unit_price' => 'precio'])->validate();

        $procedure = Procedure::findOrFail($data['procedure_id']);
        if (in_array($procedure->applies_to, ['pieza', 'cara'], true) && empty($data['tooth'])) {
            throw ValidationException::withMessages(['tooth' => "«{$procedure->name}» se hace en una pieza: elige cuál."]);
        }

        return $plan->items()->create([
            'procedure_id' => $procedure->id,
            'name' => $procedure->name,
            'tooth' => $data['tooth'] ?? null,
            'surfaces' => Consultations::surfaces($data['surfaces'] ?? []),
            'phase' => $data['phase'] ?? 1,
            'quantity' => $data['quantity'] ?? 1,
            'unit_price' => $data['unit_price'] ?? $procedure->price,
            'notes' => $data['notes'] ?? null,
            'sort' => (int) $plan->items()->max('sort') + 1,
        ]);
    }

    /** Cambia precio, cantidad, fase o nota (solo mientras se puede editar). */
    public static function updateItem(TreatmentPlanItem $item, array $input): TreatmentPlanItem
    {
        if (! $item->plan->isEditable()) {
            throw ValidationException::withMessages(['unit_price' => 'El plan ya fue aprobado: el precio no se cambia (cancela el ítem y agrega otro).']);
        }
        $item->update(validator($input, [
            'unit_price' => ['sometimes', 'numeric', 'min:0', 'max:9999999'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'phase' => ['sometimes', 'integer', 'min:1', 'max:9'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], [], ['unit_price' => 'precio', 'quantity' => 'cantidad', 'phase' => 'fase'])->validate());

        return $item;
    }

    /** Antes de aprobar se borra; después se cancela (queda la constancia). */
    public static function removeItem(TreatmentPlanItem $item): void
    {
        $plan = $item->plan;
        if ($plan->isEditable()) {
            $item->delete();

            return;
        }
        if ($item->status !== 'pendiente') {
            throw ValidationException::withMessages(['item' => 'Solo se cancelan ítems pendientes.']);
        }
        $item->update(['status' => 'cancelado']);
        self::completeIfDone($plan);
    }

    public static function saveHeader(TreatmentPlan $plan, array $input): TreatmentPlan
    {
        if ($plan->isClosed()) {
            throw ValidationException::withMessages(['title' => 'El plan está cerrado.']);
        }
        $data = validator($input, [
            'title' => ['required', 'string', 'max:150'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ], [], ['title' => 'título', 'discount' => 'descuento', 'valid_until' => 'válido hasta'])->validate();

        // El descuento es parte de lo que el paciente aceptó: después de aprobar no cambia.
        if (! $plan->isEditable()) {
            unset($data['discount']);
        }
        $plan->update($data);

        return $plan;
    }

    public static function setStatus(TreatmentPlan $plan, string $status, ?string $value = null): TreatmentPlan
    {
        $allowed = [
            'presentado' => ['borrador'],
            'aprobado' => ['borrador', 'presentado'],
            'borrador' => ['presentado', 'rechazado'],
            'rechazado' => ['borrador', 'presentado'],
            'cancelado' => ['borrador', 'presentado', 'aprobado'],
        ];
        if (! in_array($plan->status, $allowed[$status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "Un plan «{$plan->status_label}» no puede pasar a «" . (TreatmentPlan::STATUSES[$status][0] ?? $status) . '».']);
        }
        if (in_array($status, ['presentado', 'aprobado'], true) && $plan->items()->where('status', '!=', 'cancelado')->doesntExist()) {
            throw ValidationException::withMessages(['status' => 'El plan no tiene procedimientos.']);
        }
        if ($status === 'aprobado' && blank($value)) {
            throw ValidationException::withMessages(['approved_by_name' => 'Anota quién aprueba el plan (el paciente o su tutor).']);
        }
        if (in_array($status, ['rechazado', 'cancelado'], true) && blank($value)) {
            throw ValidationException::withMessages(['close_reason' => 'Escribe el motivo.']);
        }

        $plan->update(match ($status) {
            'presentado' => ['status' => 'presentado', 'presented_at' => now()],
            'aprobado' => ['status' => 'aprobado', 'approved_at' => now(), 'approved_by_name' => trim($value), 'presented_at' => $plan->presented_at ?? now()],
            'borrador' => ['status' => 'borrador', 'closed_at' => null, 'close_reason' => null],
            default => ['status' => $status, 'closed_at' => now(), 'close_reason' => trim($value)],
        });

        if ($status === 'aprobado') {
            // Lo que ya se hizo en consultas anteriores (antes de aprobar) también cuenta.
            self::completeIfDone($plan);
        }

        return $plan;
    }

    /** Marca a mano un ítem como realizado (o lo vuelve a pendiente). */
    public static function toggleDone(TreatmentPlanItem $item, bool $done): TreatmentPlanItem
    {
        $plan = $item->plan;
        if ($plan->status !== 'aprobado' && ! ($plan->status === 'terminado' && ! $done)) {
            throw ValidationException::withMessages(['item' => 'Primero aprueba el plan.']);
        }
        $item->update($done
            ? ['status' => 'realizado', 'done_at' => now()]
            : ['status' => 'pendiente', 'done_at' => null, 'consultation_id' => null, 'consultation_procedure_id' => null]);

        if (! $done && $plan->status === 'terminado') {
            $plan->update(['status' => 'aprobado', 'completed_at' => null]);
        }
        self::completeIfDone($plan);

        return $item;
    }

    /**
     * Lo registrado en una consulta marca el ítem pendiente del plan aprobado
     * del paciente: mismo procedimiento y misma pieza (o sin pieza).
     */
    public static function linkFromConsultation(ConsultationProcedure $done): ?TreatmentPlanItem
    {
        $consultation = $done->consultation;
        $item = TreatmentPlanItem::query()
            ->whereHas('plan', fn ($q) => $q->where('patient_id', $consultation->patient_id)->where('status', 'aprobado'))
            ->where('status', 'pendiente')
            ->where('procedure_id', $done->procedure_id)
            ->where(fn ($q) => $done->tooth ? $q->where('tooth', $done->tooth) : $q->whereNull('tooth'))
            ->when(self::$preferredItemId, fn ($q, $id) => $q->orderByRaw('case when id = ? then 0 else 1 end', [$id]))
            ->orderBy('phase')->orderBy('id')
            ->first();

        if (! $item) {
            return null;
        }

        $item->update(['status' => 'realizado', 'done_at' => now(), 'consultation_id' => $consultation->id, 'consultation_procedure_id' => $done->id]);
        // Cobrado por adelantado: lo hecho hoy no se vuelve a cobrar.
        if ($item->sale_item_id) {
            $done->update(['sale_item_id' => $item->sale_item_id]);
        }
        self::completeIfDone($item->plan);

        return $item;
    }

    /** Si se quita el procedimiento de la consulta, el ítem vuelve a pendiente. */
    public static function unlink(ConsultationProcedure $done): void
    {
        TreatmentPlanItem::where('consultation_procedure_id', $done->id)->get()->each(function (TreatmentPlanItem $item) {
            $item->update(['status' => 'pendiente', 'done_at' => null, 'consultation_id' => null, 'consultation_procedure_id' => null]);
            if ($item->plan->status === 'terminado') {
                $item->plan->update(['status' => 'aprobado', 'completed_at' => null]);
            }
        });
    }

    /** Ítems pendientes del plan aprobado (para la consulta). */
    public static function pendingFor(Patient $patient): Collection
    {
        return TreatmentPlanItem::with('plan')
            ->whereHas('plan', fn ($q) => $q->where('patient_id', $patient->id)->where('status', 'aprobado'))
            ->where('status', 'pendiente')
            ->orderBy('phase')->orderBy('id')
            ->get();
    }

    /** Desde la consulta: «Hecho hoy» registra el procedimiento del ítem (y lo marca en el odontograma). */
    public static function doInConsultation(Consultation $consultation, TreatmentPlanItem $item): ConsultationProcedure
    {
        if ($item->plan->patient_id !== $consultation->patient_id || $item->status !== 'pendiente') {
            throw ValidationException::withMessages(['item' => 'Ese ítem no está pendiente para este paciente.']);
        }

        $code = $item->procedure?->code;
        self::$preferredItemId = $item->id;
        try {
        $done = Consultations::addProcedure($consultation, [
            'procedure_id' => $item->procedure_id,
            'tooth' => $item->tooth,
            'surfaces' => $item->surfaces ? str_split($item->surfaces) : [],
            'quantity' => $item->quantity,
            'price' => $item->unit_price,
            'mark' => $code ? (Consultations::ODONTOGRAM_BY_CODE[$code] ?? null) : null,
            'notes' => "{$item->plan->code}",
        ]);
        } finally {
            self::$preferredItemId = null;
        }

        // addProcedure ya enlazó este ítem (es el preferido).
        return $done;
    }

    private static function completeIfDone(TreatmentPlan $plan): void
    {
        $plan->refresh();
        if ($plan->status !== 'aprobado') {
            return;
        }
        $active = $plan->items()->where('status', '!=', 'cancelado');
        if ($active->exists() && (clone $active)->where('status', 'pendiente')->doesntExist()) {
            $plan->update(['status' => 'terminado', 'completed_at' => now()]);
        }
    }
}
