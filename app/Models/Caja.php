<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Caja (el cajón del mostrador) de una clínica. Se trabaja por turnos:
 * apertura → movimientos → cierre con arqueo (App\Support\Cash). Módulo `caja`.
 */
class Caja extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_id',
        'name',
        'code',
        'description',
        'balance',
        'active',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'active' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CashSession::class)->latest('opened_at');
    }

    /** Turno abierto de esta caja, si lo hay. */
    public function openSession(): ?CashSession
    {
        return $this->sessions()->where('status', 'open')->first();
    }
}
