<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ítem de un plan de tratamiento: un procedimiento en una pieza (y caras), en una fase. */
class TreatmentPlanItem extends Model
{
    public const STATUSES = ['pendiente' => ['Pendiente', 'secondary'], 'realizado' => ['Realizado', 'success'], 'cancelado' => ['Cancelado', 'dark']];

    protected $fillable = [
        'treatment_plan_id', 'procedure_id', 'name', 'tooth', 'surfaces', 'phase', 'quantity', 'unit_price', 'status',
        'done_at', 'consultation_id', 'consultation_procedure_id', 'sale_item_id', 'notes', 'sort',
    ];

    protected $casts = [
        'tooth' => 'integer', 'phase' => 'integer', 'quantity' => 'integer', 'unit_price' => 'decimal:2', 'done_at' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlan::class, 'treatment_plan_id');
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class)->withTrashed();
    }

    /** Lo realizado en la consulta que completó este ítem. */
    public function consultationProcedure(): BelongsTo
    {
        return $this->belongsTo(ConsultationProcedure::class);
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function getSubtotalAttribute(): float
    {
        return round((float) $this->unit_price * $this->quantity, 2);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function getStatusColorAttribute(): string
    {
        return self::STATUSES[$this->status][1] ?? 'secondary';
    }

    /** «Pieza 16 · MO» o null. */
    public function getLocationAttribute(): ?string
    {
        return $this->tooth ? "Pieza {$this->tooth}" . ($this->surfaces ? " · {$this->surfaces}" : '') : null;
    }
}
