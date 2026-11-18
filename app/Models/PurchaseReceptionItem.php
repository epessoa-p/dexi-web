<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReceptionItem extends Model
{
    protected $fillable = ['purchase_reception_id', 'purchase_item_id', 'quantity', 'lot', 'expires_at'];

    protected $casts = ['quantity' => 'decimal:2', 'expires_at' => 'date'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class, 'purchase_item_id');
    }
}
