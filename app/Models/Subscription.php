<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Vincula UNA clínica a UN plan. Relación 1:1 con la clínica.
 *
 * Modelo GLOBAL: NO lleva BelongsToCompany. Si se filtrara por company_id,
 * la clínica se auto-filtraría antes de poder comprobar su propio acceso y el
 * superadmin no podría listarlas. Ver ARQUITECTURA §2.
 *
 * El estado efectivo se DERIVA de las fechas, no solo de la columna `status`:
 * una suscripción vencida corta el acceso sola, sin cron. Ver ARQUITECTURA §7.
 */
class Subscription extends Model
{
    use HasFactory;

    public const STATUSES = [
        'trial'     => 'En prueba',
        'active'    => 'Activa',
        'past_due'  => 'Pago pendiente',
        'suspended' => 'Suspendida',
        'cancelled' => 'Cancelada',
    ];

    protected $fillable = [
        'company_id',
        'plan_id',
        'status',
        'starts_at',
        'trial_ends_at',
        'current_period_end',
        'grace_days',
        'price',
        'currency',
        'auto_renew',
        'cancelled_at',
        'notes',
        'created_by',
        'max_users_override',
        'max_personal_override',
        'max_branches_override',
        'features_override',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'trial_ends_at' => 'datetime',
        'current_period_end' => 'datetime',
        'cancelled_at' => 'datetime',
        'grace_days' => 'integer',
        'price' => 'decimal:2',
        'auto_renew' => 'boolean',
        'max_users_override' => 'integer',
        'max_personal_override' => 'integer',
        'max_branches_override' => 'integer',
        'features_override' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Overrides por clínica (ARQUITECTURA §8) ──────────────────────────────

    /**
     * Límite real de esta clínica: el override si existe, si no el del plan.
     * NULL = ilimitado.
     *
     * @param  string  $key  'users' | 'personal' | 'branches'
     */
    public function effectiveLimitFor(string $key): ?int
    {
        if (! array_key_exists($key, Plan::LIMITS)) {
            return null;
        }

        $override = $this->{'max_' . $key . '_override'};

        if ($override !== null) {
            return (int) $override;
        }

        return $this->plan?->limitFor($key);
    }

    /**
     * Módulos reales de esta clínica: el override REEMPLAZA por completo a los
     * del plan si no es null.
     *
     * @return array<int, string>
     */
    public function effectiveFeatures(): array
    {
        return $this->features_override ?? ($this->plan?->features ?? []);
    }

    public function hasOverrides(): bool
    {
        return $this->max_users_override !== null
            || $this->max_personal_override !== null
            || $this->max_branches_override !== null
            || $this->features_override !== null;
    }

    // ── Estado derivado de fechas (ARQUITECTURA §7.2) ────────────────────────

    public function onTrial(): bool
    {
        return $this->status === 'trial'
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }

    /**
     * Periodo pagado vigente. Un plan vitalicio se guarda como status=active
     * sin current_period_end (no vence nunca).
     */
    public function isCurrent(): bool
    {
        return $this->status === 'active'
            && ($this->current_period_end === null || $this->current_period_end->isFuture());
    }

    /** Cortada a mano por el operador. */
    public function isBlocked(): bool
    {
        return in_array($this->status, ['suspended', 'cancelled'], true);
    }

    /**
     * Fecha en que termina lo que la clínica tiene hoy: la prueba o el periodo
     * pagado. null = sin vencimiento.
     */
    public function periodEnd(): ?Carbon
    {
        return $this->status === 'trial' ? $this->trial_ends_at : $this->current_period_end;
    }

    public function graceEndsAt(): ?Carbon
    {
        return $this->periodEnd()?->copy()->addDays($this->grace_days ?? 0);
    }

    /** Vencida pero dentro del periodo de gracia: entra, pero solo lee. */
    public function inGrace(): bool
    {
        if ($this->isBlocked() || $this->onTrial() || $this->isCurrent()) {
            return false;
        }

        return $this->graceEndsAt()?->isFuture() ?? false;
    }

    /** Uso normal: leer y escribir. */
    public function allowsWrite(): bool
    {
        return ! $this->isBlocked() && ($this->onTrial() || $this->isCurrent());
    }

    /** Al menos entrar y consultar. */
    public function allowsRead(): bool
    {
        return $this->allowsWrite() || $this->inGrace();
    }

    /**
     * Días que faltan para el fin de la prueba o del periodo. null = sin vencimiento.
     */
    public function daysRemaining(): ?int
    {
        $end = $this->periodEnd();

        if ($end === null) {
            return null;
        }

        return (int) max(0, today()->diffInDays($end->copy()->startOfDay(), false));
    }

    /** Etiqueta del estado efectivo para la interfaz. */
    public function statusLabel(): string
    {
        return match (true) {
            $this->isBlocked() => self::STATUSES[$this->status],
            $this->onTrial() => 'En prueba',
            $this->isCurrent() => 'Al día',
            $this->inGrace() => 'Solo lectura',
            default => 'Vencida',
        };
    }

    /** Color Bootstrap del estado efectivo. */
    public function statusColor(): string
    {
        return match (true) {
            $this->status === 'cancelled' => 'secondary',
            $this->isBlocked() => 'danger',
            $this->onTrial() => 'info',
            $this->isCurrent() => 'success',
            $this->inGrace() => 'warning',
            default => 'danger',
        };
    }

    // ── Consultas para los paneles del operador ──────────────────────────────

    /** Suscripciones que hoy permiten operar con normalidad (equivalente SQL de allowsWrite). */
    public function scopeWritable(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where(fn (Builder $t) => $t->where('status', 'trial')->where('trial_ends_at', '>', now()))
              ->orWhere(fn (Builder $a) => $a->where('status', 'active')
                  ->where(fn (Builder $e) => $e->whereNull('current_period_end')->orWhere('current_period_end', '>', now())));
        });
    }

    /** No cortadas a mano pero ya vencidas (incluye las que están en gracia). */
    public function scopeLapsed(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['suspended', 'cancelled'])
            ->whereNot(fn (Builder $q) => $q->writable());
    }

    /** Vigentes cuya prueba o periodo termina dentro de $days días. */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        $limit = now()->addDays($days);

        return $query->where(function (Builder $q) use ($limit) {
            $q->where(fn (Builder $t) => $t->where('status', 'trial')->whereBetween('trial_ends_at', [now(), $limit]))
              ->orWhere(fn (Builder $a) => $a->where('status', 'active')->whereBetween('current_period_end', [now(), $limit]));
        });
    }

    /** Orden por fecha de vencimiento efectiva (las sin vencimiento al final). */
    public function scopeOrderByPeriodEnd(Builder $query): Builder
    {
        return $query->orderByRaw("CASE WHEN status = 'trial' THEN trial_ends_at ELSE current_period_end END IS NULL")
                     ->orderByRaw("CASE WHEN status = 'trial' THEN trial_ends_at ELSE current_period_end END");
    }

    // ── Acciones ─────────────────────────────────────────────────────────────

    /**
     * Pasa a `active` y extiende el periodo pagado N periodos del plan.
     * Si aún está vigente suma desde el vencimiento; si no, desde hoy.
     */
    public function renew(int $periods = 1): void
    {
        $months = $this->plan?->periodMonths();

        if ($months === null) {
            $this->forceFill(['status' => 'active', 'current_period_end' => null, 'cancelled_at' => null])->save();

            return;
        }

        $end = $this->periodEnd();
        $base = $end && $end->isFuture() ? $end->copy() : now()->endOfDay();

        $this->forceFill([
            'status' => 'active',
            'current_period_end' => $base->addMonthsNoOverflow($months * max(1, $periods)),
            'cancelled_at' => null,
        ])->save();
    }
}
