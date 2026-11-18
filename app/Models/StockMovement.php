<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Renglón del kardex: inmutable; un error se corrige con otro movimiento. */
class StockMovement extends Model
{
    use BelongsToCompany;

    /** tipo => [etiqueta, color] */
    public const TYPES = [
        'in' => ['Entrada', 'success'],
        'out' => ['Salida', 'danger'],
        'adjustment' => ['Ajuste', 'warning'],
        'transfer_in' => ['Traslado recibido', 'info'],
        'transfer_out' => ['Traslado enviado', 'secondary'],
    ];

    public const REASONS = [
        'initial' => 'Stock inicial',
        'purchase' => 'Compra',
        'return' => 'Devolución',
        'donation' => 'Muestra / donación',
        'internal_use' => 'Uso interno',
        'procedure' => 'Insumo de procedimiento',
        'loss' => 'Merma / rotura',
        'expired' => 'Vencido',
        'count' => 'Inventario físico',
        'transfer' => 'Traslado',
        'sale' => 'Venta',
        'sale_return' => 'Venta anulada',
        'reversal' => 'Anulación',
        'other' => 'Otro',
    ];

    public const MANUAL_IN_REASONS = ['initial', 'purchase', 'return', 'donation', 'other'];

    public const MANUAL_OUT_REASONS = ['internal_use', 'loss', 'expired', 'return', 'other'];

    protected $fillable = [
        'company_id', 'product_id', 'branch_id', 'product_batch_id', 'type', 'reason', 'quantity', 'balance_after',
        'unit_cost', 'source_type', 'source_id', 'notes', 'user_id',
    ];

    protected $casts = ['quantity' => 'decimal:2', 'balance_after' => 'decimal:2', 'unit_cost' => 'decimal:2'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type][0] ?? $this->type;
    }

    public function getTypeColorAttribute(): string
    {
        return self::TYPES[$this->type][1] ?? 'secondary';
    }

    public function getReasonLabelAttribute(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }
}
