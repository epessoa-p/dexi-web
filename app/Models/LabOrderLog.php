<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Renglón del recorrido de una orden de laboratorio (cuelga de la orden). */
class LabOrderLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['lab_order_id', 'from_status', 'to_status', 'note', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getDescriptionAttribute(): string
    {
        $to = LabOrder::STATUSES[$this->to_status][0] ?? $this->to_status;
        $label = match (true) {
            $this->from_status === null => 'Orden enviada al laboratorio',
            $this->from_status === 'recibido' && $this->to_status === 'en_proceso' => 'Devuelto al laboratorio para ajuste',
            default => $to,
        };

        return $label . ($this->note ? " · {$this->note}" : '');
    }
}
