<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Línea de una compra (sin company_id propio: cuelga de la compra). */
class PurchaseItem extends Model
{
    protected $fillable = ['purchase_id', 'product_id', 'quantity', 'received_quantity', 'unit_cost', 'discount', 'total'];

    protected $casts = ['quantity' => 'decimal:2', 'received_quantity' => 'decimal:2', 'unit_cost' => 'decimal:2', 'discount' => 'decimal:2', 'total' => 'decimal:2'];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function pendingQuantity(): float
    {
        return max(round((float) $this->quantity - (float) $this->received_quantity, 2), 0);
    }

    /** Costo unitario real: con el descuento de la línea y el global de la compra repartidos. */
    public function netUnitCost(): float
    {
        if ((float) $this->quantity <= 0) {
            return 0;
        }
        $purchase = $this->purchase;
        $factor = $purchase && (float) $purchase->subtotal > 0 ? (float) $purchase->total / (float) $purchase->subtotal : 1;

        return round((float) $this->total * $factor / (float) $this->quantity, 2);
    }
}
