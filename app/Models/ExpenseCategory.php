<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

/**
 * En qué se gasta. Su tipo decide en qué pestaña de Gastos aparece y a
 * quién se le paga:
 *
 *   general    → payee ninguno, no recurrente   (Gastos)
 *   basico     → recurrente, mensual o anual     (Servicios básicos)
 *   proveedor  → payee proveedor                 (Proveedores)
 *   personal   → payee personal                  (Personal)
 *
 * El grupo no se solapa: `recurring` manda, luego `payee`.
 */
class ExpenseCategory extends Model
{
    use BelongsToCompany;

    /** Los cuatro tipos que ve el usuario. */
    public const TYPES = [
        'general' => 'Gasto general',
        'basico' => 'Servicio básico',
        'proveedor' => 'Pago a proveedor',
        'personal' => 'Pago a personal',
    ];

    /** Título de cada grupo en las listas. */
    public const GROUP_TITLES = [
        'general' => 'Gastos generales',
        'basico' => 'Servicios básicos',
        'proveedor' => 'Pagos a proveedores',
        'personal' => 'Pagos a personal',
    ];

    public const FREQUENCIES = ['mensual' => 'Mensual', 'anual' => 'Anual'];

    /** Categorías con las que empieza cada clínica: nombre => tipo (y periodicidad). */
    public const DEFAULTS = [
        'PAGO A PROVEEDORES' => ['proveedor'],
        'PAGO A PERSONAL' => ['personal'],
        'ALQUILER' => ['basico', 'mensual'],
        'LUZ' => ['basico', 'mensual'],
        'AGUA' => ['basico', 'mensual'],
        'INTERNET Y TELÉFONO' => ['basico', 'mensual'],
        'COMIDA' => ['general'],
        'PASAJES Y TRANSPORTE' => ['general'],
        'PEDIDOS Y ENVÍOS' => ['general'],
        'LIMPIEZA Y MANTENIMIENTO' => ['general'],
        'OTROS' => ['general'],
    ];

    /** Categoría de sistema de las liquidaciones de comisiones: no se elige a mano. */
    public const COMMISSIONS = 'COMISIONES';

    /** La categoría que se usa sola al pagar a un proveedor o al personal. */
    public const DEFAULT_NAMES = ['proveedor' => 'PAGO A PROVEEDORES', 'personal' => 'PAGO A PERSONAL'];

    protected $fillable = ['company_id', 'name', 'payee', 'recurring', 'frequency', 'active', 'sort_order'];

    protected $casts = [
        'active' => 'boolean',
        'recurring' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** general, basico, proveedor o personal. */
    public function getGroupAttribute(): string
    {
        return match (true) {
            $this->recurring => 'basico',
            $this->payee === 'proveedor' => 'proveedor',
            $this->payee === 'personal' => 'personal',
            default => 'general',
        };
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->group];
    }

    /** «Luz» o «Hosting (anual)» para los selectores de servicios. */
    public function getServiceLabelAttribute(): string
    {
        return $this->recurring && $this->frequency === 'anual' ? "{$this->name} (anual)" : $this->name;
    }

    /** Las que se eligen al cargar un gasto a mano (sin «Comisiones», que solo usa la liquidación). */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('active', true)->where('name', '!=', self::COMMISSIONS);
    }

    public function scopeGroup(Builder $query, string $group): Builder
    {
        return match ($group) {
            'basico' => $query->where('recurring', true),
            'proveedor', 'personal' => $query->where('recurring', false)->where('payee', $group),
            default => $query->where('recurring', false)->where('payee', 'ninguno'),
        };
    }

    /** Atributos de base de datos para un tipo de la interfaz. */
    public static function attributesFor(string $type, ?string $frequency = null): array
    {
        return [
            'payee' => in_array($type, ['proveedor', 'personal'], true) ? $type : 'ninguno',
            'recurring' => $type === 'basico',
            'frequency' => $type === 'basico' ? ($frequency ?: 'mensual') : null,
        ];
    }

    /**
     * Las categorías por defecto, la primera vez que la clínica entra a
     * Gastos (o al crearse). Una sola consulta cuando ya existen.
     */
    public static function ensureDefaults(?int $companyId = null): void
    {
        $companyId ??= app(Tenancy::class)->id();
        if (! $companyId || static::forCompany($companyId)->where('payee', 'proveedor')->exists()) {
            return;
        }

        $sort = 0;
        foreach (self::DEFAULTS as $name => $definition) {
            [$type, $frequency] = $definition + [1 => null];
            $category = static::forCompany($companyId)->firstOrNew(['name' => $name]);
            // Las que ya existían conservan el tipo que les dio la clínica, salvo que sean generales.
            if (! $category->exists || $category->group === 'general') {
                $category->fill(self::attributesFor($type, $frequency) + ['sort_order' => $sort]);
            }
            $category->company_id = $companyId;
            $category->active ??= true;
            $category->save();
            $sort++;
        }
    }

    /**
     * La categoría de un pago a proveedor o al personal: la que se llama como
     * la de fábrica; si la renombraron, la primera del grupo; si no hay, se crea.
     */
    public static function defaultFor(string $group): self
    {
        $name = self::DEFAULT_NAMES[$group];

        return static::group($group)->where('active', true)->where('name', $name)->first()
            ?? static::group($group)->where('active', true)->where('name', '!=', self::COMMISSIONS)->orderBy('sort_order')->orderBy('name')->first()
            ?? static::create(['name' => static::where('name', $name)->exists() ? "{$name} " . now()->year : $name] + self::attributesFor($group));
    }

    /**
     * Validación del alta y la edición, la misma para la web y la API.
     *
     * @return array<string, mixed> atributos listos para guardar
     */
    public static function validateForm(array $input, ?self $category = null): array
    {
        $validated = validator($input, [
            'name' => ['required', 'string', 'max:80',
                Rule::unique('expense_categories', 'name')->where('company_id', app(Tenancy::class)->id())->ignore($category?->id)],
            'type' => ['required', Rule::in(array_keys(self::TYPES))],
            'frequency' => ['nullable', Rule::in(array_keys(self::FREQUENCIES))],
            'active' => ['nullable', 'boolean'],
        ], ['name.unique' => 'Esa categoría ya existe.'], ['name' => 'nombre', 'type' => 'tipo', 'frequency' => 'periodicidad'])->validate();

        return ['name' => trim($validated['name'])]
            + self::attributesFor($validated['type'], $validated['frequency'] ?? null)
            + ['active' => $category && array_key_exists('active', $validated) ? (bool) $validated['active'] : ($category?->active ?? true)];
    }

    /** Nombres en MAYÚSCULAS (ESTRUCTURA_CUENTAS_Y_GASTOS §8). */
    protected function name(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : mb_strtoupper(trim($value)));
    }
}
