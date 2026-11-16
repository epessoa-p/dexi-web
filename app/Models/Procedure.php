<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\Rule;

/**
 * Procedimiento del tarifario (limpieza, resina, endodoncia…): un solo
 * catálogo para la agenda, el odontograma, los presupuestos y las ventas.
 *
 * `applies_to` dice cómo se carga: por paciente (consulta, limpieza), por
 * pieza (endodoncia, corona), por cara (resina) o por arcada (blanqueamiento).
 */
class Procedure extends Model
{
    use BelongsToCompany, SoftDeletes;

    /** clave => [etiqueta, ayuda] */
    public const APPLIES_TO = [
        'paciente' => ['Por paciente', 'Consulta, limpieza, radiografía panorámica…'],
        'pieza' => ['Por pieza', 'Endodoncia, corona, extracción…'],
        'cara' => ['Por cara', 'Resina, sellante…'],
        'arcada' => ['Por arcada', 'Blanqueamiento, férula, prótesis total…'],
    ];

    public const DURATIONS = [15, 20, 30, 45, 60, 90, 120, 180];

    /**
     * Catálogo sugerido: especialidad => [[código, nombre, minutos, se aplica a], …].
     * Se carga sin precio: cada clínica pone los suyos.
     */
    public const SUGGESTED = [
        'Odontología general' => [
            ['GEN-01', 'Consulta y diagnóstico', 30, 'paciente'],
            ['GEN-02', 'Consulta de urgencia', 30, 'paciente'],
            ['GEN-03', 'Profilaxis (limpieza dental)', 45, 'paciente'],
            ['GEN-04', 'Destartraje (tartrectomía)', 45, 'paciente'],
            ['GEN-05', 'Aplicación de flúor', 15, 'paciente'],
            ['GEN-06', 'Control', 20, 'paciente'],
        ],
        'Operatoria y estética' => [
            ['OPE-01', 'Resina simple (1 cara)', 30, 'cara'],
            ['OPE-02', 'Resina compuesta (2 o más caras)', 45, 'pieza'],
            ['OPE-03', 'Sellante de fosas y fisuras', 15, 'pieza'],
            ['OPE-04', 'Blanqueamiento en consultorio', 90, 'arcada'],
            ['OPE-05', 'Carilla de resina', 60, 'pieza'],
            ['OPE-06', 'Carilla de porcelana', 60, 'pieza'],
        ],
        'Endodoncia' => [
            ['END-01', 'Endodoncia unirradicular', 60, 'pieza'],
            ['END-02', 'Endodoncia birradicular', 90, 'pieza'],
            ['END-03', 'Endodoncia multirradicular', 120, 'pieza'],
            ['END-04', 'Retratamiento endodóntico', 120, 'pieza'],
            ['END-05', 'Pulpotomía', 45, 'pieza'],
        ],
        'Periodoncia' => [
            ['PER-01', 'Raspado y alisado radicular (por cuadrante)', 60, 'arcada'],
            ['PER-02', 'Cirugía periodontal', 90, 'pieza'],
            ['PER-03', 'Gingivectomía', 60, 'pieza'],
        ],
        'Cirugía oral' => [
            ['CIR-01', 'Extracción simple', 30, 'pieza'],
            ['CIR-02', 'Extracción compleja', 60, 'pieza'],
            ['CIR-03', 'Extracción de tercer molar', 90, 'pieza'],
            ['CIR-04', 'Frenectomía', 45, 'paciente'],
        ],
        'Implantología' => [
            ['IMP-01', 'Implante dental', 90, 'pieza'],
            ['IMP-02', 'Corona sobre implante', 60, 'pieza'],
            ['IMP-03', 'Injerto óseo', 90, 'pieza'],
        ],
        'Prótesis y rehabilitación' => [
            ['PRO-01', 'Corona de porcelana / zirconio', 60, 'pieza'],
            ['PRO-02', 'Corona metal-porcelana', 60, 'pieza'],
            ['PRO-03', 'Perno y muñón', 45, 'pieza'],
            ['PRO-04', 'Prótesis parcial removible', 60, 'arcada'],
            ['PRO-05', 'Prótesis total', 60, 'arcada'],
            ['PRO-06', 'Férula de descarga', 45, 'arcada'],
        ],
        'Ortodoncia' => [
            ['ORT-01', 'Estudio de ortodoncia', 60, 'paciente'],
            ['ORT-02', 'Instalación de brackets', 120, 'paciente'],
            ['ORT-03', 'Control de ortodoncia', 30, 'paciente'],
            ['ORT-04', 'Retenedor', 30, 'arcada'],
        ],
        'Odontopediatría' => [
            ['PED-01', 'Consulta odontopediátrica', 30, 'paciente'],
            ['PED-02', 'Extracción de pieza temporal', 30, 'pieza'],
            ['PED-03', 'Corona de acero', 45, 'pieza'],
        ],
        'Radiología' => [
            ['RAD-01', 'Radiografía periapical', 15, 'pieza'],
            ['RAD-02', 'Radiografía panorámica', 15, 'paciente'],
        ],
    ];

    protected $fillable = [
        'company_id', 'specialty_id', 'code', 'name', 'price', 'price_max', 'duration_minutes', 'applies_to',
        'color', 'description', 'active', 'sort_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'price_max' => 'decimal:2',
        'duration_minutes' => 'integer',
        'active' => 'boolean',
        'sort_order' => 'integer',
        'deleted_at' => 'datetime',
    ];

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        return $term === '' ? $query : $query->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"));
    }

    public function getAppliesToLabelAttribute(): string
    {
        return self::APPLIES_TO[$this->applies_to][0] ?? $this->applies_to;
    }

    /** Su color, o el de su especialidad (para la agenda). */
    public function getCalendarColorAttribute(): string
    {
        return $this->color ?: ($this->specialty?->color ?: '#0e9f9a');
    }

    public function hasPrice(): bool
    {
        return (float) $this->price > 0;
    }

    public function getDurationLabelAttribute(): string
    {
        $m = $this->duration_minutes;

        return $m >= 60 ? intdiv($m, 60) . ' h' . ($m % 60 ? ' ' . ($m % 60) . ' min' : '') : "{$m} min";
    }

    /**
     * Validación del alta y la edición, la misma para la web y la API.
     *
     * @return array<string, mixed>
     */
    public static function validateForm(array $input, ?self $procedure = null): array
    {
        $companyId = app(Tenancy::class)->id();

        $data = validator($input, [
            'name' => ['required', 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:20',
                Rule::unique('procedures', 'code')->where('company_id', $companyId)->whereNull('deleted_at')->ignore($procedure?->id)],
            'specialty_id' => ['nullable', Rule::exists('specialties', 'id')->where('company_id', $companyId)],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'price_max' => ['nullable', 'numeric', 'gte:price', 'max:9999999'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:600'],
            'applies_to' => ['required', Rule::in(array_keys(self::APPLIES_TO))],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'active' => ['nullable', 'boolean'],
        ], [
            'code.unique' => 'Ya hay un procedimiento con ese código.',
            'price_max.gte' => 'El precio máximo no puede ser menor que el precio base.',
        ], [
            'name' => 'nombre', 'code' => 'código', 'price' => 'precio', 'price_max' => 'precio máximo', 'duration_minutes' => 'duración',
        ])->validate();

        $data['code'] = filled($data['code'] ?? null) ? mb_strtoupper(trim($data['code'])) : null;
        $data['price_max'] = filled($data['price_max'] ?? null) && (float) $data['price_max'] > (float) $data['price'] ? $data['price_max'] : null;
        $data['active'] = array_key_exists('active', $input) && $input['active'] !== null ? (bool) $data['active'] : ($procedure?->active ?? true);

        return $data;
    }

    /** Carga el catálogo sugerido (sin precios). Devuelve cuántos agregó; no duplica códigos. */
    public static function loadSuggested(): int
    {
        Specialty::ensureDefaults();
        $specialties = Specialty::pluck('id', 'name');
        $existing = static::withTrashed()->whereNotNull('code')->pluck('code')->all();
        $count = 0;

        foreach (self::SUGGESTED as $specialty => $items) {
            foreach ($items as $sort => [$code, $name, $minutes, $appliesTo]) {
                if (in_array($code, $existing, true)) {
                    continue;
                }
                static::create([
                    'specialty_id' => $specialties[$specialty] ?? null,
                    'code' => $code, 'name' => $name, 'price' => 0,
                    'duration_minutes' => $minutes, 'applies_to' => $appliesTo, 'sort_order' => $sort,
                ]);
                $count++;
            }
        }

        return $count;
    }
}
