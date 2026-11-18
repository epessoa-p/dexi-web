<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Compra a un proveedor (P-000001). Se recibe en una o varias entregas (entra
 * al stock) y se paga en una o varias cuotas (cuenta por pagar).
 */
class Purchase extends Model
{
    use BelongsToCompany;

    /** estado => [etiqueta, color, ícono] */
    public const STATUSES = [
        'pedida' => ['Por recibir', 'warning', 'bi-truck'],
        'parcial' => ['Recibida en parte', 'info', 'bi-box-seam'],
        'recibida' => ['Recibida', 'success', 'bi-check2-all'],
        'anulada' => ['Anulada', 'dark', 'bi-x-circle'],
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'supplier_id', 'number', 'document_number', 'purchased_at', 'status', 'subtotal', 'discount',
        'total', 'paid', 'balance', 'due_date', 'notes', 'user_id', 'received_at', 'closing_notes', 'cancelled_at', 'cancel_reason',
    ];

    protected $casts = [
        'purchased_at' => 'date', 'due_date' => 'date', 'received_at' => 'datetime', 'cancelled_at' => 'datetime',
        'subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'total' => 'decimal:2', 'paid' => 'decimal:2', 'balance' => 'decimal:2',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function receptions(): HasMany
    {
        return $this->hasMany(PurchaseReception::class)->latest('received_at');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchasePayment::class)->latest('id');
    }

    public function getCodeAttribute(): string
    {
        return 'P-' . str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function getStatusColorAttribute(): string
    {
        return self::STATUSES[$this->status][1] ?? 'secondary';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'anulada';
    }

    /** Falta mercadería por recibir. */
    public function isPending(): bool
    {
        return in_array($this->status, ['pedida', 'parcial'], true);
    }

    public function isOverdue(): bool
    {
        return (float) $this->balance > 0 && $this->due_date !== null && $this->due_date->lt(today());
    }

    /** Con saldo por pagar (cuentas por pagar). */
    public function scopePayable(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), '!=', 'anulada')->where('balance', '>', 0);
    }
}
