<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Una entrega del proveedor: lo que llegó entra al stock. */
class PurchaseReception extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'purchase_id', 'received_at', 'notes', 'user_id'];

    protected $casts = ['received_at' => 'datetime'];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReceptionItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
