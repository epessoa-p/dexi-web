<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Model;

/** Método de pago. Solo el tipo `cash` cuenta para el arqueo. */
class PaymentMethod extends Model
{
    use BelongsToCompany;

    public const TYPES = [
        'cash' => 'Efectivo',
        'transfer' => 'Transferencia bancaria',
        'qr' => 'QR',
        'card' => 'Tarjeta',
        'other' => 'Otro',
    ];

    protected $fillable = ['company_id', 'name', 'type', 'requires_reference', 'active', 'sort'];

    protected $casts = [
        'requires_reference' => 'boolean',
        'active' => 'boolean',
        'sort' => 'integer',
    ];

    /** Métodos con los que empieza cada clínica: nombre => [tipo, pide referencia]. */
    public const DEFAULTS = [
        'Efectivo' => ['cash', false],
        'QR' => ['qr', true],
        'Tarjeta' => ['card', true],
        'Transferencia' => ['transfer', true],
    ];

    /** Crea los métodos por defecto la primera vez (una sola consulta cuando ya existen). */
    public static function ensureDefaults(?int $companyId = null): void
    {
        $companyId ??= app(Tenancy::class)->id();
        if (! $companyId || static::forCompany($companyId)->exists()) {
            return;
        }

        $sort = 0;
        foreach (self::DEFAULTS as $name => [$type, $reference]) {
            static::forCompany($companyId)->create([
                'company_id' => $companyId, 'name' => $name, 'type' => $type,
                'requires_reference' => $reference, 'active' => true, 'sort' => $sort++,
            ]);
        }
    }

    public function isCash(): bool
    {
        return $this->type === 'cash';
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
