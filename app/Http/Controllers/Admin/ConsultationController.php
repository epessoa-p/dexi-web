<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\ConsultationDiagnosis;
use App\Models\ConsultationProcedure;
use App\Models\Patient;
use App\Models\Personal;
use App\Models\Procedure;
use App\Support\Consultations;
use App\Support\Odontogram;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

/**
 * Atención clínica: lista de consultas y el «escritorio» de la consulta
 * (notas, odontograma, diagnósticos, procedimientos, indicaciones y cierre).
 */
class ConsultationController extends Controller
{
    public function index()
    {
        $filter = request('filter', 'hoy');
        $mine = Consultations::myProfessionalId();

        $consultations = Consultation::with(['patient', 'professional'])->withCount(['diagnoses', 'procedures'])
            ->when($filter === 'hoy', fn ($q) => $q->whereDate('started_at', now()->toDateString()))
            ->when($filter === 'abiertas', fn ($q) => $q->where('status', 'abierta'))
            ->when($filter === 'mias' && $mine, fn ($q) => $q->where('personal_id', $mine))
            ->when(request('professional'), fn ($q, $id) => $q->where('personal_id', $id))
            ->latest('started_at')
            ->paginate(25)->withQueryString();

        return view('admin.consultations.index', [
            'consultations' => $consultations,
            'filter' => $filter,
            'mine' => $mine,
            'counts' => [
                'hoy' => Consultation::whereDate('started_at', now()->toDateString())->count(),
                'abiertas' => Consultation::where('status', 'abierta')->count(),
            ],
            // Lo que espera atención ahora: citas de hoy en sala de espera o en atención sin consulta.
            'waiting' => Appointment::with(['patient', 'professional', 'procedure'])->onDate(now())
                ->whereIn('status', ['en_espera', 'en_atencion', 'confirmada', 'agendada'])
                ->whereDoesntHave('consultation')->orderByRaw("case status when 'en_atencion' then 0 when 'en_espera' then 1 else 2 end")
                ->orderBy('starts_at')->limit(8)->get(),
        ]);
    }

    /** Abre la consulta desde una cita o desde la ficha del paciente. */
    public function start()
    {
        $companyId = app(\App\Support\Tenancy::class)->id();
        $data = request()->validate([
            'appointment_id' => ['nullable', Rule::exists('appointments', 'id')->where('company_id', $companyId)],
            'patient_id' => ['required_without:appointment_id', 'nullable', Rule::exists('patients', 'id')->where('company_id', $companyId)],
            'personal_id' => ['nullable', Rule::exists('personal', 'id')->where('company_id', $companyId)],
        ]);

        $appointment = ! empty($data['appointment_id']) ? Appointment::findOrFail($data['appointment_id']) : null;
        $patient = $appointment?->patient ?? Patient::findOrFail($data['patient_id']);

        $consultation = Consultations::start($patient, $appointment, $data['personal_id'] ?? null);

        return redirect()->route('consultations.show', $consultation);
    }

    public function show(Consultation $consultation)
    {
        $consultation->load(['patient', 'professional', 'appointment', 'diagnoses', 'procedures', 'closer']);
        $patient = $consultation->patient;

        return view('admin.consultations.show', [
            'consultation' => $consultation,
            'patient' => $patient,
            'procedures' => Procedure::with('specialty')->where('active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'professionals' => Personal::professionals()->get(),
            'previous' => Consultation::with('professional')->where('patient_id', $patient->id)->whereKeyNot($consultation->id)
                ->latest('started_at')->limit(5)->get(),
            'marks' => Consultations::ODONTOGRAM_BY_CODE,
            // Pendientes del plan de tratamiento aprobado (si la clínica tiene el módulo).
            'openPlan' => app(\App\Support\Tenancy::class)->company()?->planAllows('tratamientos')
                ? \App\Models\TreatmentPlan::where('patient_id', $patient->id)->whereIn('status', ['borrador', 'presentado'])->latest()->first() : null,
            'planItems' => app(\App\Support\Tenancy::class)->company()?->planAllows('tratamientos')
                ? \App\Support\TreatmentPlans::pendingFor($patient) : collect(),
        ]);
    }

    /** Guardado de notas (también automático, por fetch). */
    public function notes(Consultation $consultation)
    {
        Consultations::saveNotes($consultation, request()->all());

        return request()->expectsJson()
            ? response()->json(['ok' => true, 'saved_at' => now()->format('H:i')])
            : back()->with('success', 'Consulta guardada.');
    }

    /** Cambiar el profesional (solo abierta). */
    public function professional(Consultation $consultation)
    {
        Consultations::ensureOpen($consultation);
        $data = request()->validate(['personal_id' => ['required', Rule::exists('personal', 'id')->where('company_id', $consultation->company_id)->where('attends_patients', true)]]);
        $consultation->update($data);

        return back()->with('success', 'Profesional actualizado.');
    }

    public function addDiagnosis(Consultation $consultation)
    {
        $diagnosis = Consultations::addDiagnosis($consultation, request()->all());

        return back()->with('success', "Diagnóstico agregado: {$diagnosis->label}.");
    }

    public function removeDiagnosis(Consultation $consultation, ConsultationDiagnosis $diagnosis)
    {
        abort_unless($diagnosis->consultation_id === $consultation->id, 404);
        Consultations::ensureOpen($consultation);
        $diagnosis->delete();

        return back()->with('success', 'Diagnóstico quitado.');
    }

    public function addProcedure(Consultation $consultation)
    {
        $item = Consultations::addProcedure($consultation, request()->all());

        return back()->with('success', "Registrado: {$item->name}" . ($item->tooth ? " (pieza {$item->tooth})" : '') . '.');
    }

    public function removeProcedure(Consultation $consultation, ConsultationProcedure $item)
    {
        abort_unless($item->consultation_id === $consultation->id, 404);
        Consultations::ensureOpen($consultation);
        if ($item->sale_item_id) {
            return back()->withErrors(['error' => "«{$item->name}» ya fue cobrado: para quitarlo, primero anula la venta."]);
        }
        \App\Support\TreatmentPlans::unlink($item);
        // Los insumos que había descontado vuelven al stock.
        \App\Support\Stock::restore($item, 'reversal', "Se quitó «{$item->name}» de {$consultation->code}");
        $item->delete();

        return back()->with('success', 'Procedimiento quitado (el odontograma no cambia: corrígelo ahí si hace falta).');
    }

    /** «Hecho hoy» de un ítem del plan de tratamiento aprobado. */
    public function doPlanItem(Consultation $consultation, \App\Models\TreatmentPlanItem $item)
    {
        $done = \App\Support\TreatmentPlans::doInConsultation($consultation, $item);

        return back()->with('success', "Registrado del plan: {$done->name}" . ($done->tooth ? " (pieza {$done->tooth})" : '') . '.');
    }

    public function close(Consultation $consultation)
    {
        Consultations::saveNotes($consultation, request()->only(['reason', 'history', 'exam', 'plan', 'indications', 'next_visit']));
        Consultations::close($consultation);

        return redirect()->route('consultations.show', $consultation)->with('success', "Consulta {$consultation->code} cerrada y firmada.");
    }

    public function addendum(Consultation $consultation)
    {
        Consultations::addendum($consultation, (string) request('addendum'));

        return back()->with('success', 'Nota de corrección agregada.');
    }

    /** Receta / indicaciones para imprimir. */
    public function prescription(Consultation $consultation)
    {
        return view('admin.consultations.prescription', [
            'consultation' => $consultation->load(['patient', 'professional.specialty']),
        ]);
    }
}
