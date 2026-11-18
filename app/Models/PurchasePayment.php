<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pago (cuota) de una compra. Sale de una cuenta o de la caja con el mismo
 * mecanismo que un gasto (App\Support\PaymentSource); anularlo devuelve el dinero.
 */
class PurchasePayment extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'purchase_id', 'branch_id', 'source', 'finance_account_id', 'finance_movement_id', 'cash_session_id', 'cash_movement_id',
        'payment_method_id', 'amount', 'reference', 'paid_at', 'status', 'user_id', 'cancelled_at', 'cancel_reason',
    ];

    protected $casts = ['amount' => 'decimal:2', 'paid_at' => 'date', 'cancelled_at' => 'datetime'];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'finance_account_id')->withTrashed();
    }

    public function financeMovement(): BelongsTo
    {
        return $this->belongsTo(FinanceMovement::class);
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCancelled(): bool
    {
        return $this->status === 'anulado';
    }

    public function getSourceLabelAttribute(): string
    {
        return match ($this->source) {
            'account' => $this->account?->name ?? 'Cuenta',
            'cash' => 'Caja' . ($this->cashSession?->caja ? ' · ' . $this->cashSession->caja->name : ''),
            default => 'Pagado por fuera',
        };
    }
}
