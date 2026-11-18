<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Procedimiento realizado en una consulta, con su precio congelado al momento. */
class ConsultationProcedure extends Model
{
    protected $fillable = ['consultation_id', 'procedure_id', 'name', 'tooth', 'surfaces', 'quantity', 'price', 'notes', 'sale_item_id'];

    protected $casts = ['tooth' => 'integer', 'quantity' => 'integer', 'price' => 'decimal:2'];

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class)->withTrashed();
    }

    public function getSubtotalAttribute(): float
    {
        return round((float) $this->price * $this->quantity, 2);
    }
}
