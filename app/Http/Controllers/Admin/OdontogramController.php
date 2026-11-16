<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\OdontogramEntry;
use App\Models\Patient;
use App\Support\Consultations;
use App\Support\Odontogram;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Odontograma del paciente (JSON para el dibujo interactivo). */
class OdontogramController extends Controller
{
    public function state(Patient $patient): JsonResponse
    {
        return response()->json(self::payload($patient));
    }

    /** Registra una condición. Dentro de una consulta abierta queda ligada a ella. */
    public function store(Patient $patient): JsonResponse
    {
        $consultationId = request('consultation_id');
        $consultation = null;
        if ($consultationId) {
            $consultation = Consultation::where('patient_id', $patient->id)->find($consultationId);
            if (! $consultation || ! $consultation->isOpen()) {
                throw ValidationException::withMessages(['consultation_id' => 'Esa consulta no está abierta.']);
            }
        }

        $created = Odontogram::record($patient, request()->all(), $consultation?->id, $consultation?->personal_id ?? Consultations::myProfessionalId());

        return response()->json(self::payload($patient) + [
            'message' => $created->isEmpty() ? 'Ya estaba registrado.' : "Pieza {$created->first()->tooth}: {$created->first()->label}" . ($created->count() > 1 ? " ({$created->count()} caras)" : '') . '.',
        ], $created->isEmpty() ? 200 : 201);
    }

    /** Quitar un registro (corrección o ya no está): queda en la historia como resuelto. */
    public function resolve(Patient $patient, OdontogramEntry $entry): JsonResponse
    {
        abort_unless($entry->patient_id === $patient->id, 404);
        $reason = request()->validate(['reason' => ['nullable', 'string', 'max:120']])['reason'] ?? null;
        if (! $entry->resolved_at) {
            $entry->resolve($reason ?: 'Quitado del odontograma');
        }

        return response()->json(self::payload($patient) + ['message' => 'Registro quitado (queda en la historia).']);
    }

    /** Detalle de una pieza: lo vigente y su historia. */
    public function tooth(Patient $patient, int $tooth): JsonResponse
    {
        abort_unless(in_array($tooth, Odontogram::teeth(), true), 404);
        $entries = OdontogramEntry::with(['consultation', 'professional'])->where('patient_id', $patient->id)->where('tooth', $tooth)->latest('id')->get();
        $map = fn (OdontogramEntry $e) => [
            'id' => $e->id, 'label' => $e->label, 'type' => $e->type, 'surface' => $e->surface, 'surface_label' => $e->surface_label,
            'notes' => $e->notes, 'date' => $e->created_at?->format('d/m/Y'), 'professional' => $e->professional?->full_name,
            'consultation' => $e->consultation?->code, 'resolved' => $e->resolved_at?->format('d/m/Y'), 'resolution' => $e->resolution,
        ];

        return response()->json([
            'tooth' => $tooth,
            'name' => Odontogram::toothName($tooth),
            'current' => $entries->whereNull('resolved_at')->map($map)->values(),
            'history' => $entries->whereNotNull('resolved_at')->map($map)->values(),
        ]);
    }

    /** @return array<string, mixed> */
    public static function payload(Patient $patient): array
    {
        return ['state' => (object) Odontogram::state($patient), 'summary' => Odontogram::summary($patient)];
    }
}
