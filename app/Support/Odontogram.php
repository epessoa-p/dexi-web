<?php

namespace App\Support;

use App\Models\OdontogramEntry;
use App\Models\Patient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Odontograma con numeración FDI.
 *
 * - Permanente: cuadrantes 1–4, piezas 1–8 (11–48). Temporal: 5–8, piezas 1–5 (51–85).
 * - Caras: O (oclusal / incisal), M (mesial), D (distal), V (vestibular), L (lingual / palatino).
 * - Convención clínica: **rojo** = hallazgo a tratar; **azul** = tratamiento existente o realizado.
 * - Nada se borra: un registro se «resuelve» (se trató, o fue un error) y queda en la historia.
 */
final class Odontogram
{
    public const SURFACES = ['O' => 'Oclusal / incisal', 'M' => 'Mesial', 'D' => 'Distal', 'V' => 'Vestibular', 'L' => 'Lingual / palatino'];

    /**
     * clave => [etiqueta, tipo (hallazgo|tratamiento), alcance (cara|pieza), símbolo para el dibujo].
     */
    public const CONDITIONS = [
        // Hallazgos (rojo)
        'caries' => ['Caries', 'hallazgo', 'cara', 'fill'],
        'fractura' => ['Fractura', 'hallazgo', 'cara', 'fill'],
        'restauracion_defectuosa' => ['Restauración defectuosa', 'hallazgo', 'cara', 'outline'],
        'extraccion_indicada' => ['Extracción indicada', 'hallazgo', 'pieza', 'x'],
        'endodoncia_indicada' => ['Endodoncia indicada', 'hallazgo', 'pieza', 'root'],
        'resto_radicular' => ['Resto radicular', 'hallazgo', 'pieza', 'root'],
        'movilidad' => ['Movilidad', 'hallazgo', 'pieza', 'tag'],
        // Tratamientos existentes / realizados (azul)
        'obturacion' => ['Obturación / resina', 'tratamiento', 'cara', 'fill'],
        'sellante' => ['Sellante', 'tratamiento', 'cara', 'outline'],
        'endodoncia' => ['Endodoncia', 'tratamiento', 'pieza', 'root'],
        'corona' => ['Corona', 'tratamiento', 'pieza', 'ring'],
        'implante' => ['Implante', 'tratamiento', 'pieza', 'implant'],
        'protesis' => ['Prótesis', 'tratamiento', 'pieza', 'bar'],
        'ausente' => ['Ausente', 'tratamiento', 'pieza', 'missing'],
        'erupcion' => ['En erupción / no erupcionado', 'tratamiento', 'pieza', 'tag'],
    ];

    /** Al registrar un tratamiento en una cara, resuelve el hallazgo que tenía esa cara. */
    public const TREATS = [
        'obturacion' => ['caries', 'fractura', 'restauracion_defectuosa'],
        'sellante' => ['caries'],
        'endodoncia' => ['endodoncia_indicada'],
        'corona' => ['fractura', 'restauracion_defectuosa'],
        'ausente' => ['extraccion_indicada', 'resto_radicular', 'caries', 'fractura', 'endodoncia_indicada', 'movilidad'],
        'implante' => ['extraccion_indicada', 'resto_radicular'],
    ];

    /** @return array<int, array<int, int>> filas del dibujo: [superior derecha+izquierda, inferior derecha+izquierda] */
    public static function rows(string $dentition): array
    {
        return $dentition === 'temporal'
            ? [[55, 54, 53, 52, 51, 61, 62, 63, 64, 65], [85, 84, 83, 82, 81, 71, 72, 73, 74, 75]]
            : [[18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28], [48, 47, 46, 45, 44, 43, 42, 41, 31, 32, 33, 34, 35, 36, 37, 38]];
    }

    /** @return array<int, int> */
    public static function teeth(): array
    {
        return array_merge(...self::rows('permanente'), ...self::rows('temporal'));
    }

    public static function toothName(int $tooth): string
    {
        $quadrant = intdiv($tooth, 10);
        $n = $tooth % 10;
        $temporal = $quadrant >= 5;
        $names = $temporal
            ? [1 => 'incisivo central', 2 => 'incisivo lateral', 3 => 'canino', 4 => 'primer molar', 5 => 'segundo molar']
            : [1 => 'incisivo central', 2 => 'incisivo lateral', 3 => 'canino', 4 => 'primer premolar', 5 => 'segundo premolar', 6 => 'primer molar', 7 => 'segundo molar', 8 => 'tercer molar'];
        $side = in_array($quadrant, [1, 4, 5, 8], true) ? 'derecho' : 'izquierdo';
        $arch = in_array($quadrant, [1, 2, 5, 6], true) ? 'superior' : 'inferior';

        return ucfirst(($names[$n] ?? 'pieza') . " {$arch} {$side}") . ($temporal ? ' (temporal)' : '');
    }

    /** Dentición que conviene mostrar primero según la edad. */
    public static function defaultDentition(Patient $patient): string
    {
        return $patient->age !== null && $patient->age < 6 ? 'temporal' : 'permanente';
    }

    /**
     * Registra una condición en una pieza (y caras). Devuelve los registros creados.
     *
     * @return Collection<int, OdontogramEntry>
     */
    public static function record(Patient $patient, array $input, ?int $consultationId = null, ?int $personalId = null): Collection
    {
        $data = validator($input, [
            'tooth' => ['required', 'integer', Rule::in(self::teeth())],
            'condition' => ['required', Rule::in(array_keys(self::CONDITIONS))],
            'surfaces' => ['nullable', 'array'],
            'surfaces.*' => [Rule::in(array_keys(self::SURFACES))],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['tooth' => 'pieza', 'condition' => 'condición', 'surfaces' => 'caras'])->validate();

        [, , $scope] = self::CONDITIONS[$data['condition']];
        $surfaces = array_values(array_unique($data['surfaces'] ?? []));
        if ($scope === 'cara' && $surfaces === []) {
            throw ValidationException::withMessages(['surfaces' => 'Elige en qué cara está (toca la cara en el dibujo).']);
        }
        if ($scope === 'pieza') {
            $surfaces = [null];
        }

        return DB::transaction(function () use ($patient, $data, $surfaces, $consultationId, $personalId) {
            $tooth = (int) $data['tooth'];
            $current = OdontogramEntry::where('patient_id', $patient->id)->where('tooth', $tooth)->whereNull('resolved_at')->get();

            // Un tratamiento resuelve los hallazgos que corrige (en esa cara, o en toda la pieza).
            $treats = self::TREATS[$data['condition']] ?? [];
            $current->filter(fn (OdontogramEntry $e) => in_array($e->condition, $treats, true)
                && ($surfaces === [null] || $e->surface === null || in_array($e->surface, $surfaces, true)))
                ->each(fn (OdontogramEntry $e) => $e->resolve('Tratado: ' . self::CONDITIONS[$data['condition']][0]));

            $created = collect();
            foreach ($surfaces as $surface) {
                // No duplicar la misma condición vigente en la misma cara.
                $exists = $current->first(fn (OdontogramEntry $e) => ! $e->resolved_at && $e->condition === $data['condition'] && $e->surface === $surface);
                if ($exists) {
                    continue;
                }
                $created->push(OdontogramEntry::create([
                    'patient_id' => $patient->id, 'consultation_id' => $consultationId, 'tooth' => $tooth, 'surface' => $surface,
                    'condition' => $data['condition'], 'notes' => $data['notes'] ?? null,
                    'personal_id' => $personalId, 'created_by' => auth()->id(),
                ]));
            }

            return $created;
        });
    }

    /**
     * Estado actual para dibujar: pieza => [entire => [...condiciones], surfaces => [cara => condición]].
     *
     * @return array<int, array{whole: array<int, string>, surfaces: array<string, string>, count: int}>
     */
    public static function state(Patient $patient): array
    {
        $state = [];
        OdontogramEntry::where('patient_id', $patient->id)->whereNull('resolved_at')->orderBy('id')->get()
            ->each(function (OdontogramEntry $e) use (&$state) {
                $state[$e->tooth] ??= ['whole' => [], 'surfaces' => [], 'count' => 0];
                $state[$e->tooth]['count']++;
                if ($e->surface) {
                    // En una cara manda el hallazgo (rojo) sobre el tratamiento.
                    $previous = $state[$e->tooth]['surfaces'][$e->surface] ?? null;
                    if (! $previous || self::CONDITIONS[$e->condition][1] === 'hallazgo') {
                        $state[$e->tooth]['surfaces'][$e->surface] = $e->condition;
                    }
                } else {
                    $state[$e->tooth]['whole'][] = $e->condition;
                }
            });

        return $state;
    }

    /** Resumen para la ficha: cuántos hallazgos pendientes y en cuántas piezas. */
    public static function summary(Patient $patient): array
    {
        $open = OdontogramEntry::where('patient_id', $patient->id)->whereNull('resolved_at')->get();
        // Una caries en dos caras (MO) es UN hallazgo: se cuenta por pieza y condición.
        $distinct = fn ($entries) => $entries->unique(fn ($e) => $e->tooth . '-' . $e->condition)->count();
        $findings = $open->filter(fn ($e) => self::CONDITIONS[$e->condition][1] === 'hallazgo');
        $treatments = $open->filter(fn ($e) => self::CONDITIONS[$e->condition][1] === 'tratamiento' && $e->condition !== 'ausente');

        return [
            'findings' => $distinct($findings),
            'teeth_with_findings' => $findings->pluck('tooth')->unique()->count(),
            'missing' => $open->where('condition', 'ausente')->count(),
            'treatments' => $distinct($treatments),
        ];
    }

    /** Para la UI: catálogo serializable. */
    public static function catalog(): array
    {
        return collect(self::CONDITIONS)->map(fn ($c, $key) => ['key' => $key, 'label' => $c[0], 'type' => $c[1], 'scope' => $c[2], 'symbol' => $c[3]])->values()->all();
    }
}
