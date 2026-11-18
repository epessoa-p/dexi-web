<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Insumo que gasta un procedimiento del tarifario (p. ej. resina: 1 cartucho de anestesia, 1 par de guantes). */
class ProcedureSupply extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'procedure_id', 'product_id', 'quantity'];

    protected $casts = ['quantity' => 'decimal:2'];

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class)->withTrashed();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
