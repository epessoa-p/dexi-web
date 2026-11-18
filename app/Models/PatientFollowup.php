<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Control o seguimiento programado: «escribirle a Juan el 15/05 por su control de 6 meses». */
class PatientFollowup extends Model
{
    use BelongsToCompany;

    /** estado => [etiqueta, color] */
    public const STATUSES = [
        'pendiente' => ['Pendiente', 'warning'],
        'hecho' => ['Hecho', 'success'],
        'descartado' => ['Descartado', 'secondary'],
    ];

    /** Plazos rápidos al programar: etiqueta => modificador de fecha. */
    public const QUICK = ['1 semana' => '+1 week', '1 mes' => '+1 month', '3 meses' => '+3 months', '6 meses' => '+6 months', '1 año' => '+1 year'];

    public const REASONS = ['Control de 6 meses', 'Limpieza / profilaxis', 'Retiro de puntos', 'Control post-endodoncia', 'Control de ortodoncia', 'Revisar presupuesto'];

    protected $fillable = ['company_id', 'patient_id', 'consultation_id', 'due_date', 'reason', 'notes', 'status', 'closed_at', 'closed_by', 'created_by'];

    protected $casts = ['due_date' => 'date', 'closed_at' => 'datetime'];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function communications(): HasMany
    {
        return $this->hasMany(Communication::class, 'followup_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), 'pendiente');
    }

    public function isPending(): bool
    {
        return $this->status === 'pendiente';
    }

    public function isOverdue(): bool
    {
        return $this->isPending() && $this->due_date->lt(today());
    }
}
