<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Support\Patients;

/**
 * Pacientes. El CompanyScope filtra por la clínica activa y el route model
 * binding pasa por él: un paciente de otra clínica responde 404.
 */
class PatientController extends Controller
{
    /** Pestañas de la ficha: clave => [etiqueta, ícono, disponible ya]. */
    public const TABS = [
        'resumen' => ['Resumen', 'bi-person-vcard', true],
        'odontograma' => ['Odontograma', 'bi-grid-3x3', true],
        'historia' => ['Historia clínica', 'bi-journal-medical', true],
        'tratamiento' => ['Plan de tratamiento', 'bi-clipboard2-check', false],
        'citas' => ['Citas', 'bi-calendar-event', true],
        'pagos' => ['Pagos', 'bi-cash-stack', false],
        'documentos' => ['Documentos y fotos', 'bi-images', true],
        'recordatorios' => ['Recordatorios', 'bi-bell', false],
    ];

    public function index()
    {
        $filter = request('filter');

        $patients = Patient::query()
            ->search(request('q'))
            ->when($filter === 'inactive', fn ($q) => $q->where('active', false), fn ($q) => $q->where('active', true))
            ->when($filter === 'alerts', fn ($q) => $q->withAlerts())
            ->when($filter === 'minors', fn ($q) => $q->minors())
            ->when($filter === 'birthday', fn ($q) => $q->birthdayToday())
            ->when(request('sort') === 'recent', fn ($q) => $q->latest('id'), fn ($q) => $q->orderBy('last_name')->orderBy('first_name'))
            ->paginate(20)
            ->withQueryString();

        return view('admin.patients.index', [
            'patients' => $patients,
            'filter' => $filter,
            'counts' => [
                'all' => Patient::where('active', true)->count(),
                'alerts' => Patient::where('active', true)->withAlerts()->count(),
                'minors' => Patient::where('active', true)->minors()->count(),
                'birthday' => Patient::where('active', true)->birthdayToday()->count(),
                'inactive' => Patient::where('active', false)->count(),
            ],
        ]);
    }

    /** Autocompletado (agenda, ventas…): JSON corto. */
    public function search()
    {
        return response()->json(
            Patient::where('active', true)->search(request('q'))->orderBy('last_name')->limit(10)->get()
                ->map(fn (Patient $p) => [
                    'id' => $p->id,
                    'name' => $p->full_name,
                    'detail' => collect([$p->code, $p->document_label, $p->age_label, $p->phone])->filter()->implode(' · '),
                    'alert' => $p->hasAlerts(),
                ])
        );
    }

    public function create()
    {
        return view('admin.patients.create', ['patient' => new Patient(['active' => true]), 'branches' => $this->branches()]);
    }

    public function store()
    {
        $patient = Patients::create(request()->all());

        // Desde «Nueva cita»: vuelve al alta con el paciente ya elegido.
        if (request('return') === 'appointment' && \Illuminate\Support\Facades\Route::has('appointments.create')) {
            return redirect()->route('appointments.create', array_filter(['patient_id' => $patient->id, 'start' => request('start'), 'personal_id' => request('personal_id')]))
                ->with('success', "Paciente {$patient->full_name} registrado. Ahora elige la hora.");
        }

        return redirect()->route('patients.show', $patient)->with('success', "Paciente {$patient->full_name} registrado ({$patient->code}).");
    }

    public function show(Patient $patient)
    {
        $tab = array_key_exists(request('tab'), self::TABS) ? request('tab') : 'resumen';
        $patient->load(['branch', 'creator'])->loadCount('documents');

        return view('admin.patients.show', [
            'patient' => $patient,
            'tab' => $tab,
            'documents' => $tab === 'documentos' ? $patient->documents()->with('uploader')
                ->when(request('category'), fn ($q, $c) => $q->where('category', $c))->get() : collect(),
            'recentDocuments' => $tab === 'resumen' ? $patient->documents()->limit(4)->get() : collect(),
            // Citas: solo si la clínica tiene agenda (el binding ya filtra por clínica).
            'agenda' => (bool) app(\App\Support\Tenancy::class)->company()?->planAllows('agenda') && auth()->user()->hasPermissionInCompany('appointments.view', app(\App\Support\Tenancy::class)->company()),
            // Historia clínica y odontograma: módulo historia_clinica.
            'clinical' => (bool) app(\App\Support\Tenancy::class)->company()?->planAllows('historia_clinica'),
            'consultations' => $tab === 'historia'
                ? \App\Models\Consultation::with(['professional', 'diagnoses', 'procedures'])->where('patient_id', $patient->id)->latest('started_at')->get()
                : collect(),
            'odontogramSummary' => $tab === 'resumen' ? \App\Support\Odontogram::summary($patient) : null,
            'appointments' => in_array($tab, ['citas', 'resumen'], true)
                ? \App\Models\Appointment::with(['professional', 'procedure'])->where('patient_id', $patient->id)->latest('starts_at')->limit($tab === 'citas' ? 100 : 20)->get()
                : collect(),
        ]);
    }

    public function edit(Patient $patient)
    {
        return view('admin.patients.edit', ['patient' => $patient, 'branches' => $this->branches()]);
    }

    public function update(Patient $patient)
    {
        Patients::update($patient, request()->all() + ['active' => request()->boolean('active')]);

        return redirect()->route('patients.show', $patient)->with('success', 'Datos del paciente actualizados.');
    }

    /**
     * Un paciente con documentos (y, más adelante, con historia clínica) no se
     * borra: se desactiva. Sin nada, va a la papelera (soft delete).
     */
    public function destroy(Patient $patient)
    {
        if ($patient->documents()->exists()) {
            $patient->update(['active' => false]);

            return redirect()->route('patients.show', $patient)->with('success', "{$patient->full_name} tiene documentos clínicos: quedó inactivo en lugar de eliminarse.");
        }

        $patient->delete();

        return redirect()->route('patients.index')->with('success', "Paciente {$patient->full_name} eliminado.");
    }

    private function branches()
    {
        return Branch::where('active', true)->orderBy('name')->get(['id', 'name']);
    }
}
