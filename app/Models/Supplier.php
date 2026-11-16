<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Proveedor. */
class Supplier extends Model
{
    use HasFactory, SoftDeletes, BelongsToCompany;

    protected $fillable = [
        'company_id', 'name', 'tax_id', 'contact_name', 'phone', 'email', 'address', 'notes', 'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        return $term === '' ? $query : $query->where(fn ($q) => $q
            ->where('name', 'like', "%{$term}%")
            ->orWhere('tax_id', 'like', "%{$term}%")
            ->orWhere('contact_name', 'like', "%{$term}%"));
    }

    /** Nombres en MAYÚSCULAS (ESTRUCTURA_CUENTAS_Y_GASTOS §8). */
    protected function name(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : mb_strtoupper(trim($value)));
    }
}
