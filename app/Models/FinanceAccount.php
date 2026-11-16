<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cuenta de la tesorería (capital, caja chica, banco, billetera…). Su saldo
 * solo lo cambia App\Support\Treasury, junto con el historial.
 */
class FinanceAccount extends Model
{
    use SoftDeletes, BelongsToCompany;

    public const TYPES = [
        'banco' => 'Banco',
        'caja_chica' => 'Caja chica',
        'billetera' => 'Billetera / QR',
        'capital' => 'Capital',
        'otro' => 'Otra',
    ];

    /** Ícono de Bootstrap Icons según el tipo. */
    public const ICONS = [
        'banco' => 'bi-bank',
        'caja_chica' => 'bi-piggy-bank',
        'billetera' => 'bi-phone',
        'capital' => 'bi-safe2',
        'otro' => 'bi-wallet2',
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'name', 'type', 'bank_name', 'account_number', 'opening_balance', 'balance', 'notes', 'active',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'balance' => 'decimal:2',
        'active' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(FinanceMovement::class);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function getIconAttribute(): string
    {
        return self::ICONS[$this->type] ?? 'bi-wallet2';
    }

    /** Nombres en MAYÚSCULAS (ESTRUCTURA_CUENTAS_Y_GASTOS §8). */
    protected function name(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : mb_strtoupper(trim($value)));
    }
}
