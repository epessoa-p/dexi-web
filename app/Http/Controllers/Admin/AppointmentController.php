<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\Personal;
use App\Models\Procedure;
use App\Models\Schedule;
use App\Models\ScheduleBlock;
use App\Support\Agenda;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

/**
 * Agenda: calendario (día/semana/mes/lista), alta y edición de citas,
 * reprogramar arrastrando, estados y la vista «Hoy» (sala de espera).
 */
class AppointmentController extends Controller
{
    public function index()
    {
        return view('admin.appointments.index', [
            'professionals' => Personal::professionals()->with('specialty')->get(),
            'opensAt' => $this->setting('clinic.opens_at', '08:00'),
            'closesAt' => $this->setting('clinic.closes_at', '18:00'),
            'hiddenDays' => array_values(array_filter([
                $this->setting('clinic.works_sunday', false) ? null : 0,
                $this->setting('clinic.works_saturday', true) ? null : 6,
            ], fn ($d) => $d !== null)),
        ]);
    }

    /** Eventos para el calendario (citas + bloqueos) del rango visible. */
    public function events(): JsonResponse
    {
        $from = request('start') ? self::wallClock(request('start')) : now()->startOfWeek();
        $to = request('end') ? self::wallClock(request('end')) : now()->endOfWeek();
        $professionals = array_filter((array) request('professionals', []));
        $showCancelled = request()->boolean('cancelled');

        $appointments = Appointment::with(['patient', 'professional', 'procedure.specialty'])
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)
            ->when($professionals, fn ($q) => $q->whereIn('personal_id', $professionals))
            ->when(! $showCancelled, fn ($q) => $q->whereNotIn('status', ['cancelada', 'no_asistio']))
            ->get();

        $events = $appointments->map(fn (Appointment $a) => [
            'id' => $a->id,
            'title' => $a->patient?->full_name,
            'start' => $a->starts_at->format('Y-m-d\TH:i:s'),
            'end' => $a->ends_at->format('Y-m-d\TH:i:s'),
            'backgroundColor' => $a->professional?->color,
            'borderColor' => $a->professional?->color,
            'classNames' => ['appt', "appt-{$a->status}"],
            'editable' => ! $a->isClosed(),
            'extendedProps' => [
                'procedure' => $a->title,
                'professional' => $a->professional?->full_name,
                'status' => $a->status,
                'statusLabel' => $a->status_label,
                'alert' => (bool) $a->patient?->hasAlerts(),
                'overbooked' => $a->overbooked,
                'url' => route('appointments.show', $a),
            ],
        ]);

        $blocks = ScheduleBlock::with('personal')->where('starts_at', '<', $to)->where('ends_at', '>', $from)
            ->when($professionals, fn ($q) => $q->where(fn ($w) => $w->whereNull('personal_id')->orWhereIn('personal_id', $professionals)))
            ->get()
            ->map(fn (ScheduleBlock $b) => [
                'start' => $b->starts_at->format('Y-m-d\TH:i:s'),
                'end' => $b->ends_at->format('Y-m-d\TH:i:s'),
                'display' => 'background',
                'classNames' => ['agenda-block'],
                'title' => $b->reason . ($b->personal ? " · {$b->personal->full_name}" : ''),
            ]);

        return response()->json($events->concat($blocks)->values());
    }

    /** Horas libres de un profesional en un día (para el alta). */
    public function slots(): JsonResponse
    {
        $data = request()->validate([
            'personal_id' => ['required', Rule::exists('personal', 'id')->where('company_id', app(Tenancy::class)->id())],
            'date' => ['required', 'date_format:Y-m-d'],
            'duration' => ['nullable', 'integer', 'min:5', 'max:600'],
            'ignore' => ['nullable', 'integer'],
        ]);

        $professional = Personal::findOrFail($data['personal_id']);
        $minutes = (int) ($data['duration'] ?? $this->setting('clinic.appointment_minutes', 30));
        $schedule = Schedule::where('personal_id', $professional->id)->where('weekday', Carbon::parse($data['date'])->isoWeekday())->orderBy('starts_at')->get();

        return response()->json([
            'slots' => Agenda::freeSlots($professional, Carbon::parse($data['date']), $minutes, $data['ignore'] ?? null),
            'schedule' => $schedule->map(fn (Schedule $s) => "{$s->start_label}–{$s->end_label}")->values(),
            'has_schedule' => Schedule::where('personal_id', $professional->id)->exists(),
        ]);
    }

    public function create()
    {
        $patient = request('patient_id') ? Patient::find(request('patient_id')) : null;

        return view('admin.appointments.create', $this->formData(new Appointment([
            'patient_id' => $patient?->id,
            'personal_id' => request('personal_id') ?: Personal::professionals()->value('id'),
            'starts_at' => request('start') ? Carbon::parse(request('start')) : null,
        ]), $patient));
    }

    public function store()
    {
        $appointment = Agenda::create(request()->all());

        return redirect()->route('appointments.index', ['date' => $appointment->starts_at->toDateString()])
            ->with('success', "Cita agendada: {$appointment->patient->full_name}, {$appointment->starts_at->locale('es')->translatedFormat('l j \d\e F, H:i')}.");
    }

    public function show(Appointment $appointment)
    {
        $appointment->load(['patient', 'professional.specialty', 'procedure', 'branch', 'creator', 'logs.user']);

        return view('admin.appointments.show', [
            'appointment' => $appointment,
            'history' => Appointment::with(['professional', 'procedure'])->where('patient_id', $appointment->patient_id)
                ->whereKeyNot($appointment->id)->latest('starts_at')->limit(5)->get(),
        ]);
    }

    public function edit(Appointment $appointment)
    {
        abort_if($appointment->isClosed(), 403, 'Esta cita ya está cerrada.');

        return view('admin.appointments.edit', $this->formData($appointment, $appointment->patient));
    }

    public function update(Appointment $appointment)
    {
        Agenda::update($appointment, request()->all());

        return redirect()->route('appointments.show', $appointment)->with('success', 'Cita actualizada.');
    }

    /** Arrastrar o estirar en el calendario. */
    public function move(Appointment $appointment): JsonResponse
    {
        $data = request()->validate(['start' => ['required', 'date'], 'end' => ['nullable', 'date', 'after:start'], 'force' => ['nullable', 'boolean']]);
        Agenda::move($appointment, self::wallClock($data['start']),
            isset($data['end']) ? self::wallClock($data['end']) : null, (bool) ($data['force'] ?? false));

        return response()->json(['ok' => true, 'message' => 'Cita reprogramada: ' . $appointment->fresh()->starts_at->locale('es')->translatedFormat('D j, H:i') . '.']);
    }

    public function status(Appointment $appointment)
    {
        $data = request()->validate([
            'status' => ['required', Rule::in(array_keys(Appointment::STATUSES))],
            'cancel_reason' => ['nullable', 'string', 'max:255'],
        ]);
        Agenda::setStatus($appointment, $data['status'], $data['cancel_reason'] ?? null);

        if (request()->expectsJson()) {
            return response()->json(['ok' => true, 'status' => $appointment->status, 'label' => $appointment->status_label]);
        }

        return back()->with('success', "{$appointment->patient->full_name}: {$appointment->status_label}.");
    }

    /** Hoy: tablero por estado (por llegar, en sala de espera, en atención, atendidas). */
    public function today()
    {
        $date = request('date') ? Carbon::parse(request('date')) : now();
        $professional = request('professional');

        $appointments = Appointment::with(['patient', 'professional', 'procedure', 'consultation'])->onDate($date)
            ->when($professional, fn ($q) => $q->where('personal_id', $professional))
            ->orderBy('starts_at')->get();

        return view('admin.appointments.today', [
            'date' => $date,
            'columns' => [
                'pending' => $appointments->whereIn('status', ['agendada', 'confirmada']),
                'waiting' => $appointments->where('status', 'en_espera')->sortBy('arrived_at'),
                'attending' => $appointments->where('status', 'en_atencion'),
                'done' => $appointments->where('status', 'atendida'),
                'missed' => $appointments->whereIn('status', ['cancelada', 'no_asistio']),
            ],
            'professionals' => Personal::professionals()->get(),
            'professional' => $professional,
        ]);
    }

    /** @return array<string, mixed> */
    private function formData(Appointment $appointment, ?Patient $patient): array
    {
        return [
            'appointment' => $appointment,
            'patient' => $patient,
            'professionals' => Personal::professionals()->with('specialty')->get(),
            'procedures' => Procedure::with('specialty')->where('active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'branches' => Branch::where('active', true)->orderBy('name')->get(['id', 'name']),
            'defaultMinutes' => (int) $this->setting('clinic.appointment_minutes', 30),
        ];
    }

    /**
     * El calendario trabaja en «hora de pared» de la clínica: las fechas viajan sin
     * zona (o con una Z que se ignora), así se ve igual desde cualquier navegador.
     */
    private static function wallClock(string $value): Carbon
    {
        return Carbon::parse(substr(str_replace(' ', 'T', $value), 0, 19));
    }

    private function setting(string $key, mixed $default): mixed
    {
        return settings()->get($key, $default, app(Tenancy::class)->id()) ?? $default;
    }
}
