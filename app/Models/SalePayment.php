<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pago de una venta: al cobrarla (`venta`) o después (`abono`). */
class SalePayment extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'sale_id', 'payment_method_id', 'cash_session_id', 'kind', 'amount', 'reference', 'user_id', 'paid_at'];

    protected $casts = ['amount' => 'decimal:2', 'paid_at' => 'datetime'];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
