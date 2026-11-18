<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Admin\LabOrderController as WebLabOrderController;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\Personal;
use App\Models\Supplier;
use App\Support\LabOrders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Laboratorio en la app: los trabajos en curso, atrasados y por instalar, su
 * recorrido (llegó, instalado, devolver para ajuste) y la orden nueva desde
 * la ficha. El pago al laboratorio sigue en la web. Reglas de App\Support\LabOrders.
 */
class LabOrderController extends WebLabOrderController
{
    public function list(Request $request): JsonResponse
    {
        $filter = array_key_exists($request->query('filter'), self::FILTERS) ? $request->query('filter') : 'activos';
        $patientId = $request->integer('patient_id') ?: null;
        if ($patientId && ! $request->query('filter')) {
            $filter = 'todos';
        }
        $scope = fn ($q, string $f) => match ($f) {
            'activos' => $q->active(),
            'atrasados' => $q->whereIn('status', ['enviado', 'en_proceso'])->whereNotNull('due_at')->whereDate('due_at', '<', today()),
            'recibidos' => $q->where('status', 'recibido'),
            'pagar' => $q->unpaid(),
            'instalados' => $q->where('status', 'instalado'),
            default => $q,
        };
        $base = LabOrder::query()
            ->when($patientId, fn ($q) => $q->where('patient_id', $patientId))
            ->when($request->query('q'), fn ($q, $term) => $q->whereHas('patient', fn ($p) => $p->search($term)));

        $orders = $scope((clone $base)->with(['patient', 'lab', 'professional']), $filter)
            ->orderByRaw("case when status in ('enviado','en_proceso') then 0 when status = 'recibido' then 1 else 2 end")
            ->orderBy('due_at')->latest('id')->limit(100)->get();

        return response()->json([
            'filter' => $filter,
            // «Por pagar» se gestiona en la web (se paga agrupado por laboratorio).
            'filters' => collect(self::FILTERS)->except('pagar')->map(fn ($v, $k) => ['key' => $k, 'label' => $v[0], 'count' => $scope(clone $base, $k)->count()])->values(),
            'data' => $orders->map(fn (LabOrder $o) => self::summary($o))->values(),
        ]);
    }

    /** Lo necesario para una orden nueva: laboratorios, profesionales, trabajos, materiales, colores y los ítems del plan del paciente. */
    public function options(Request $request): JsonResponse
    {
        $patient = $request->integer('patient_id') ? Patient::findOrFail($request->integer('patient_id')) : null;

        return response()->json([
            'labs' => Supplier::where('active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            // Los laboratorios ya usados, primero.
            'used_labs' => LabOrder::select('supplier_id')->distinct()->pluck('supplier_id')->values(),
            'professionals' => Personal::professionals()->get(['id', 'full_name'])->map(fn ($p) => ['id' => $p->id, 'name' => $p->full_name])->values(),
            'default_professional' => Personal::where('user_id', auth()->id())->value('id'),
            'work_types' => collect(LabOrder::WORK_TYPES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'materials' => LabOrder::MATERIALS,
            'shades' => LabOrder::SHADES,
            'plan_items' => $patient ? LabOrders::planItemsFor($patient)->map(fn ($i) => [
                'id' => $i->id,
                'name' => $i->name,
                'tooth' => $i->tooth,
                'plan' => $i->plan?->code,
            ])->values() : [],
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $order = LabOrders::create($request->all() + ['sent_at' => today()->toDateString()]);

        return response()->json(self::detail($order), 201);
    }

    public function detailFor(LabOrder $order): JsonResponse
    {
        return response()->json(self::detail($order));
    }

    public function changeStatus(Request $request, LabOrder $order): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(LabOrder::STATUSES))], 'note' => ['nullable', 'string', 'max:255']]);
        LabOrders::setStatus($order, $data['status'], $data['note'] ?? null);

        return response()->json(self::detail($order->fresh()));
    }

    /** @return array<string, mixed> */
    public static function summary(LabOrder $o): array
    {
        return [
            'id' => $o->id,
            'code' => $o->code,
            'status' => $o->status,
            'status_label' => $o->status_label,
            'status_color' => $o->status_color,
            'work' => $o->work_label,
            'summary' => $o->summary,
            'patient' => $o->patient ? ['id' => $o->patient->id, 'full_name' => $o->patient->full_name] : null,
            'lab' => $o->lab?->name,
            'professional' => $o->professional?->full_name,
            'sent_at' => $o->sent_at?->toDateString(),
            'due_at' => $o->due_at?->toDateString(),
            'late' => $o->isLate(),
            'returns_count' => $o->returns_count,
        ];
    }

    /** @return array<string, mixed> */
    private static function detail(LabOrder $o): array
    {
        $o->load(['patient', 'lab', 'professional', 'planItem.plan', 'expense', 'logs.user']);

        return self::summary($o) + [
            'teeth' => $o->teeth,
            'material' => $o->material,
            'shade' => $o->shade,
            'instructions' => $o->instructions,
            'notes' => $o->notes,
            'cost' => (float) $o->cost,
            'paid' => $o->isPaid(),
            'plan_item' => $o->planItem ? ['id' => $o->planItem->id, 'name' => $o->planItem->name, 'plan' => $o->planItem->plan?->code] : null,
            'received_at' => $o->received_at?->format('Y-m-d\TH:i:s'),
            'installed_at' => $o->installed_at?->format('Y-m-d\TH:i:s'),
            'cancel_reason' => $o->cancel_reason,
            // Lo que se puede hacer ahora (recibido → en proceso = devolver para ajuste).
            // El paso más común primero (es el botón principal en la app).
            'next' => collect(LabOrder::TRANSITIONS[$o->status] ?? [])
                ->sortBy(fn ($s) => array_search($s, ['recibido', 'instalado', 'en_proceso', 'cancelado'], true))->values()->map(fn ($s) => [
                'status' => $s,
                'label' => match ([$o->status, $s]) {
                    ['recibido', 'en_proceso'] => 'Devolver para ajuste',
                    [$o->status, 'recibido'] => 'Llegó del laboratorio',
                    [$o->status, 'instalado'] => 'Instalado en boca',
                    [$o->status, 'en_proceso'] => 'En proceso',
                    [$o->status, 'cancelado'] => 'Cancelar',
                    default => LabOrder::STATUSES[$s][0] ?? $s,
                },
            ])->values(),
            'log' => $o->logs->map(fn ($l) => [
                'from' => $l->from_status ? (LabOrder::STATUSES[$l->from_status][0] ?? $l->from_status) : null,
                'to' => LabOrder::STATUSES[$l->to_status][0] ?? $l->to_status,
                'note' => $l->note,
                'at' => $l->created_at?->format('Y-m-d\TH:i:s'),
                'user' => $l->user?->name,
            ])->values(),
        ];
    }
}
