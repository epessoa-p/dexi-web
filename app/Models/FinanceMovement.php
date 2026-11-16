<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Un renglón del extracto de una cuenta. Nunca se borra: al anularlo se
 * marca y se escribe el movimiento contrario (origen «anulacion»).
 */
class FinanceMovement extends Model
{
    use BelongsToCompany;

    public const ORIGINS = [
        'manual' => 'Manual',
        'apertura' => 'Saldo inicial',
        'transferencia' => 'Transferencia',
        'gasto' => 'Gasto',
        'compra' => 'Compra',
        'anulacion' => 'Anulación',
    ];

    /** Los que se anulan desde la cuenta; un gasto o una compra, desde su ficha. */
    public const CANCELLABLE_ORIGINS = ['manual', 'transferencia'];

    protected $fillable = [
        'company_id', 'finance_account_id', 'type', 'origin', 'concept', 'amount', 'balance_after', 'occurred_on', 'reference',
        'transfer_movement_id', 'reverses_movement_id', 'source_type', 'source_id', 'created_by', 'cancelled_at', 'cancelled_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'occurred_on' => 'date',
        'cancelled_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'finance_account_id')->withTrashed();
    }

    public function transferPair(): BelongsTo
    {
        return $this->belongsTo(self::class, 'transfer_movement_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isIncome(): bool
    {
        return $this->type === 'ingreso';
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function isCancellable(): bool
    {
        return ! $this->isCancelled() && in_array($this->origin, self::CANCELLABLE_ORIGINS, true);
    }

    public function getOriginLabelAttribute(): string
    {
        return self::ORIGINS[$this->origin] ?? $this->origin;
    }

    /** Con signo: + entra, − sale. */
    public function getSignedAmountAttribute(): float
    {
        return $this->isIncome() ? (float) $this->amount : -(float) $this->amount;
    }
}
