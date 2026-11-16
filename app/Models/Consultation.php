<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Consulta (atención odontológica), C-000001. Abierta se edita; al cerrarla
 * queda firmada por el profesional y solo admite notas de corrección (addendum).
 */
class Consultation extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'branch_id', 'patient_id', 'appointment_id', 'personal_id', 'number', 'status',
        'reason', 'history', 'exam', 'plan', 'indications', 'next_visit', 'addendum',
        'started_at', 'closed_at', 'closed_by', 'created_by',
    ];

    protected $casts = ['started_at' => 'datetime', 'closed_at' => 'datetime'];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id')->withTrashed();
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(ConsultationDiagnosis::class)->orderBy('id');
    }

    public function procedures(): HasMany
    {
        return $this->hasMany(ConsultationProcedure::class)->orderBy('id');
    }

    public function odontogramEntries(): HasMany
    {
        return $this->hasMany(OdontogramEntry::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'abierta';
    }

    public function getCodeAttribute(): string
    {
        return 'C-' . str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    /** Total de lo realizado (para cobrar cuando llegue Ventas). */
    public function getTotalAttribute(): float
    {
        return round((float) $this->procedures->sum(fn (ConsultationProcedure $p) => $p->price * $p->quantity), 2);
    }
}
