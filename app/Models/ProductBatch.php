<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lote con vencimiento, por consultorio. Las salidas consumen primero el que vence antes. */
class ProductBatch extends Model
{
    use BelongsToCompany;

    /** Días antes del vencimiento en que se avisa. */
    public const EXPIRY_WARNING_DAYS = 60;

    protected $fillable = ['company_id', 'product_id', 'branch_id', 'lot', 'expires_at', 'quantity', 'initial_quantity'];

    protected $casts = ['expires_at' => 'date', 'quantity' => 'decimal:2', 'initial_quantity' => 'decimal:2'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt(today());
    }

    public function isExpiringSoon(): bool
    {
        return $this->expires_at !== null && ! $this->isExpired() && $this->expires_at->lte(today()->addDays(self::EXPIRY_WARNING_DAYS));
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('quantity', '>', 0);
    }

    /** Con existencia y vencidos o por vencer. */
    public function scopeExpiring(Builder $query): Builder
    {
        return $query->available()->whereNotNull('expires_at')->where('expires_at', '<=', today()->addDays(self::EXPIRY_WARNING_DAYS));
    }
}
