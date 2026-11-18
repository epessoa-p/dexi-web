<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Regla de comisión: cuánto gana un odontólogo (o todo el equipo) por lo que
 * atiende. Gana la más específica (App\Support\Commissions::ruleFor). Editarla
 * o borrarla no cambia lo ya generado.
 */
class CommissionRule extends Model
{
    use BelongsToCompany;

    public const APPLIES_TO = [
        'todos' => 'Todos los procedimientos',
        'especialidad' => 'Una especialidad',
        'procedimiento' => 'Un procedimiento',
    ];

    public const TYPES = ['porcentaje' => 'Porcentaje', 'monto_fijo' => 'Monto fijo'];

    protected $fillable = ['company_id', 'personal_id', 'applies_to', 'specialty_id', 'procedure_id', 'type', 'value', 'active', 'notes'];

    protected $casts = ['value' => 'decimal:2', 'active' => 'boolean'];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id')->withTrashed();
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    /**
     * 1 = la más específica … 6 = la más general:
     * profesional + procedimiento › profesional + especialidad › profesional + todo
     * › equipo + procedimiento › equipo + especialidad › equipo + todo.
     */
    public function getPriorityAttribute(): int
    {
        $what = ['procedimiento' => 1, 'especialidad' => 2, 'todos' => 3][$this->applies_to] ?? 3;

        return ($this->personal_id ? 0 : 3) + $what;
    }

    public function getWhoLabelAttribute(): string
    {
        return $this->personal_id ? ($this->professional?->full_name ?? 'Profesional eliminado') : 'Todo el equipo';
    }

    public function getWhatLabelAttribute(): string
    {
        return match ($this->applies_to) {
            'procedimiento' => $this->procedure?->name ?? 'Procedimiento eliminado',
            'especialidad' => 'Especialidad: ' . ($this->specialty?->name ?? 'eliminada'),
            default => self::APPLIES_TO['todos'],
        };
    }

    /** «40 %» o «Bs 150.00 por procedimiento». */
    public function getRateLabelAttribute(): string
    {
        return Commission::rate($this->type, (float) $this->value);
    }
}
