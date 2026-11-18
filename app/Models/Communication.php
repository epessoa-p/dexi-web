<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Communications;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mensaje enviado (abierto en WhatsApp) a un paciente. */
class Communication extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'patient_id', 'appointment_id', 'followup_id', 'kind', 'channel', 'recipient', 'message', 'user_id'];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getKindLabelAttribute(): string
    {
        return Communications::KINDS[$this->kind][0] ?? ucfirst($this->kind);
    }
}
