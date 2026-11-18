<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Venta / cobro (V-000001). Lo no pagado queda como saldo (cuenta por cobrar)
 * y se completa con abonos. No se edita: se anula y lo cobrado se devuelve.
 */
class Sale extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'branch_id', 'patient_id', 'cash_session_id', 'number', 'status', 'subtotal', 'discount', 'total',
        'paid', 'balance', 'due_date', 'notes', 'user_id', 'personal_id', 'cancelled_at', 'cancelled_by', 'cancel_reason',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'total' => 'decimal:2', 'paid' => 'decimal:2', 'balance' => 'decimal:2',
        'due_date' => 'date', 'cancelled_at' => 'datetime',
    ];

    /** Quién atendió lo suelto de la venta (las líneas de consultas y planes traen el suyo). */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id')->withTrashed();
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class)->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function scopeValid(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), 'completada');
    }

    /** Con saldo pendiente (cuentas por cobrar). */
    public function scopeReceivable(Builder $query): Builder
    {
        return $query->valid()->where('balance', '>', 0);
    }

    public function isCancelled(): bool
    {
        return $this->status === 'anulada';
    }

    public function getCodeAttribute(): string
    {
        return 'V-' . str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    /** Vencido: con saldo y fecha de pago pasada. */
    public function isOverdue(): bool
    {
        return (float) $this->balance > 0 && $this->due_date && $this->due_date->lt(today());
    }
}
