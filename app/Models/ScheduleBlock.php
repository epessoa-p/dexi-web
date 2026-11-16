<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bloqueo de la agenda: vacaciones, congreso o feriado (sin profesional = toda la clínica). */
class ScheduleBlock extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'personal_id', 'starts_at', 'ends_at', 'reason', 'created_by'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime'];

    public function personal(): BelongsTo
    {
        return $this->belongsTo(Personal::class)->withTrashed();
    }
}
