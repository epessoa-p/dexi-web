<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Línea de una venta: un procedimiento realizado, un ítem de plan o algo suelto del tarifario. */
class SaleItem extends Model
{
    protected $fillable = [
        'sale_id', 'procedure_id', 'product_id', 'consultation_procedure_id', 'treatment_plan_item_id', 'personal_id',
        'description', 'tooth', 'quantity', 'unit_price', 'discount', 'total',
    ];

    protected $casts = [
        'tooth' => 'integer', 'quantity' => 'decimal:2', 'unit_price' => 'decimal:2', 'discount' => 'decimal:2', 'total' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class)->withTrashed();
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id')->withTrashed();
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function consultationProcedure(): BelongsTo
    {
        return $this->belongsTo(ConsultationProcedure::class);
    }

    public function planItem(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlanItem::class, 'treatment_plan_item_id');
    }

    /** «Consulta C-000004» o «Plan PT-000002». */
    public function getOriginAttribute(): ?string
    {
        if ($this->consultation_procedure_id) {
            return 'Consulta ' . ($this->consultationProcedure?->consultation?->code ?? '');
        }
        if ($this->treatment_plan_item_id) {
            return 'Plan ' . ($this->planItem?->plan?->code ?? '');
        }

        return null;
    }
}
