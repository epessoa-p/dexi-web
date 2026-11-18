<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Liquidación L-000001: el pago a un odontólogo de sus comisiones pendientes
 * de un período. Se paga como un gasto de Finanzas (categoría COMISIONES).
 */
class CommissionSettlement extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'personal_id', 'number', 'period_start', 'period_end', 'commissions_count', 'total', 'status', 'expense_id',
        'paid_at', 'paid_by', 'notes', 'cancelled_at', 'cancelled_by', 'cancel_reason',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total' => 'decimal:2',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id')->withTrashed();
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    public function isCancelled(): bool
    {
        return $this->status === 'anulada';
    }

    public function getCodeAttribute(): string
    {
        return 'L-' . str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }
}
