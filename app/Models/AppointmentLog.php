<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Renglón del historial de una cita (sin company_id propio: cuelga de la cita). */
class AppointmentLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['appointment_id', 'action', 'from_value', 'to_value', 'note', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** «Reprogramada: lun 17/11 09:00 → mar 18/11 10:30». */
    public function getDescriptionAttribute(): string
    {
        return match ($this->action) {
            'creada' => 'Cita creada',
            'reprogramada' => "Reprogramada: {$this->from_value} → {$this->to_value}",
            'estado' => (Appointment::STATUSES[$this->from_value][0] ?? $this->from_value) . ' → ' . (Appointment::STATUSES[$this->to_value][0] ?? $this->to_value),
            'profesional' => "Cambio de profesional: {$this->from_value} → {$this->to_value}",
            default => $this->action,
        } . ($this->note ? " · {$this->note}" : '');
    }
}
