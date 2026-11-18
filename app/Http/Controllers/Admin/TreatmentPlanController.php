<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\Personal;
use App\Models\Procedure;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Support\Consultations;
use App\Support\TreatmentPlans;
use Illuminate\Validation\Rule;

/** Planes de tratamiento / presupuestos. */
class TreatmentPlanController extends Controller
{
    public const FILTERS = [
        'abiertos' => ['Abiertos', 'bi-folder2-open'],
        'presentado' => ['Por aprobar', 'bi-hourglass-split'],
        'aprobado' => ['En curso', 'bi-play-circle'],
        'terminado' => ['Terminados', 'bi-check2-all'],
        'cerrados' => ['Rechazados / cancelados', 'bi-x-circle'],
    ];

    public function index()
    {
        $filter = array_key_exists(request('filter'), self::FILTERS) ? request('filter') : 'abiertos';

        $plans = TreatmentPlan::with(['patient', 'professional', 'items'])
            ->when($filter === 'abiertos', fn ($q) => $q->open())
            ->when(in_array($filter, ['presentado', 'aprobado', 'terminado'], true), fn ($q) => $q->where('status', $filter))
            ->when($filter === 'cerrados', fn ($q) => $q->whereIn('status', ['rechazado', 'cancelado']))
            ->when(request('q'), fn ($q, $term) => $q->whereHas('patient', fn ($p) => $p->search($term)))
            ->latest('updated_at')
            ->paginate(20)->withQueryString();

        return view('admin.treatment-plans.index', [
            'plans' => $plans,
            'filter' => $filter,
            'counts' => [
                'abiertos' => TreatmentPlan::open()->count(),
                'presentado' => TreatmentPlan::where('status', 'presentado')->count(),
                'aprobado' => TreatmentPlan::where('status', 'aprobado')->count(),
            ],
        ]);
    }

    public function create()
    {
        $patient = Patient::findOrFail(request('patient_id'));

        return view('admin.treatment-plans.create', [
            'patient' => $patient,
            'consultation' => request('consultation_id') ? Consultation::where('patient_id', $patient->id)->find(request('consultation_id')) : null,
            'suggestions' => TreatmentPlans::suggestions($patient),
            'professionals' => Personal::professionals()->get(),
            'defaultProfessional' => Consultations::myProfessionalId(),
        ]);
    }

    public function store()
    {
        $patient = Patient::findOrFail(request('patient_id'));
        $plan = TreatmentPlans::create($patient, request()->all());

        return redirect()->route('treatment-plans.show', $plan)->with('success', "Plan {$plan->code} creado" . ($plan->items()->exists() ? ' con los hallazgos elegidos.' : '. Agrega los procedimientos.'));
    }

    public function show(TreatmentPlan $plan)
    {
        $plan->load(['patient', 'professional', 'items.consultation', 'consultation']);

        return view('admin.treatment-plans.show', [
            'plan' => $plan,
            'totals' => $plan->totals(),
            'procedures' => Procedure::with('specialty')->where('active', true)->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function update(TreatmentPlan $plan)
    {
        TreatmentPlans::saveHeader($plan, request()->all());

        return back()->with('success', 'Plan actualizado.');
    }

    public function addItem(TreatmentPlan $plan)
    {
        $item = TreatmentPlans::addItem($plan, request()->all());

        return back()->with('success', "Agregado: {$item->name}" . ($item->tooth ? " (pieza {$item->tooth})" : '') . '.');
    }

    public function updateItem(TreatmentPlan $plan, TreatmentPlanItem $item)
    {
        abort_unless($item->treatment_plan_id === $plan->id, 404);
        TreatmentPlans::updateItem($item, request()->only(['unit_price', 'quantity', 'phase', 'notes']));

        return request()->expectsJson()
            ? response()->json(['ok' => true, 'totals' => $plan->fresh()->totals(), 'subtotal' => $item->fresh()->subtotal])
            : back()->with('success', 'Ítem actualizado.');
    }

    public function removeItem(TreatmentPlan $plan, TreatmentPlanItem $item)
    {
        abort_unless($item->treatment_plan_id === $plan->id, 404);
        $editable = $plan->isEditable();
        TreatmentPlans::removeItem($item);

        return back()->with('success', $editable ? 'Ítem quitado.' : 'Ítem cancelado (queda la constancia en el plan).');
    }

    public function toggleItem(TreatmentPlan $plan, TreatmentPlanItem $item)
    {
        abort_unless($item->treatment_plan_id === $plan->id, 404);
        TreatmentPlans::toggleDone($item, request()->boolean('done'));

        return back()->with('success', request()->boolean('done') ? "«{$item->name}» realizado." : "«{$item->name}» vuelve a pendiente.");
    }

    public function status(TreatmentPlan $plan)
    {
        $data = request()->validate([
            'status' => ['required', Rule::in(['presentado', 'aprobado', 'borrador', 'rechazado', 'cancelado'])],
            'approved_by_name' => ['nullable', 'string', 'max:150'],
            'close_reason' => ['nullable', 'string', 'max:255'],
        ]);
        TreatmentPlans::setStatus($plan, $data['status'], $data['status'] === 'aprobado' ? ($data['approved_by_name'] ?? null) : ($data['close_reason'] ?? null));

        return back()->with('success', "Plan {$plan->code}: {$plan->fresh()->status_label}.");
    }

    /** Presupuesto para el paciente (imprimir o guardar como PDF). */
    public function print(TreatmentPlan $plan)
    {
        $plan->load(['patient', 'professional.specialty', 'items']);

        return view('admin.treatment-plans.print', ['plan' => $plan, 'totals' => $plan->totals()]);
    }
}
