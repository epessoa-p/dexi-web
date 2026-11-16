<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Gasto de la clínica, G-000001. Sale de una cuenta de la
 * tesorería o de un turno de caja (App\Support\PaymentSource). No se edita:
 * se anula (el dinero vuelve a donde salió) y se registra de nuevo.
 */
class Expense extends Model
{
    use SoftDeletes, BelongsToCompany;

    public const SOURCES = ['account' => 'Cuenta', 'cash' => 'Caja', 'external' => 'Pagado por fuera'];

    protected $fillable = [
        'company_id', 'branch_id', 'number', 'expense_category_id', 'supplier_id', 'personal_id', 'commission_settlement_id', 'concept', 'amount', 'spent_at',
        'reference', 'source', 'finance_account_id', 'finance_movement_id', 'payment_method_id', 'cash_session_id', 'cash_movement_id',
        'user_id', 'receipt', 'notes', 'status', 'cancelled_at', 'cancelled_by', 'cancel_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'spent_at' => 'date',
        'cancelled_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function personal(): BelongsTo
    {
        return $this->belongsTo(Personal::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'finance_account_id')->withTrashed();
    }

    public function financeMovement(): BelongsTo
    {
        return $this->belongsTo(FinanceMovement::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function cashMovement(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Los que cuentan: sin los anulados. */
    public function scopeValid(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), '!=', 'anulado');
    }

    public function isCancelled(): bool
    {
        return $this->status === 'anulado';
    }

    public function getCodeAttribute(): string
    {
        return 'G-' . str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    /** A quién se le pagó: el proveedor o la persona. */
    public function getPayeeNameAttribute(): ?string
    {
        return $this->supplier?->name ?? $this->personal?->full_name;
    }

    /** «Banco Unión», «Caja principal · turno 14» o «Pagado por fuera». */
    public function getSourceLabelAttribute(): string
    {
        return match ($this->source) {
            'account' => $this->account?->name ?? 'Cuenta',
            'cash' => trim(($this->cashSession?->caja?->name ?? 'Caja') . ($this->cash_session_id ? " · turno {$this->cash_session_id}" : '')),
            default => self::SOURCES['external'],
        };
    }

    public function getReceiptUrlAttribute(): ?string
    {
        // Disco privado: se sirve por una ruta con permiso, nunca desde public/.
        return $this->receipt ? route('expenses.receipt', $this) : null;
    }
}
