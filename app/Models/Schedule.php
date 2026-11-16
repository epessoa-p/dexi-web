<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bloque del horario semanal de un profesional (p. ej. lunes 08:00–12:00). */
class Schedule extends Model
{
    use BelongsToCompany;

    /** ISO: 1 = lunes … 7 = domingo. */
    public const WEEKDAYS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    protected $fillable = ['company_id', 'personal_id', 'branch_id', 'weekday', 'starts_at', 'ends_at'];

    protected $casts = ['weekday' => 'integer'];

    public function personal(): BelongsTo
    {
        return $this->belongsTo(Personal::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** «08:00» (sin segundos). */
    public function getStartLabelAttribute(): string
    {
        return substr((string) $this->starts_at, 0, 5);
    }

    public function getEndLabelAttribute(): string
    {
        return substr((string) $this->ends_at, 0, 5);
    }
}
