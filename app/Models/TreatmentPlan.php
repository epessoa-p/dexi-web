<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plan de tratamiento / presupuesto (PT-000001).
 *   borrador → presentado → aprobado (en curso) → terminado · rechazado · cancelado
 * Antes de aprobarse se editan los ítems libremente; después solo se agregan
 * ítems o se cancelan (el paciente aceptó lo anterior).
 */
class TreatmentPlan extends Model
{
    use BelongsToCompany;

    /** estado => [etiqueta, color, ícono] */
    public const STATUSES = [
        'borrador' => ['Borrador', 'secondary', 'bi-pencil'],
        'presentado' => ['Por aprobar', 'warning', 'bi-hourglass-split'],
        'aprobado' => ['En curso', 'primary', 'bi-play-circle'],
        'terminado' => ['Terminado', 'success', 'bi-check2-all'],
        'rechazado' => ['Rechazado', 'danger', 'bi-hand-thumbs-down'],
        'cancelado' => ['Cancelado', 'dark', 'bi-x-circle'],
    ];

    protected $fillable = [
        'company_id', 'patient_id', 'personal_id', 'consultation_id', 'number', 'title', 'status', 'discount', 'valid_until', 'notes',
        'presented_at', 'approved_at', 'approved_by_name', 'completed_at', 'closed_at', 'close_reason', 'created_by',
    ];

    protected $casts = [
        'discount' => 'decimal:2',
        'valid_until' => 'date',
        'presented_at' => 'datetime',
        'approved_at' => 'datetime',
        'completed_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id')->withTrashed();
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TreatmentPlanItem::class)->orderBy('phase')->orderBy('sort')->orderBy('id');
    }

    /** Los que siguen vivos (se pueden presentar, aprobar o avanzar). */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['borrador', 'presentado', 'aprobado']);
    }

    public function getCodeAttribute(): string
    {
        return 'PT-' . str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function getStatusColorAttribute(): string
    {
        return self::STATUSES[$this->status][1] ?? 'secondary';
    }

    public function getStatusIconAttribute(): string
    {
        return self::STATUSES[$this->status][2] ?? 'bi-circle';
    }

    /** Se editan los ítems (agregar, quitar, cambiar precio). */
    public function isEditable(): bool
    {
        return in_array($this->status, ['borrador', 'presentado'], true);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['terminado', 'rechazado', 'cancelado'], true);
    }

    /** @return array{subtotal: float, discount: float, total: float, done: float, pending: float, progress: int, items: int, items_done: int} */
    public function totals(): array
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();
        $active = $items->where('status', '!=', 'cancelado');
        $subtotal = round((float) $active->sum(fn (TreatmentPlanItem $i) => $i->subtotal), 2);
        $discount = min(round((float) $this->discount, 2), $subtotal);
        $done = round((float) $active->where('status', 'realizado')->sum(fn (TreatmentPlanItem $i) => $i->subtotal), 2);
        $count = $active->count();
        $countDone = $active->where('status', 'realizado')->count();

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => round($subtotal - $discount, 2),
            'done' => $done,
            'pending' => round($subtotal - $done, 2),
            'progress' => $count ? (int) round($countDone / $count * 100) : 0,
            'items' => $count,
            'items_done' => $countDone,
        ];
    }
}
