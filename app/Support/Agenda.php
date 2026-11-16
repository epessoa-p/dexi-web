<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Personal;
use App\Models\Procedure;
use App\Models\Schedule;
use App\Models\ScheduleBlock;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de la agenda en UN solo lugar (web y API):
 *
 * - Un profesional no atiende dos citas a la vez, ni un paciente está en dos.
 * - No se agenda en un bloqueo (vacaciones, feriado) ni fuera del horario del
 *   profesional, salvo que se acepte como **sobreturno** (`force`).
 * - Reprogramar y cambiar de estado queda en el historial de la cita.
 */
final class Agenda
{
    /**
     * Valida el alta o la edición y devuelve los atributos listos.
     *
     * @return array<string, mixed>
     */
    public static function validate(array $input, ?Appointment $appointment = null): array
    {
        $companyId = app(Tenancy::class)->id();
        $exists = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $companyId)->whereNull('deleted_at');

        $data = validator($input, [
            'patient_id' => ['required', $exists('patients')],
            'personal_id' => ['required', $exists('personal')->where('attends_patients', true)->where('active', true)],
            'procedure_id' => ['nullable', $exists('procedures')],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'duration' => ['nullable', 'integer', 'min:5', 'max:600'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'force' => ['nullable', 'boolean'],
        ], [
            'personal_id.exists' => 'Elige un profesional que atienda pacientes.',
            'patient_id.required' => 'Elige al paciente.',
        ], [
            'patient_id' => 'paciente', 'personal_id' => 'profesional', 'procedure_id' => 'procedimiento',
            'date' => 'fecha', 'time' => 'hora', 'duration' => 'duración', 'reason' => 'motivo',
        ])->validate();

        $procedure = ! empty($data['procedure_id']) ? Procedure::find($data['procedure_id']) : null;
        $minutes = (int) ($data['duration'] ?? $procedure?->duration_minutes ?? settings()->get('clinic.appointment_minutes', 30, app(Tenancy::class)->id()));
        $starts = Carbon::createFromFormat('Y-m-d H:i', "{$data['date']} {$data['time']}");
        $ends = $starts->copy()->addMinutes($minutes);

        if (! $appointment && $starts->lt(now()->subMinutes(30))) {
            throw ValidationException::withMessages(['time' => 'Esa hora ya pasó: elige una hora futura.']);
        }

        $overbooked = self::check((int) $data['personal_id'], (int) $data['patient_id'], $starts, $ends, (bool) ($data['force'] ?? false), $appointment?->id);

        return [
            'patient_id' => (int) $data['patient_id'],
            'personal_id' => (int) $data['personal_id'],
            'procedure_id' => $procedure?->id,
            'branch_id' => $data['branch_id'] ?? null,
            'reason' => trim((string) ($data['reason'] ?? '')) ?: null,
            'notes' => $data['notes'] ?? null,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'overbooked' => $overbooked,
        ];
    }

    /**
     * Verifica choques. Los duros (bloqueo, mismo paciente) siempre frenan;
     * el choque con otra cita del profesional y el fuera de horario se pueden
     * forzar como sobreturno. Devuelve si quedó como sobreturno.
     */
    public static function check(int $personalId, int $patientId, CarbonInterface $starts, CarbonInterface $ends, bool $force, ?int $ignoreId = null): bool
    {
        $overlap = fn ($q) => $q->where('starts_at', '<', $ends)->where('ends_at', '>', $starts)
            ->when($ignoreId, fn ($w) => $w->whereKeyNot($ignoreId))->active();

        $block = ScheduleBlock::where('starts_at', '<', $ends)->where('ends_at', '>', $starts)
            ->where(fn ($q) => $q->whereNull('personal_id')->orWhere('personal_id', $personalId))->first();
        if ($block) {
            throw ValidationException::withMessages(['time' => "La agenda está bloqueada: {$block->reason} ({$block->starts_at->format('d/m H:i')} – {$block->ends_at->format('d/m H:i')})."]);
        }

        $patientClash = Appointment::where('patient_id', $patientId)->tap($overlap)->with('professional')->first();
        if ($patientClash) {
            throw ValidationException::withMessages(['time' => "El paciente ya tiene una cita a esa hora ({$patientClash->starts_at->format('H:i')} con {$patientClash->professional?->full_name})."]);
        }

        $problems = [];
        $clash = Appointment::where('personal_id', $personalId)->tap($overlap)->with('patient')->first();
        if ($clash) {
            $problems[] = "ya atiende a {$clash->patient?->full_name} de {$clash->starts_at->format('H:i')} a {$clash->ends_at->format('H:i')}";
        }
        if (! self::withinSchedule($personalId, $starts, $ends)) {
            $problems[] = 'es fuera de su horario de atención';
        }

        if ($problems && ! $force) {
            throw ValidationException::withMessages([
                'force' => 'A esa hora el profesional ' . implode(' y ', $problems) . '. Marca «Agendar como sobreturno» para agendar igual.',
            ]);
        }

        return $problems !== [];
    }

    /** ¿Cae dentro de algún bloque de su horario semanal? Sin horario cargado, todo vale. */
    public static function withinSchedule(int $personalId, CarbonInterface $starts, CarbonInterface $ends): bool
    {
        $schedules = Schedule::where('personal_id', $personalId)->get();
        if ($schedules->isEmpty()) {
            return true;
        }
        if (! $starts->isSameDay($ends->copy()->subSecond())) {
            return false;
        }

        // «HH:MM» en los dos lados: la base puede devolver la hora con o sin segundos.
        $from = $starts->format('H:i');
        $to = $ends->format('H:i');

        return $schedules->where('weekday', $starts->isoWeekday())
            ->contains(fn (Schedule $s) => $s->start_label <= $from && $s->end_label >= $to);
    }

    public static function create(array $input): Appointment
    {
        $data = self::validate($input);

        return DB::transaction(function () use ($data) {
            $appointment = Appointment::create($data + ['status' => 'agendada', 'created_by' => auth()->id()]);
            self::log($appointment, 'creada', note: $appointment->overbooked ? 'sobreturno' : null);

            return $appointment;
        });
    }

    /** Edición completa (paciente, profesional, procedimiento, hora). */
    public static function update(Appointment $appointment, array $input): Appointment
    {
        if ($appointment->isClosed()) {
            throw ValidationException::withMessages(['time' => 'Esta cita ya está cerrada: no se puede cambiar.']);
        }

        $data = self::validate($input, $appointment);

        return DB::transaction(function () use ($appointment, $data) {
            $before = $appointment->starts_at;
            $beforeProfessional = $appointment->professional;
            $appointment->update($data);

            if (! $before->equalTo($data['starts_at'])) {
                $appointment->increment('reschedule_count');
                self::log($appointment, 'reprogramada', self::when($before), self::when($data['starts_at']));
            }
            if ($beforeProfessional?->id !== $data['personal_id']) {
                self::log($appointment, 'profesional', $beforeProfessional?->full_name, $appointment->fresh('professional')->professional?->full_name);
            }

            return $appointment;
        });
    }

    /** Reprogramar arrastrando en el calendario: misma duración salvo que se estire. */
    public static function move(Appointment $appointment, CarbonInterface $starts, ?CarbonInterface $ends, bool $force = false): Appointment
    {
        if ($appointment->isClosed()) {
            throw ValidationException::withMessages(['time' => 'Esta cita ya está cerrada: no se puede mover.']);
        }

        $ends ??= $starts->copy()->addMinutes($appointment->duration_minutes);
        $overbooked = self::check($appointment->personal_id, $appointment->patient_id, $starts, $ends, $force, $appointment->id);

        return DB::transaction(function () use ($appointment, $starts, $ends, $overbooked) {
            $before = $appointment->starts_at;
            $appointment->update(['starts_at' => $starts, 'ends_at' => $ends, 'overbooked' => $overbooked]);
            if (! $before->equalTo($starts)) {
                $appointment->increment('reschedule_count');
                self::log($appointment, 'reprogramada', self::when($before), self::when($starts));
            }

            return $appointment;
        });
    }

    /** Cambio de estado con su hora (llegó, pasó, terminó…). */
    public static function setStatus(Appointment $appointment, string $status, ?string $reason = null): Appointment
    {
        if (! $appointment->canMoveTo($status)) {
            throw ValidationException::withMessages(['status' => "Una cita «{$appointment->status_label}» no puede pasar a «" . (Appointment::STATUSES[$status][0] ?? $status) . '».']);
        }
        if ($status === 'cancelada' && blank($reason)) {
            throw ValidationException::withMessages(['cancel_reason' => 'Escribe el motivo de la cancelación.']);
        }

        $stamp = [
            'confirmada' => ['confirmed_at' => now()],
            'en_espera' => ['arrived_at' => $appointment->arrived_at ?? now()],
            'en_atencion' => ['started_at' => $appointment->started_at ?? now(), 'arrived_at' => $appointment->arrived_at ?? now()],
            'atendida' => ['finished_at' => now()],
            'cancelada' => ['cancelled_at' => now(), 'cancel_reason' => $reason],
            'agendada' => ['cancelled_at' => null, 'cancel_reason' => null],
        ][$status] ?? [];

        return DB::transaction(function () use ($appointment, $status, $stamp, $reason) {
            $from = $appointment->status;
            $appointment->update(['status' => $status] + $stamp);
            self::log($appointment, 'estado', $from, $status, $status === 'cancelada' ? $reason : null);

            return $appointment;
        });
    }

    /**
     * Horas libres de un profesional en un día, para proponer en el alta.
     *
     * @return Collection<int, string> «HH:MM»
     */
    public static function freeSlots(Personal $professional, CarbonInterface $day, int $minutes, ?int $ignoreId = null): Collection
    {
        $day = Carbon::parse($day)->startOfDay();
        $hasSchedule = Schedule::where('personal_id', $professional->id)->exists();
        $schedules = Schedule::where('personal_id', $professional->id)->where('weekday', $day->isoWeekday())->orderBy('starts_at')->get();

        // Con horario cargado, solo sus bloques de ese día (si no atiende ese día, nada).
        // Sin horario cargado: el horario general de la clínica.
        $ranges = $hasSchedule
            ? $schedules->map(fn (Schedule $s) => [$s->starts_at, $s->ends_at])
            : collect([[settings()->get('clinic.opens_at', '08:00', app(Tenancy::class)->id()), settings()->get('clinic.closes_at', '19:00', app(Tenancy::class)->id())]]);

        // Todo se compara en «hora de pared» (texto Y-m-d H:i:s): lo mismo que guarda la base.
        $wall = fn ($date) => $date->format('Y-m-d H:i:s');
        $busy = Appointment::where('personal_id', $professional->id)->active()->onDate($day)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->get(['starts_at', 'ends_at'])
            ->map(fn ($a) => [$wall($a->starts_at), $wall($a->ends_at)]);
        $blocks = ScheduleBlock::where('starts_at', '<', $day->copy()->endOfDay())->where('ends_at', '>', $day)
            ->where(fn ($q) => $q->whereNull('personal_id')->orWhere('personal_id', $professional->id))->get()
            ->map(fn ($b) => [$wall($b->starts_at), $wall($b->ends_at)]);
        $taken = $busy->concat($blocks);
        $now = $wall(now());

        $step = 15;
        $slots = collect();
        foreach ($ranges as [$from, $to]) {
            $cursor = $day->copy()->setTimeFromTimeString($from);
            $limit = $day->copy()->setTimeFromTimeString($to);
            while ($cursor->copy()->addMinutes($minutes)->lte($limit)) {
                [$start, $end] = [$wall($cursor), $wall($cursor->copy()->addMinutes($minutes))];
                $isTaken = $taken->contains(fn ($range) => $range[0] < $end && $range[1] > $start);
                if (! $isTaken && $start > $now) {
                    $slots->push($cursor->format('H:i'));
                }
                $cursor->addMinutes($step);
            }
        }

        return $slots->unique()->values();
    }

    private static function log(Appointment $appointment, string $action, ?string $from = null, ?string $to = null, ?string $note = null): void
    {
        $appointment->logs()->create(['action' => $action, 'from_value' => $from, 'to_value' => $to, 'note' => $note, 'user_id' => auth()->id()]);
    }

    private static function when(CarbonInterface $date): string
    {
        return $date->locale('es')->translatedFormat('D d/m H:i');
    }
}
