<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Admin\OdontogramController as WebOdontogram;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\ConsultationDiagnosis;
use App\Models\ConsultationProcedure;
use App\Models\OdontogramEntry;
use App\Models\Patient;
use App\Models\Personal;
use App\Models\Procedure;
use App\Models\TreatmentPlanItem;
use App\Support\Consultations;
use App\Support\Odontogram;
use App\Support\Stock;
use App\Support\Tenancy;
use App\Support\TreatmentPlans;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Atención clínica en la API (para la app en el sillón): consultas,
 * diagnósticos, procedimientos, odontograma y «hecho hoy» del plan. Mismas
 * reglas que la web (App\Support\Consultations y Odontogram).
 */
class ClinicalController extends Controller
{
    /** Catálogos para la consulta y el odontograma (se piden una vez). */
    public function options(): JsonResponse
    {
        return response()->json([
            'diagnoses' => collect(ConsultationDiagnosis::CATALOG)->map(fn ($d, $key) => ['key' => $key, 'label' => $d[0], 'icd' => $d[1] ?? null])->values(),
            'surfaces' => collect(Odontogram::SURFACES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'conditions' => Odontogram::catalog(),
            'rows' => ['permanente' => Odontogram::rows('permanente'), 'temporal' => Odontogram::rows('temporal')],
            // Procedimiento → condición que marca en el odontograma al registrarlo.
            'marks' => Consultations::ODONTOGRAM_BY_CODE,
            'professionals' => Personal::professionals()->get()->map(fn (Personal $p) => ['id' => $p->id, 'name' => $p->full_name, 'color' => $p->color])->values(),
        ]);
    }

    /** Consultas: de hoy, abiertas o mías; y quién espera ser atendido. */
    public function index(Request $request): JsonResponse
    {
        $filter = in_array($request->query('filter'), ['hoy', 'abiertas', 'mias'], true) ? $request->query('filter') : 'hoy';
        $mine = Consultations::myProfessionalId();

        $consultations = Consultation::with(['patient', 'professional'])->withCount(['diagnoses', 'procedures'])
            ->when($filter === 'hoy', fn ($q) => $q->whereDate('started_at', now()->toDateString()))
            ->when($filter === 'abiertas', fn ($q) => $q->where('status', 'abierta'))
            ->when($filter === 'mias', fn ($q) => $q->where('personal_id', $mine ?? 0))
            ->latest('started_at')->limit(50)->get();

        return response()->json([
            'filter' => $filter,
            'data' => $consultations->map(fn (Consultation $c) => self::summary($c))->values(),
            'waiting' => Appointment::with(['patient', 'professional', 'procedure'])->onDate(now())
                ->whereIn('status', ['en_espera', 'en_atencion', 'confirmada', 'agendada'])->whereDoesntHave('consultation')
                ->orderByRaw("case status when 'en_atencion' then 0 when 'en_espera' then 1 else 2 end")->orderBy('starts_at')->limit(10)->get()
                ->map(fn (Appointment $a) => AppointmentController::appointment($a))->values(),
        ]);
    }

    /** Abrir la consulta desde una cita (o retomarla) o desde el paciente. */
    public function start(Request $request): JsonResponse
    {
        $companyId = app(Tenancy::class)->id();
        $data = $request->validate([
            'appointment_id' => ['nullable', Rule::exists('appointments', 'id')->where('company_id', $companyId)],
            'patient_id' => ['required_without:appointment_id', 'nullable', Rule::exists('patients', 'id')->where('company_id', $companyId)],
            'personal_id' => ['nullable', Rule::exists('personal', 'id')->where('company_id', $companyId)],
        ], ['patient_id.required_without' => 'Elige al paciente.']);
        $appointment = ! empty($data['appointment_id']) ? Appointment::findOrFail($data['appointment_id']) : null;
        $patient = $appointment?->patient ?? Patient::findOrFail($data['patient_id']);

        return response()->json($this->detail(Consultations::start($patient, $appointment, $data['personal_id'] ?? null)), 201);
    }

    public function show(Consultation $consultation): JsonResponse
    {
        return response()->json($this->detail($consultation));
    }

    /** Guardar las notas (motivo, anamnesis, examen, plan, indicaciones, próxima visita). */
    public function notes(Request $request, Consultation $consultation): JsonResponse
    {
        Consultations::saveNotes($consultation, $request->only(['reason', 'history', 'exam', 'plan', 'indications', 'next_visit']));
        if ($request->filled('personal_id')) {
            $consultation->update($request->validate(['personal_id' => [Rule::exists('personal', 'id')->where('company_id', $consultation->company_id)->where('attends_patients', true)]]));
        }

        return response()->json($this->detail($consultation) + ['saved_at' => now()->format('H:i')]);
    }

    public function addDiagnosis(Request $request, Consultation $consultation): JsonResponse
    {
        Consultations::addDiagnosis($consultation, $request->all());

        return response()->json($this->detail($consultation), 201);
    }

    public function removeDiagnosis(Consultation $consultation, ConsultationDiagnosis $diagnosis): JsonResponse
    {
        abort_unless($diagnosis->consultation_id === $consultation->id, 404);
        Consultations::ensureOpen($consultation);
        $diagnosis->delete();

        return response()->json($this->detail($consultation));
    }

    public function addProcedure(Request $request, Consultation $consultation): JsonResponse
    {
        Consultations::addProcedure($consultation, $request->all());

        return response()->json($this->detail($consultation), 201);
    }

    public function removeProcedure(Consultation $consultation, ConsultationProcedure $item): JsonResponse
    {
        abort_unless($item->consultation_id === $consultation->id, 404);
        Consultations::ensureOpen($consultation);
        if ($item->sale_item_id) {
            throw ValidationException::withMessages(['item' => "«{$item->name}» ya fue cobrado: para quitarlo, primero anula la venta."]);
        }
        TreatmentPlans::unlink($item);
        Stock::restore($item, 'reversal', "Se quitó «{$item->name}» de {$consultation->code}");
        $item->delete();

        return response()->json($this->detail($consultation));
    }

    /** «Hecho hoy» de un ítem del plan de tratamiento aprobado. */
    public function doPlanItem(Consultation $consultation, TreatmentPlanItem $item): JsonResponse
    {
        abort_unless($item->plan?->company_id === $consultation->company_id, 404);
        TreatmentPlans::doInConsultation($consultation, $item);

        return response()->json($this->detail($consultation), 201);
    }

    public function close(Request $request, Consultation $consultation): JsonResponse
    {
        if ($consultation->isOpen()) {
            Consultations::saveNotes($consultation, $request->only(['reason', 'history', 'exam', 'plan', 'indications', 'next_visit']));
        }
        Consultations::close($consultation);

        return response()->json($this->detail($consultation));
    }

    public function addendum(Request $request, Consultation $consultation): JsonResponse
    {
        Consultations::addendum($consultation, (string) $request->input('addendum'));

        return response()->json($this->detail($consultation));
    }

    /** Historia clínica del paciente: sus consultas, de la más reciente a la más antigua. */
    public function history(Patient $patient): JsonResponse
    {
        $consultations = Consultation::with(['professional', 'diagnoses', 'procedures'])->where('patient_id', $patient->id)->latest('started_at')->limit(100)->get();

        return response()->json(['data' => $consultations->map(fn (Consultation $c) => self::summary($c) + [
            'diagnoses' => $c->diagnoses->map(fn ($d) => self::diagnosis($d))->values(),
            'procedures' => $c->procedures->map(fn ($p) => self::procedure($p))->values(),
            'indications' => $c->indications,
        ])->values()]);
    }

    // ── Odontograma ────────────────────────────────────────────────────────

    public function odontogram(Patient $patient): JsonResponse
    {
        return response()->json(WebOdontogram::payload($patient) + ['dentition' => Odontogram::defaultDentition($patient)]);
    }

    public function recordOdontogram(Request $request, Patient $patient): JsonResponse
    {
        return app(WebOdontogram::class)->store($patient);
    }

    public function resolveOdontogram(Patient $patient, OdontogramEntry $entry): JsonResponse
    {
        return app(WebOdontogram::class)->resolve($patient, $entry);
    }

    public function tooth(Patient $patient, int $tooth): JsonResponse
    {
        return app(WebOdontogram::class)->tooth($patient, $tooth);
    }

    // ── Presentación ───────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public static function summary(Consultation $c): array
    {
        return [
            'id' => $c->id,
            'code' => $c->code,
            'status' => $c->status,
            'open' => $c->isOpen(),
            'started_at' => $c->started_at?->format('Y-m-d\TH:i:s'),
            'closed_at' => $c->closed_at?->format('Y-m-d\TH:i:s'),
            'reason' => $c->reason,
            'patient' => $c->patient ? PatientController::summary($c->patient) : null,
            'professional' => $c->professional ? ['id' => $c->professional->id, 'name' => $c->professional->full_name, 'color' => $c->professional->color] : null,
            'diagnoses_count' => $c->diagnoses_count ?? $c->diagnoses()->count(),
            'procedures_count' => $c->procedures_count ?? $c->procedures()->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Consultation $c): array
    {
        $c->refresh()->load(['patient', 'professional', 'appointment', 'diagnoses', 'procedures', 'closer']);
        $company = app(Tenancy::class)->company();
        $plans = $company?->planAllows('tratamientos');

        return self::summary($c) + $c->only(['history', 'exam', 'plan', 'indications', 'next_visit', 'addendum']) + [
            'appointment_id' => $c->appointment_id,
            'closed_by' => $c->closer?->name,
            'diagnoses' => $c->diagnoses->map(fn ($d) => self::diagnosis($d))->values(),
            'procedures' => $c->procedures->map(fn ($p) => self::procedure($p))->values(),
            'plan_items' => $plans ? TreatmentPlans::pendingFor($c->patient)->map(fn (TreatmentPlanItem $i) => [
                'id' => $i->id, 'name' => $i->name, 'tooth' => $i->tooth, 'surfaces' => $i->surfaces, 'plan' => $i->plan?->code, 'phase' => $i->phase,
            ])->values() : [],
            'billed' => round((float) $c->procedures->whereNotNull('sale_item_id')->sum(fn ($p) => $p->price * $p->quantity), 2),
            'to_bill' => round((float) $c->procedures->whereNull('sale_item_id')->sum(fn ($p) => $p->price * $p->quantity), 2),
            'odontogram' => WebOdontogram::payload($c->patient) + ['dentition' => Odontogram::defaultDentition($c->patient)],
            'previous' => Consultation::with('professional')->where('patient_id', $c->patient_id)->whereKeyNot($c->id)->latest('started_at')->limit(5)->get()
                ->map(fn (Consultation $p) => self::summary($p))->values(),
        ];
    }

    /** @return array<string, mixed> */
    private static function diagnosis(ConsultationDiagnosis $d): array
    {
        return ['id' => $d->id, 'code' => $d->code, 'label' => $d->label, 'icd' => ConsultationDiagnosis::CATALOG[$d->code][1] ?? null,
            'tooth' => $d->tooth, 'surfaces' => $d->surfaces, 'notes' => $d->notes];
    }

    /** @return array<string, mixed> */
    private static function procedure(ConsultationProcedure $p): array
    {
        return ['id' => $p->id, 'procedure_id' => $p->procedure_id, 'name' => $p->name, 'tooth' => $p->tooth, 'surfaces' => $p->surfaces,
            'quantity' => (int) $p->quantity, 'price' => (float) $p->price, 'notes' => $p->notes, 'billed' => $p->sale_item_id !== null];
    }
}
