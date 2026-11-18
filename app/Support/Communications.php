<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Communication;
use App\Models\Company;
use App\Models\MessageTemplate;
use App\Models\Patient;
use App\Models\PatientFollowup;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Comunicaciones por WhatsApp con enlace wa.me: Dexi arma la lista del día con
 * el mensaje ya escrito, la recepcionista toca «Enviar», WhatsApp se abre con el
 * texto listo y queda registrado (para no escribirle dos veces).
 *
 * Tipos: recordatorio de cita · cumpleaños · seguimiento (controles programados)
 *        · inasistencia (no vino y no reagendó) · saldo vencido.
 */
class Communications
{
    /** tipo => [etiqueta, ícono, color, módulo del plan que lo habilita (null = siempre), texto sugerido] */
    public const KINDS = [
        'recordatorio' => ['Recordatorio de cita', 'bi-calendar-check', '#0E9F9A', 'agenda',
            'Hola {nombre} 👋, te recordamos tu cita en {clinica} el {fecha} a las {hora} con {profesional}. Responde SÍ para confirmar o escríbenos si necesitas cambiarla. ¡Te esperamos!'],
        'cumpleanos' => ['Cumpleaños', 'bi-cake2', '#db2777', null,
            '¡Feliz cumpleaños, {nombre}! 🎉 Todo el equipo de {clinica} te desea un día lleno de sonrisas. 🦷'],
        'seguimiento' => ['Seguimiento y controles', 'bi-arrow-repeat', '#4f46e5', null,
            'Hola {nombre}, te escribimos de {clinica}. Ya es momento de tu {motivo}. ¿Te agendamos una cita? Responde a este mensaje y te damos opciones de horario.'],
        'inasistencia' => ['No asistió', 'bi-person-x', '#ea580c', 'agenda',
            'Hola {nombre}, te extrañamos en tu cita del {fecha} en {clinica}. ¿Quieres que te reagendemos? Responde a este mensaje y buscamos un horario que te quede bien.'],
        'saldo' => ['Saldo vencido', 'bi-cash-stack', '#dc3545', 'ventas',
            'Hola {nombre}, te escribimos de {clinica}. Te recordamos que tienes un saldo pendiente de {saldo}. Puedes pagarlo en tu próxima visita. ¡Gracias!'],
    ];

    /** Variables que se pueden usar en las plantillas. */
    public const VARIABLES = [
        '{nombre}' => 'Nombre del paciente',
        '{paciente}' => 'Nombre y apellido',
        '{clinica}' => 'Nombre de la clínica',
        '{telefono}' => 'Teléfono de la clínica',
        '{fecha}' => 'Fecha de la cita',
        '{hora}' => 'Hora de la cita',
        '{profesional}' => 'Odontólogo de la cita',
        '{motivo}' => 'Motivo del control',
        '{saldo}' => 'Saldo pendiente',
    ];

    /** Cuántos días atrás se buscan inasistencias sin reagendar. */
    public const NO_SHOW_DAYS = 14;

    // ── Plantillas ─────────────────────────────────────────────────────────

    /** Tipos que la clínica puede usar según su plan. */
    public static function kinds(?Company $company = null): array
    {
        $company ??= app(Tenancy::class)->company();

        return array_filter(self::KINDS, fn ($k) => $k[3] === null || (bool) $company?->planAllows($k[3]));
    }

    public static function template(string $kind): string
    {
        return MessageTemplate::where('kind', $kind)->value('body') ?: self::KINDS[$kind][4];
    }

    /** @param array<string, string> $bodies tipo => texto (vacío = volver al sugerido) */
    public static function saveTemplates(array $bodies): void
    {
        foreach ($bodies as $kind => $body) {
            if (! isset(self::KINDS[$kind])) {
                continue;
            }
            $body = trim((string) $body);
            if (mb_strlen($body) > 1000) {
                throw ValidationException::withMessages(["templates.{$kind}" => 'El mensaje no puede pasar de 1000 caracteres.']);
            }
            if ($body === '' || $body === self::KINDS[$kind][4]) {
                MessageTemplate::where('kind', $kind)->delete();
            } else {
                MessageTemplate::updateOrCreate(['kind' => $kind], ['body' => $body]);
            }
        }
    }

    /** Reemplaza {variables}; las que no aplican quedan vacías. */
    public static function render(string $kind, Patient $patient, array $vars = [], ?string $body = null): string
    {
        $company = app(Tenancy::class)->company();
        $base = [
            '{nombre}' => $patient->first_name,
            '{paciente}' => $patient->full_name,
            '{clinica}' => $company?->displayName() ?? '',
            '{telefono}' => (string) ($company ? settings()->get('company.phone', null, $company->id) : ''),
        ];
        $text = strtr($body ?? self::template($kind), array_merge(array_fill_keys(array_keys(self::VARIABLES), ''), $base, $vars));

        return trim(preg_replace('/[ \t]{2,}/', ' ', $text));
    }

    public static function appointmentVars(Appointment $appointment): array
    {
        $start = $appointment->starts_at->locale('es');

        return [
            '{fecha}' => $start->translatedFormat('l j \d\e F'),
            '{hora}' => $start->format('H:i'),
            '{profesional}' => (string) $appointment->professional?->full_name,
            '{motivo}' => $appointment->title,
        ];
    }

    /** Enlace wa.me con el texto ya escrito (null si el paciente no tiene celular). */
    public static function link(Patient $patient, string $text): ?string
    {
        $base = $patient->whatsapp_link;

        return $base ? $base . '?text=' . rawurlencode($text) : null;
    }

    // ── Lista del día ──────────────────────────────────────────────────────

    /**
     * Pendientes de enviar, por tipo. Recordatorios: citas del día $remindFor
     * (por defecto mañana); lo demás, a la fecha de hoy.
     *
     * @return array<string, Collection<int, array>>
     */
    public static function queue(?Carbon $remindFor = null): array
    {
        $kinds = self::kinds();
        $queue = [];

        if (isset($kinds['recordatorio'])) {
            $day = ($remindFor ?? now()->addDay())->copy();
            $appointments = Appointment::with(['patient', 'professional', 'procedure'])->onDate($day)
                ->whereIn('status', ['agendada', 'confirmada'])->orderBy('starts_at')->get()
                ->filter(fn ($a) => $a->patient);
            $sent = self::sentBy('appointment_id', 'recordatorio', $appointments->pluck('id'));
            $queue['recordatorio'] = $appointments->map(fn (Appointment $a) => self::row('recordatorio', $a->patient,
                $a->starts_at->format('H:i') . ' · ' . $a->title . ($a->professional ? ' · ' . $a->professional->full_name : ''),
                self::appointmentVars($a), $sent->get($a->id), ['appointment_id' => $a->id], $a))->values();
        }

        $patients = Patient::where('active', true)->birthdayToday()->orderBy('first_name')->get();
        $sent = Communication::where('kind', 'cumpleanos')->whereIn('patient_id', $patients->pluck('id'))
            ->where('created_at', '>=', today())->latest('id')->get()->keyBy('patient_id');
        $queue['cumpleanos'] = $patients->map(fn (Patient $p) => self::row('cumpleanos', $p,
            'Cumple ' . ($p->birth_date ? $p->birth_date->age . ' años' : 'hoy'), [], $sent->get($p->id)))->values();

        $followups = PatientFollowup::with('patient')->pending()->whereDate('due_date', '<=', today())->orderBy('due_date')->get()
            ->filter(fn ($f) => $f->patient);
        $sent = self::sentBy('followup_id', 'seguimiento', $followups->pluck('id'));
        $queue['seguimiento'] = $followups->map(fn (PatientFollowup $f) => self::row('seguimiento', $f->patient,
            $f->reason . ' · ' . ($f->due_date->isToday() ? 'para hoy' : 'desde el ' . $f->due_date->format('d/m/Y')),
            ['{motivo}' => mb_strtolower($f->reason)], $sent->get($f->id), ['followup_id' => $f->id], null, $f))->values();

        if (isset($kinds['inasistencia'])) {
            $missed = Appointment::with(['patient', 'professional', 'procedure'])->where('status', 'no_asistio')
                ->where('starts_at', '>=', today()->subDays(self::NO_SHOW_DAYS))->where('starts_at', '<', now())
                ->latest('starts_at')->get()->filter(fn ($a) => $a->patient)->unique('patient_id');
            // Si ya tiene otra cita por delante, no hace falta escribirle.
            $booked = Appointment::whereIn('patient_id', $missed->pluck('patient_id'))->where('starts_at', '>', now())
                ->whereIn('status', ['agendada', 'confirmada'])->pluck('patient_id')->all();
            $missed = $missed->reject(fn ($a) => in_array($a->patient_id, $booked, true));
            $sent = self::sentBy('appointment_id', 'inasistencia', $missed->pluck('id'));
            $queue['inasistencia'] = $missed->map(fn (Appointment $a) => self::row('inasistencia', $a->patient,
                'Faltó el ' . $a->starts_at->format('d/m H:i') . ' · ' . $a->title,
                self::appointmentVars($a), $sent->get($a->id), ['appointment_id' => $a->id], $a))->values();
        }

        if (isset($kinds['saldo'])) {
            $debts = Sale::receivable()->with('patient')->whereNotNull('patient_id')
                ->whereNotNull('due_date')->whereDate('due_date', '<', today())->get()
                ->groupBy('patient_id');
            $sent = Communication::where('kind', 'saldo')->whereIn('patient_id', $debts->keys())
                ->where('created_at', '>=', today()->subDays(7))->latest('id')->get()->keyBy('patient_id');
            $queue['saldo'] = $debts->map(function ($sales) use ($sent) {
                $balance = round((float) $sales->sum('balance'), 2);
                $oldest = $sales->min('due_date');

                return self::row('saldo', $sales->first()->patient,
                    Money::format($balance) . ' · vencido desde el ' . Carbon::parse($oldest)->format('d/m/Y'),
                    ['{saldo}' => Money::format($balance)], $sent->get($sales->first()->patient_id));
            })->filter(fn ($row) => $row['patient'])->values();
        }

        // Orden de las pestañas = orden de KINDS.
        return array_intersect_key(array_replace(array_fill_keys(array_keys(self::KINDS), null), $queue), $queue);
    }

    /** Cuántos mensajes quedan por enviar hoy (para el dashboard). */
    public static function pendingCount(?Carbon $remindFor = null): int
    {
        return collect(self::queue($remindFor))->sum(fn ($rows) => $rows->whereNull('sent')->count());
    }

    private static function sentBy(string $column, string $kind, Collection $ids): Collection
    {
        return $ids->isEmpty() ? collect() : Communication::where('kind', $kind)->whereIn($column, $ids)
            ->with('user')->latest('id')->get()->unique($column)->keyBy($column);
    }

    private static function row(string $kind, Patient $patient, string $detail, array $vars, ?Communication $sent,
        array $refs = [], ?Appointment $appointment = null, ?PatientFollowup $followup = null): array
    {
        $message = self::render($kind, $patient, $vars);

        return [
            'key' => $kind . '-' . ($refs ? implode('-', $refs) : $patient->id),
            'kind' => $kind,
            'patient' => $patient,
            'detail' => $detail,
            'message' => $message,
            'phone' => $patient->whatsapp_link ? substr($patient->whatsapp_link, strlen('https://wa.me/')) : null,
            'refs' => $refs,
            'sent' => $sent,
            'appointment' => $appointment,
            'followup' => $followup,
        ];
    }

    // ── Envío y seguimientos ───────────────────────────────────────────────

    /** Registra que se abrió WhatsApp con el mensaje (la clínica lo envía desde su teléfono). */
    public static function log(array $input): Communication
    {
        $data = validator($input, [
            'kind' => ['required', Rule::in(array_keys(self::KINDS))],
            'patient_id' => ['required', 'integer'],
            'message' => ['required', 'string', 'max:2000'],
            'appointment_id' => ['nullable', 'integer'],
            'followup_id' => ['nullable', 'integer'],
        ], [], ['message' => 'mensaje'])->validate();

        // Las búsquedas pasan por el scope de la clínica: un id ajeno da 404.
        $patient = Patient::withTrashed()->findOrFail($data['patient_id']);
        $appointment = ! empty($data['appointment_id']) ? Appointment::where('patient_id', $patient->id)->findOrFail($data['appointment_id']) : null;
        $followup = ! empty($data['followup_id']) ? PatientFollowup::where('patient_id', $patient->id)->findOrFail($data['followup_id']) : null;

        return Communication::create([
            'patient_id' => $patient->id,
            'appointment_id' => $appointment?->id,
            'followup_id' => $followup?->id,
            'kind' => $data['kind'],
            'channel' => 'whatsapp',
            'recipient' => $patient->whatsapp_link ? substr($patient->whatsapp_link, strlen('https://wa.me/')) : null,
            'message' => trim($data['message']),
            'user_id' => auth()->id(),
        ]);
    }

    public static function schedule(Patient $patient, array $input): PatientFollowup
    {
        $data = validator($input, [
            'reason' => ['required', 'string', 'max:150'],
            'due_date' => ['nullable', 'date', 'after_or_equal:today', 'required_without:in'],
            'in' => ['nullable', Rule::in(array_values(PatientFollowup::QUICK))],
            'notes' => ['nullable', 'string', 'max:1000'],
            'consultation_id' => ['nullable', 'integer'],
        ], [
            'due_date.required_without' => 'Elige cuándo: un plazo rápido o una fecha.',
            'due_date.after_or_equal' => 'La fecha del control no puede ser pasada.',
        ], ['reason' => 'motivo', 'due_date' => 'fecha'])->validate();

        $due = ! empty($data['due_date']) ? Carbon::parse($data['due_date']) : today()->modify($data['in']);
        $consultation = ! empty($data['consultation_id'])
            ? \App\Models\Consultation::where('patient_id', $patient->id)->find($data['consultation_id'])
            : null;

        return PatientFollowup::create([
            'patient_id' => $patient->id,
            'consultation_id' => $consultation?->id,
            'due_date' => $due->toDateString(),
            'reason' => trim($data['reason']),
            'notes' => $data['notes'] ?? null,
            'status' => 'pendiente',
            'created_by' => auth()->id(),
        ]);
    }

    public static function close(PatientFollowup $followup, string $status): void
    {
        if (! in_array($status, ['hecho', 'descartado', 'pendiente'], true)) {
            throw ValidationException::withMessages(['status' => 'Estado no válido.']);
        }
        $followup->update([
            'status' => $status,
            'closed_at' => $status === 'pendiente' ? null : now(),
            'closed_by' => $status === 'pendiente' ? null : auth()->id(),
        ]);
    }
}
