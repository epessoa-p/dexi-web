<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\ConsultationDiagnosis;
use App\Models\ConsultationProcedure;
use App\Models\Patient;
use App\Models\Personal;
use App\Models\Procedure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * La consulta odontológica, en un solo lugar (web y API):
 *
 * - Se abre desde una cita (la pasa a «en atención») o desde la ficha.
 * - Abierta: se editan notas, diagnósticos y procedimientos realizados.
 * - Un procedimiento hecho en una pieza puede marcarse solo en el odontograma
 *   (resina → obturación, endodoncia → endodoncia, extracción → ausente…).
 * - Cerrada: queda firmada; solo admite notas de corrección. La cita pasa a «atendida».
 */
final class Consultations
{
    /** Qué deja en el odontograma cada procedimiento sugerido (por código). */
    public const ODONTOGRAM_BY_CODE = [
        'OPE-01' => 'obturacion', 'OPE-02' => 'obturacion', 'OPE-03' => 'sellante',
        'END-01' => 'endodoncia', 'END-02' => 'endodoncia', 'END-03' => 'endodoncia', 'END-04' => 'endodoncia',
        'CIR-01' => 'ausente', 'CIR-02' => 'ausente', 'CIR-03' => 'ausente', 'PED-02' => 'ausente',
        'IMP-01' => 'implante', 'PRO-01' => 'corona', 'PRO-02' => 'corona', 'PED-03' => 'corona', 'IMP-02' => 'corona',
    ];

    /** Abre una consulta (o devuelve la que ya está abierta para esa cita). */
    public static function start(Patient $patient, ?Appointment $appointment = null, ?int $personalId = null): Consultation
    {
        if ($appointment) {
            $existing = Consultation::where('appointment_id', $appointment->id)->first();
            if ($existing) {
                return $existing;
            }
        }

        $personalId ??= $appointment?->personal_id ?? self::myProfessionalId();
        if (! $personalId || ! Personal::where('attends_patients', true)->whereKey($personalId)->exists()) {
            throw ValidationException::withMessages(['personal_id' => 'Elige el profesional que atiende.']);
        }

        return DB::transaction(function () use ($patient, $appointment, $personalId) {
            $consultation = Consultation::create([
                'patient_id' => $patient->id,
                'appointment_id' => $appointment?->id,
                'personal_id' => $personalId,
                'branch_id' => $appointment?->branch_id ?? $patient->branch_id,
                'number' => (int) Consultation::lockForUpdate()->max('number') + 1,
                'status' => 'abierta',
                'reason' => $appointment?->reason ?: $appointment?->procedure?->name,
                'started_at' => now(),
                'created_by' => auth()->id(),
            ]);

            if ($appointment) {
                self::advance($appointment, 'en_atencion');
            }

            return $consultation;
        });
    }

    /** El profesional que corresponde al usuario conectado (si tiene ficha y atiende). */
    public static function myProfessionalId(): ?int
    {
        return auth()->id() ? Personal::where('user_id', auth()->id())->where('attends_patients', true)->value('id') : null;
    }

    public static function saveNotes(Consultation $consultation, array $input): Consultation
    {
        self::ensureOpen($consultation);
        $data = validator($input, [
            'reason' => ['nullable', 'string', 'max:255'],
            'history' => ['nullable', 'string', 'max:5000'],
            'exam' => ['nullable', 'string', 'max:5000'],
            'plan' => ['nullable', 'string', 'max:5000'],
            'indications' => ['nullable', 'string', 'max:5000'],
            'next_visit' => ['nullable', 'string', 'max:255'],
        ])->validate();
        $consultation->update($data);

        return $consultation;
    }

    public static function addDiagnosis(Consultation $consultation, array $input): ConsultationDiagnosis
    {
        self::ensureOpen($consultation);
        $data = validator($input, [
            'code' => ['required', Rule::in(array_keys(ConsultationDiagnosis::CATALOG))],
            'label' => ['nullable', 'required_if:code,otro', 'string', 'max:150'],
            'tooth' => ['nullable', 'integer', Rule::in(Odontogram::teeth())],
            'surfaces' => ['nullable', 'array'],
            'surfaces.*' => [Rule::in(array_keys(Odontogram::SURFACES))],
            'notes' => ['nullable', 'string', 'max:255'],
        ], ['label.required_if' => 'Escribe el diagnóstico.'], ['code' => 'diagnóstico', 'tooth' => 'pieza'])->validate();

        return $consultation->diagnoses()->create([
            'code' => $data['code'],
            'label' => $data['code'] === 'otro' ? trim($data['label']) : ConsultationDiagnosis::CATALOG[$data['code']][0],
            'tooth' => $data['tooth'] ?? null,
            'surfaces' => self::surfaces($data['surfaces'] ?? []),
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /** Procedimiento realizado; si se pide, lo marca en el odontograma de la pieza. */
    public static function addProcedure(Consultation $consultation, array $input): ConsultationProcedure
    {
        self::ensureOpen($consultation);
        $companyId = app(Tenancy::class)->id();
        $data = validator($input, [
            'procedure_id' => ['required', Rule::exists('procedures', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'tooth' => ['nullable', 'integer', Rule::in(Odontogram::teeth())],
            'surfaces' => ['nullable', 'array'],
            'surfaces.*' => [Rule::in(array_keys(Odontogram::SURFACES))],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'notes' => ['nullable', 'string', 'max:255'],
            'mark' => ['nullable', 'string', Rule::in(array_keys(Odontogram::CONDITIONS))],
        ], [], ['procedure_id' => 'procedimiento', 'tooth' => 'pieza', 'quantity' => 'cantidad', 'price' => 'precio'])->validate();

        $procedure = Procedure::findOrFail($data['procedure_id']);
        if (in_array($procedure->applies_to, ['pieza', 'cara'], true) && empty($data['tooth'])) {
            throw ValidationException::withMessages(['tooth' => "«{$procedure->name}» se hace en una pieza: elige cuál."]);
        }
        if ($procedure->applies_to === 'cara' && empty($data['surfaces'])) {
            throw ValidationException::withMessages(['surfaces' => 'Marca en qué cara(s).']);
        }

        return DB::transaction(function () use ($consultation, $procedure, $data) {
            $item = $consultation->procedures()->create([
                'procedure_id' => $procedure->id,
                'name' => $procedure->name,
                'tooth' => $data['tooth'] ?? null,
                'surfaces' => self::surfaces($data['surfaces'] ?? []),
                'quantity' => $data['quantity'] ?? 1,
                'price' => $data['price'] ?? $procedure->price,
                'notes' => $data['notes'] ?? null,
            ]);

            if (! empty($data['mark']) && ! empty($data['tooth'])) {
                Odontogram::record($consultation->patient, [
                    'tooth' => $data['tooth'], 'condition' => $data['mark'], 'surfaces' => $data['surfaces'] ?? [],
                    'notes' => "{$consultation->code} · {$procedure->name}",
                ], $consultation->id, $consultation->personal_id);
            }

            return $item;
        });
    }

    public static function close(Consultation $consultation): Consultation
    {
        self::ensureOpen($consultation);
        if (blank($consultation->reason) && blank($consultation->history) && blank($consultation->exam)
            && $consultation->diagnoses()->doesntExist() && $consultation->procedures()->doesntExist()) {
            throw ValidationException::withMessages(['close' => 'La consulta está vacía: anota al menos el motivo, un diagnóstico o lo que se hizo.']);
        }

        return DB::transaction(function () use ($consultation) {
            $consultation->update(['status' => 'cerrada', 'closed_at' => now(), 'closed_by' => auth()->id()]);
            if ($consultation->appointment) {
                self::advance($consultation->appointment, 'atendida');
            }

            return $consultation;
        });
    }

    /** Nota de corrección de una consulta cerrada (se agrega, nunca se reescribe). */
    public static function addendum(Consultation $consultation, string $note): Consultation
    {
        if ($consultation->isOpen()) {
            throw ValidationException::withMessages(['addendum' => 'La consulta sigue abierta: edítala directamente.']);
        }
        $note = trim($note);
        if ($note === '') {
            throw ValidationException::withMessages(['addendum' => 'Escribe la nota.']);
        }
        $line = now()->format('d/m/Y H:i') . ' · ' . (auth()->user()?->name ?? 'sistema') . ': ' . $note;
        $consultation->update(['addendum' => trim(($consultation->addendum ? $consultation->addendum . "\n" : '') . $line)]);

        return $consultation;
    }

    /** Lleva la cita hasta un estado por el camino normal (llegó → en atención → atendida). */
    private static function advance(Appointment $appointment, string $target): void
    {
        $path = ['agendada' => 0, 'confirmada' => 0, 'en_espera' => 1, 'en_atencion' => 2, 'atendida' => 3];
        if (! isset($path[$appointment->status]) || $path[$appointment->status] >= $path[$target]) {
            return;
        }
        foreach (['en_espera', 'en_atencion', 'atendida'] as $step) {
            if ($path[$step] > $path[$appointment->status] && $path[$step] <= $path[$target]) {
                Agenda::setStatus($appointment, $step);
            }
        }
    }

    public static function ensureOpen(Consultation $consultation): void
    {
        if (! $consultation->isOpen()) {
            throw ValidationException::withMessages(['close' => 'La consulta está cerrada: solo se le pueden agregar notas de corrección.']);
        }
    }

    /** ['O', 'M'] → «MO» en orden clínico (M, O, D, V, L). */
    public static function surfaces(array $surfaces): ?string
    {
        $order = ['M', 'O', 'D', 'V', 'L'];
        $sorted = array_values(array_intersect($order, array_unique($surfaces)));

        return $sorted ? implode('', $sorted) : null;
    }
}
