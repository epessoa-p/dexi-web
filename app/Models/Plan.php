<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Producto comercial del operador: qué módulos incluye y qué límites impone.
 *
 * Modelo GLOBAL: pertenece al operador de la plataforma, no a una clínica.
 * Nunca lleva BelongsToCompany. Ver ARQUITECTURA §2 y §6.
 */
class Plan extends Model
{
    use HasFactory, SoftDeletes;

    public const PERIODS = [
        'monthly' => 'Mensual',
        'quarterly' => 'Trimestral',
        'yearly' => 'Anual',
        'lifetime' => 'Vitalicio',
    ];

    /**
     * Catálogo de módulos de Dexi. Un plan incluye una lista de estos slugs en
     * su columna `features` (y una clínica puede tener otra lista en
     * subscriptions.features_override).
     */
    public const MODULES = [
        'agenda'           => 'Agenda y citas',
        'historia_clinica' => 'Historia clínica y odontograma',
        'tratamientos'     => 'Planes de tratamiento y presupuestos',
        'ventas'           => 'Ventas, cobros y cuentas por cobrar',
        'caja'             => 'Caja (turnos, arqueo y cierre)',
        'finanzas'         => 'Finanzas: cuentas y gastos',
        'inventario'       => 'Insumos e inventario',
        'compras'          => 'Compras y cuentas por pagar',
        'comisiones'       => 'Comisiones del personal',
        'laboratorio'      => 'Trabajos de laboratorio dental',
        'recordatorios'    => 'Recordatorios y comunicaciones',
        'estadisticas'     => 'Reportes y estadísticas',
    ];

    /**
     * Mapa permissions.module → feature de plan que lo habilita.
     *
     * Un módulo que NO figura aquí es ADMINISTRATIVO y está SIEMPRE disponible,
     * independientemente del plan: companies, plans, subscriptions, users,
     * roles, cargos, personal, branches y settings. Los pacientes también
     * serán administrativos: son el dato maestro de todos los demás módulos.
     *
     * Al construir el dominio dental se añadirán aquí sus módulos, p. ej.
     *   'appointments' => 'agenda',     'clinical_records' => 'historia_clinica',
     *   'odontograms'  => 'historia_clinica', 'treatment_plans' => 'tratamientos',
     *   'supplies'     => 'inventario', 'lab_orders'       => 'laboratorio',
     *
     * El valor puede ser un array: el módulo se habilita si el plan incluye
     * ALGUNA de esas features (OR). Ver ARQUITECTURA §6.2.
     */
    public const PERMISSION_MODULE_FEATURES = [
        'cajas' => 'caja',
        'appointments' => 'agenda',
        'schedules' => 'agenda',
        'consultations' => 'historia_clinica',
        'odontogram' => 'historia_clinica',
        'cash' => 'caja',
        'payment_methods' => ['caja', 'ventas'],
        'accounts' => 'finanzas',
        'account_movements' => 'finanzas',
        // Un gasto sale de una cuenta (finanzas) o de la caja: basta con uno de los dos.
        'expenses' => ['caja', 'finanzas'],
        'expense_categories' => ['caja', 'finanzas'],
        'suppliers' => ['finanzas', 'compras', 'inventario'],
    ];

    /**
     * Recursos limitables por plan: clave => etiqueta (columna max_<clave>).
     */
    public const LIMITS = [
        'users' => 'Usuarios',
        'personal' => 'Personal',
        'branches' => 'Consultorios',
    ];

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'currency',
        'billing_period',
        'trial_days',
        'max_users',
        'max_personal',
        'max_branches',
        'features',
        'active',
        'sort_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'trial_days' => 'integer',
        'max_users' => 'integer',
        'max_personal' => 'integer',
        'max_branches' => 'integer',
        'features' => 'array',
        'active' => 'boolean',
        'sort_order' => 'integer',
        'deleted_at' => 'datetime',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Features de plan que habilitan un módulo de permisos.
     * Devuelve [] si el módulo es administrativo (siempre disponible).
     *
     * @return array<int, string>
     */
    public static function featuresForPermissionModule(string $module): array
    {
        $feature = static::PERMISSION_MODULE_FEATURES[$module] ?? null;

        if ($feature === null) {
            return [];
        }

        return is_array($feature) ? $feature : [$feature];
    }

    public static function moduleLabel(string $module): string
    {
        return static::MODULES[$module] ?? $module;
    }

    public function periodLabel(): string
    {
        return self::PERIODS[$this->billing_period] ?? $this->billing_period;
    }

    /**
     * Meses que cubre un período de facturación. null = vitalicio.
     */
    public function periodMonths(): ?int
    {
        return match ($this->billing_period) {
            'monthly' => 1,
            'quarterly' => 3,
            'yearly' => 12,
            default => null,
        };
    }

    public function allows(string $feature): bool
    {
        return in_array($feature, $this->features ?? [], true);
    }

    /**
     * Límite del plan para un recurso. NULL = ilimitado.
     *
     * @param  string  $key  'users' | 'personal' | 'branches'
     */
    public function limitFor(string $key): ?int
    {
        return array_key_exists($key, self::LIMITS) ? $this->{'max_' . $key} : null;
    }

    public function isFree(): bool
    {
        return (float) $this->price === 0.0;
    }
}
