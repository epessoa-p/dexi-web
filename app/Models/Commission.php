<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comisión ganada por una línea cobrada (o un ajuste negativo al anular).
 * Guarda su base, tipo y valor: nunca se recalcula.
 */
class Commission extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'sale_id', 'sale_item_id', 'sale_payment_id', 'personal_id', 'commission_rule_id', 'commission_settlement_id',
        'kind', 'adjusts_commission_id', 'description', 'base', 'type', 'value', 'amount', 'earned_at',
    ];

    protected $casts = [
        'base' => 'decimal:2',
        'value' => 'decimal:2',
        'amount' => 'decimal:2',
        'earned_at' => 'datetime',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class, 'sale_item_id');
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id')->withTrashed();
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(CommissionSettlement::class, 'commission_settlement_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('commission_settlement_id'));
    }

    public function isAdjustment(): bool
    {
        return $this->kind === 'ajuste';
    }

    public static function rate(string $type, float $value): string
    {
        return $type === 'porcentaje'
            ? rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . ' %'
            : Money::format($value) . ' por procedimiento';
    }

    public function getRateLabelAttribute(): string
    {
        return self::rate($this->type, (float) $this->value);
    }
}
