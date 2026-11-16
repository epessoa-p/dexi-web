<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Odontogram;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un registro del odontograma: condición en una pieza (y cara). Se resuelve, no se borra. */
class OdontogramEntry extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'patient_id', 'consultation_id', 'tooth', 'surface', 'condition', 'notes',
        'personal_id', 'created_by', 'resolved_at', 'resolution', 'resolved_by',
    ];

    protected $casts = ['tooth' => 'integer', 'resolved_at' => 'datetime'];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function resolve(string $reason): void
    {
        $this->update(['resolved_at' => now(), 'resolution' => $reason, 'resolved_by' => auth()->id()]);
    }

    public function getLabelAttribute(): string
    {
        return Odontogram::CONDITIONS[$this->condition][0] ?? $this->condition;
    }

    public function getTypeAttribute(): string
    {
        return Odontogram::CONDITIONS[$this->condition][1] ?? 'hallazgo';
    }

    public function getSurfaceLabelAttribute(): ?string
    {
        return $this->surface ? (Odontogram::SURFACES[$this->surface] ?? $this->surface) : null;
    }
}
