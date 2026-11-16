<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Personal;
use App\Support\Agenda;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Agenda en la API: mismas reglas que la web (App\Support\Agenda). */
class AppointmentController extends Controller
{
    /** Citas de un día (o rango) y los profesionales, para la vista de agenda de la app. */
    public function index(Request $request): JsonResponse
    {
        $from = Carbon::parse($request->query('from', $request->query('date', now()->toDateString())))->startOfDay();
        $to = Carbon::parse($request->query('to', $from->toDateString()))->endOfDay();

        $appointments = Appointment::with(['patient', 'professional', 'procedure'])
            ->whereBetween('starts_at', [$from, $to])
            ->when($request->query('professional'), fn ($q, $id) => $q->where('personal_id', $id))
            ->when($request->query('patient'), fn ($q, $id) => $q->where('patient_id', $id))
            ->orderBy('starts_at')->get();

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'professionals' => Personal::professionals()->get()->map(fn (Personal $p) => ['id' => $p->id, 'name' => $p->full_name, 'color' => $p->color])->values(),
            'statuses' => collect(Appointment::STATUSES)->map(fn ($s, $key) => ['key' => $key, 'label' => $s[0]])->values(),
            'data' => $appointments->map(fn (Appointment $a) => self::appointment($a))->values(),
        ]);
    }

    public function show(Appointment $appointment): JsonResponse
    {
        return response()->json(self::appointment($appointment->load(['patient', 'professional', 'procedure', 'logs.user'])) + [
            'history' => $appointment->logs->map(fn ($l) => ['description' => $l->description, 'at' => $l->created_at?->toIso8601String(), 'user' => $l->user?->name])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(self::appointment(Agenda::create($request->all())->load(['patient', 'professional', 'procedure'])), 201);
    }

    public function update(Request $request, Appointment $appointment): JsonResponse
    {
        return response()->json(self::appointment(Agenda::update($appointment, $request->all())->load(['patient', 'professional', 'procedure'])));
    }

    public function status(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Appointment::STATUSES))],
            'cancel_reason' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(self::appointment(Agenda::setStatus($appointment, $data['status'], $data['cancel_reason'] ?? null)->load(['patient', 'professional', 'procedure'])));
    }

    public function slots(Request $request): JsonResponse
    {
        $data = $request->validate([
            'personal_id' => ['required', Rule::exists('personal', 'id')->where('company_id', app(Tenancy::class)->id())],
            'date' => ['required', 'date_format:Y-m-d'],
            'duration' => ['nullable', 'integer', 'min:5', 'max:600'],
        ]);

        return response()->json(['slots' => Agenda::freeSlots(Personal::findOrFail($data['personal_id']), Carbon::parse($data['date']), (int) ($data['duration'] ?? 30))]);
    }

    /** @return array<string, mixed> */
    public static function appointment(Appointment $a): array
    {
        return [
            'id' => $a->id,
            'starts_at' => $a->starts_at->format('Y-m-d\TH:i:s'),
            'ends_at' => $a->ends_at->format('Y-m-d\TH:i:s'),
            'duration_minutes' => $a->duration_minutes,
            'status' => $a->status,
            'status_label' => $a->status_label,
            'next_statuses' => Appointment::TRANSITIONS[$a->status] ?? [],
            'title' => $a->title,
            'reason' => $a->reason,
            'notes' => $a->notes,
            'overbooked' => $a->overbooked,
            'reschedule_count' => $a->reschedule_count,
            'patient' => $a->patient ? PatientController::summary($a->patient) : null,
            'professional' => $a->professional ? ['id' => $a->professional->id, 'name' => $a->professional->full_name, 'color' => $a->professional->color] : null,
            'procedure' => $a->procedure ? ['id' => $a->procedure->id, 'name' => $a->procedure->name] : null,
            'arrived_at' => $a->arrived_at?->toIso8601String(),
            'cancel_reason' => $a->cancel_reason,
        ];
    }
}
